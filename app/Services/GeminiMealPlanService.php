<?php

namespace App\Services;

use App\Models\Alimento;
use App\Models\MealPlan;
use App\Models\MealPlanDraft;
use App\Models\Meta_diaria;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GeminiMealPlanService
{
    public function __construct(private readonly MealCompositionService $composition) {}

    public function preview(User $user, array $preferences): MealPlanDraft
    {
        $preferences['objective'] = $user->objetivo;
        $meta = $this->meta($user);
        $target = $this->macros($meta->meta_calorias, $meta->meta_proteinas, $meta->meta_carboidratos, $meta->meta_gorduras);
        $definitions = $this->composition->definitions($preferences, $target);
        $payload = $this->validatedGeneration($target, $preferences, $this->candidates($preferences), $definitions, null);
        return $this->draft($user, $preferences, $payload);
    }

    public function clonePlan(User $user, MealPlan $plan): MealPlanDraft
    {
        abort_unless($plan->user_id === $user->id, 404);
        $plan->load('meals.items.food.planTags');
        $preferences = [...$plan->preferences, 'objective' => $user->objetivo];
        $payload = [
            'preferences' => $preferences, 'target' => $plan->target, 'totals' => $plan->totals,
            'within_target' => ! $plan->warning, 'warning' => $plan->warning, 'summary' => 'Cópia editável de '.$plan->titulo,
            'meals' => $plan->meals->map(fn ($meal) => [
                'position' => $meal->position, 'descricao' => $meal->descricao, 'horario' => substr((string) $meal->horario, 0, 5),
                'target' => $meal->target, 'totals' => $meal->totals, 'explanation' => 'Plano copiado para edição.',
                'items' => $meal->items->map(fn ($item) => [
                    'food_id' => $item->food_id, 'descricao' => $item->descricao_snapshot, 'role' => $item->culinary_role ?: (collect($this->composition->rolesForFood($item->food))->first() ?? 'acompanhamento'),
                    'quantity' => (float) $item->quantity, 'macros' => $item->macros,
                ])->values()->all(),
            ])->values()->all(),
        ];
        return $this->draft($user, $preferences, $payload);
    }

    /** Reorganiza somente uma refeição e preserva a versão anterior para desfazer. */
    public function replaceMeal(User $user, MealPlanDraft $draft, int $position, ?string $instruction): MealPlanDraft
    {
        $this->assertDraft($user, $draft);
        $definitions = $this->composition->definitions($draft->preferences, $draft->payload['target']);
        $definition = $definitions->firstWhere('position', $position);
        if (! $definition) throw ValidationException::withMessages(['position' => 'Refeição inválida.']);
        $existing = collect($draft->payload['meals'])->firstWhere('position', $position);
        $preferences = [...$draft->preferences, 'avoid_food_ids' => collect($existing['items'] ?? [])->pluck('food_id')->values()->all()];
        $replacement = $this->validatedGeneration($definition['target'], $preferences, $this->candidates($preferences, 3), collect([$definition]), $instruction ?: 'Reorganize esta refeição inteira com uma combinação culinária natural.');
        $payload = $draft->payload;
        $payload['summary'] = $replacement['summary'];
        $payload['meals'] = collect($payload['meals'])->map(fn ($meal) => $meal['position'] === $position ? $replacement['meals'][0] : $meal)->values()->all();
        $payload['totals'] = $this->sum(collect($payload['meals'])->pluck('totals')->all());
        if (! $this->withinTarget($payload['target'], $payload['totals'])) throw ValidationException::withMessages(['plan' => 'A reorganização não manteve a meta diária. Tente novamente.']);
        $draft->update(['previous_payload' => $draft->payload, 'payload' => $payload, 'expires_at' => now()->addMinutes(config('gemini.draft_ttl_minutes'))]);
        return $draft->fresh();
    }

    /** @return list<array<string, mixed>> */
    public function itemSuggestions(User $user, MealPlanDraft $draft, int $position, int $foodId): array
    {
        $this->assertDraft($user, $draft);
        $meal = collect($draft->payload['meals'])->firstWhere('position', $position);
        $item = collect($meal['items'] ?? [])->firstWhere('food_id', $foodId);
        if (! $meal || ! $item) throw ValidationException::withMessages(['food_id' => 'Este alimento não pertence à refeição.']);
        $role = $item['role'] ?? null;
        if (! $role) throw ValidationException::withMessages(['food_id' => 'Este alimento não possui papel culinário para troca.']);
        $foods = $this->candidates($draft->preferences)->filter(fn (Alimento $food) => $food->id !== $foodId && $food->planTags->contains('slug', $role))->take(40)->values();
        if ($foods->count() < 2) throw ValidationException::withMessages(['food_id' => 'Não há substituições compatíveis no catálogo.']);
        $answer = $this->ask('substituicao', [
            'alimento_atual' => $item, 'papel_culinario' => $role, 'refeicao_atual' => $meal,
            'candidatos' => $this->foodData($foods), 'regras' => ['Sugira entre 2 e 5 substituições para o mesmo papel culinário.', 'Mantenha a refeição coerente e as calorias/macros próximas.', 'Use somente IDs candidatos.'],
        ], $this->suggestionSchema());
        $foodById = $foods->keyBy('id');
        $suggestions = collect($answer['suggestions'] ?? [])->map(function ($suggestion) use ($foodById, $meal, $foodId, $item) {
            $food = $foodById->get((int) ($suggestion['food_id'] ?? 0));
            $quantity = (float) ($suggestion['quantity_g'] ?? 0);
            if (! $food || $quantity < 1 || $quantity > 800) return null;
            $replacement = ['food_id' => $food->id, 'descricao' => $food->descricao, 'role' => $item['role'], 'quantity' => round($quantity, 1), 'macros' => $this->foodMacros($food, $quantity)];
            $items = collect($meal['items'])->map(fn ($mealItem) => $mealItem['food_id'] === $foodId ? $replacement : $mealItem)->all();
            $totals = $this->sum(collect($items)->pluck('macros')->all());
            return ['food_id' => $food->id, 'descricao' => $food->descricao, 'quantity' => $replacement['quantity'], 'role' => $item['role'], 'macros' => $replacement['macros'], 'reason' => Str::limit(strip_tags((string) ($suggestion['reason'] ?? 'Alternativa compatível para esta refeição.')), 160, ''), 'meal_totals' => $totals, 'delta' => $this->delta($meal['totals'], $totals), 'within_target' => $this->withinTarget($meal['target'], $totals)];
        })->filter(fn ($suggestion) => $suggestion && $suggestion['within_target'])->unique('food_id')->take(5)->values();
        if ($suggestions->isEmpty()) throw ValidationException::withMessages(['food_id' => 'A IA não encontrou uma troca que preserve a meta desta refeição.']);
        return $suggestions->all();
    }

    public function applyItemReplacement(User $user, MealPlanDraft $draft, int $position, int $foodId, int $replacementFoodId, float $quantity): MealPlanDraft
    {
        $this->assertDraft($user, $draft);
        $payload = $draft->payload;
        $mealIndex = collect($payload['meals'])->search(fn ($meal) => $meal['position'] === $position);
        if ($mealIndex === false) throw ValidationException::withMessages(['position' => 'Refeição inválida.']);
        $meal = $payload['meals'][$mealIndex];
        $current = collect($meal['items'])->firstWhere('food_id', $foodId);
        if (! $current || ! ($current['role'] ?? null)) throw ValidationException::withMessages(['food_id' => 'Alimento inválido para substituição.']);
        $food = $this->candidates($draft->preferences)->firstWhere('id', $replacementFoodId);
        if (! $food || ! $food->planTags->contains('slug', $current['role']) || $quantity < 1 || $quantity > 800) throw ValidationException::withMessages(['replacement_food_id' => 'Substituição incompatível.']);
        $replacement = ['food_id' => $food->id, 'descricao' => $food->descricao, 'role' => $current['role'], 'quantity' => round($quantity, 1), 'macros' => $this->foodMacros($food, $quantity)];
        $meal['items'] = collect($meal['items'])->map(fn ($item) => $item['food_id'] === $foodId ? $replacement : $item)->values()->all();
        $meal['totals'] = $this->sum(collect($meal['items'])->pluck('macros')->all());
        if (! $this->withinTarget($meal['target'], $meal['totals'])) throw ValidationException::withMessages(['replacement_food_id' => 'A troca ultrapassa a margem nutricional desta refeição.']);
        $payload['meals'][$mealIndex] = $meal;
        $payload['totals'] = $this->sum(collect($payload['meals'])->pluck('totals')->all());
        if (! $this->withinTarget($payload['target'], $payload['totals'])) throw ValidationException::withMessages(['replacement_food_id' => 'A troca ultrapassa a margem nutricional do dia.']);
        $draft->update(['previous_payload' => $draft->payload, 'payload' => $payload, 'expires_at' => now()->addMinutes(config('gemini.draft_ttl_minutes'))]);
        return $draft->fresh();
    }

    public function undo(User $user, MealPlanDraft $draft): MealPlanDraft
    {
        $this->assertDraft($user, $draft);
        if (! $draft->previous_payload) throw ValidationException::withMessages(['draft_id' => 'Não há alteração para desfazer.']);
        $draft->update(['payload' => $draft->previous_payload, 'previous_payload' => null]);
        return $draft->fresh();
    }

    public function save(User $user, MealPlanDraft $draft, string $title): MealPlan
    {
        return DB::transaction(function () use ($user, $draft, $title) {
            $draft = MealPlanDraft::query()->whereKey($draft->id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            if ($draft->expires_at->isPast()) throw ValidationException::withMessages(['draft_id' => 'Esta prévia expirou. Gere um novo plano.']);
            $payload = $draft->payload;
            $plan = MealPlan::create(['user_id' => $user->id, 'titulo' => $title, 'style' => $payload['preferences']['style'], 'generation_provider' => $draft->provider, 'generation_model' => $draft->model, 'generation_version' => 'ai-composition-v2', 'meal_count' => $payload['preferences']['meal_count'], 'preferences' => $payload['preferences'], 'target' => $payload['target'], 'totals' => $payload['totals'], 'warning' => $payload['warning']]);
            foreach ($payload['meals'] as $meal) {
                $stored = $plan->meals()->create(collect($meal)->only(['position', 'descricao', 'horario', 'target', 'totals'])->all());
                foreach ($meal['items'] as $item) $stored->items()->create(['food_id' => $item['food_id'], 'descricao_snapshot' => $item['descricao'], 'quantity' => $item['quantity'], 'culinary_role' => $item['role'] ?? null, 'macros' => $item['macros']]);
            }
            $draft->delete();
            return $plan->load('meals.items');
        });
    }

    private function draft(User $user, array $preferences, array $payload): MealPlanDraft
    {
        return MealPlanDraft::create(['user_id' => $user->id, 'provider' => 'gemini', 'model' => config('gemini.model'), 'preferences' => $preferences, 'payload' => $payload, 'expires_at' => now()->addMinutes(config('gemini.draft_ttl_minutes'))]);
    }

    private function assertDraft(User $user, MealPlanDraft $draft): void
    {
        abort_unless($draft->user_id === $user->id, 404);
        if ($draft->expires_at->isPast()) throw ValidationException::withMessages(['draft_id' => 'Esta prévia expirou. Gere um novo plano.']);
    }

    private function meta(User $user): Meta_diaria
    {
        $meta = Meta_diaria::query()->where('id_usuario', $user->id)->orderByRaw('case when data is null then 0 else 1 end')->orderByDesc('data')->first();
        if (! $meta) throw ValidationException::withMessages(['meta' => 'Defina sua meta diária antes de gerar um plano.']);
        return $meta;
    }

    private function candidates(array $preferences, int $minimum = 8): Collection
    {
        $query = Alimento::query()->where('status', 'ativo')->with(['planTags', 'restrictions'])->whereNotIn('id', $preferences['excluded_food_ids'] ?? []);
        foreach (array_values(array_unique([...(array) ($preferences['restriction_slugs'] ?? []), ...match ($preferences['diet_type'] ?? 'onivora') { 'vegetariana' => ['vegetariano'], 'vegana' => ['vegano'], default => [] }])) as $slug) $query->whereHas('restrictions', fn ($relation) => $relation->where('slug', $slug));
        $foods = $query->get()->sortByDesc(fn (Alimento $food) => ($food->planTags->contains('slug', 'base_alimentar') ? 1000 : 0) + ($food->planTags->contains('slug', $preferences['style']) ? 100 : 0))->values();
        if ($foods->count() < $minimum) throw ValidationException::withMessages(['restrictions' => 'Não há alimentos suficientes revisados para essas restrições.']);
        return $foods;
    }

    private function validatedGeneration(array $target, array $preferences, Collection $foods, Collection $definitions, ?string $instruction): array
    {
        $this->assertCandidateCoverage($foods, $definitions);
        $answer = $this->generate($target, $preferences, $foods, $definitions, $instruction);
        try { return $this->validatePlan($answer, $foods, $target, $definitions, $preferences); }
        catch (ValidationException) {
            $retryPreferences = [...$preferences, 'internal_retry' => 'A resposta anterior foi recusada. Refaça com a estrutura culinária obrigatória, somente IDs e papéis do catálogo, sem itens aleatórios.'];
            return $this->validatePlan($this->generate($target, $retryPreferences, $foods, $definitions, $instruction), $foods, $target, $definitions, $preferences);
        }
    }

    private function generate(array $target, array $preferences, Collection $foods, Collection $definitions, ?string $instruction): array
    {
        $context = ['papel' => 'Você monta planos alimentares brasileiros realistas. Não dê aconselhamento médico.', 'meta_do_dia' => $target, 'objetivo' => $preferences['objective'] ?? null, 'preferencias' => collect($preferences)->except(['excluded_food_ids', 'objective'])->all(), 'instrucao_de_troca' => $instruction, 'regras' => ['Use apenas IDs de candidatos por papel culinário.', 'Cada item deve trazer food_id, role e quantity_g.', 'Priorize combinações que fariam sentido juntas no cotidiano brasileiro.', 'Evite os alimentos indicados como preferência de alternativa quando houver opções equivalentes.', 'Não misture fruta, conservas, pães e queijo em almoço/jantar sem a estrutura de prato exigida.', 'Respeite os componentes obrigatórios e as metas de cada refeição.'], 'refeicoes' => $definitions->map(fn ($definition) => [...$definition, 'candidatos_por_papel' => $this->composition->candidatesByRole($foods, $definition)->map(fn ($items) => $this->foodData($items))->all()])->values()->all()];
        return $this->ask('plano_alimentar', $context, $this->planSchema($definitions->count()));
    }

    private function ask(string $task, array $context, array $schema): array
    {
        if (! config('gemini.enabled') || ! config('gemini.api_key')) throw ValidationException::withMessages(['ai' => 'A geração por IA não está configurada no momento.']);
        $started = microtime(true);
        try {
            $response = Http::acceptJson()->withHeaders(['x-goog-api-key' => config('gemini.api_key')])->timeout(config('gemini.timeout'))->post(config('gemini.endpoint'), ['model' => config('gemini.model'), 'input' => json_encode(['task' => $task, ...$context], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'response_format' => [['type' => 'text', 'mime_type' => 'application/json', 'schema' => $schema]]])->throw()->json();
            $step = collect($response['steps'] ?? [])->reverse()->first(fn ($item) => ($item['type'] ?? null) === 'model_output');
            $raw = data_get($response, 'output_text') ?? data_get($step, 'content.0.text');
            if (! is_string($raw)) throw new \RuntimeException('Gemini não retornou conteúdo estruturado.');
            Log::info('meal_plan.ai.generated', ['provider' => 'gemini', 'task' => $task, 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            Log::warning('meal_plan.ai.failed', ['provider' => 'gemini', 'task' => $task, 'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'error' => $exception->getMessage()]);
            throw ValidationException::withMessages(['ai' => 'Não foi possível gerar seu plano com IA agora. Tente novamente.']);
        }
    }

    private function validatePlan(array $answer, Collection $foods, array $target, Collection $definitions, array $preferences): array
    {
        if (! is_string($answer['summary'] ?? null) || ! is_array($answer['meals'] ?? null) || count($answer['meals']) !== $definitions->count()) throw ValidationException::withMessages(['ai' => 'A IA retornou um plano incompleto.']);
        $foodById = $foods->keyBy('id'); $meals = [];
        foreach ($definitions->values() as $index => $definition) {
            $aiMeal = $answer['meals'][$index] ?? null;
            if (! is_array($aiMeal) || (int) ($aiMeal['position'] ?? -1) !== $definition['position'] || ! is_array($aiMeal['items'] ?? null)) throw ValidationException::withMessages(['ai' => 'A IA retornou uma refeição inválida.']);
            $this->composition->validate($aiMeal['items'], $definition, $foods);
            $ids = [];
            $items = collect($aiMeal['items'])->map(function ($item) use ($foodById, &$ids) {
                $food = $foodById->get((int) ($item['food_id'] ?? 0)); $quantity = (float) ($item['quantity_g'] ?? 0);
                if (! $food || in_array($food->id, $ids, true) || $quantity < 1 || $quantity > 800) throw ValidationException::withMessages(['ai' => 'A IA escolheu alimento ou porção inválidos.']);
                $ids[] = $food->id;
                return ['food_id' => $food->id, 'descricao' => $food->descricao, 'role' => $item['role'], 'quantity' => round($quantity, 1), 'macros' => $this->foodMacros($food, $quantity)];
            })->values();
            $totals = $this->sum($items->pluck('macros')->all());
            if (! $this->withinTarget($definition['target'], $totals)) throw ValidationException::withMessages(['ai' => 'A IA não atingiu a meta desta refeição.']);
            $meals[] = ['position' => $definition['position'], 'descricao' => $definition['descricao'], 'horario' => $definition['horario'], 'target' => $definition['target'], 'totals' => $totals, 'explanation' => Str::limit(strip_tags((string) ($aiMeal['explanation'] ?? '')), 220, ''), 'items' => $items->all()];
        }
        $totals = $this->sum(array_column($meals, 'totals'));
        if (! $this->withinTarget($target, $totals)) throw ValidationException::withMessages(['ai' => 'A IA não atingiu a meta diária.']);
        return ['preferences' => $preferences, 'target' => $target, 'totals' => $totals, 'within_target' => true, 'warning' => null, 'summary' => Str::limit(strip_tags($answer['summary']), 300, ''), 'meals' => $meals];
    }

    private function assertCandidateCoverage(Collection $foods, Collection $definitions): void
    {
        foreach ($definitions as $definition) {
            $candidates = $this->composition->candidatesByRole($foods, $definition);
            foreach ($definition['composition']['required'] ?? [] as $role) {
                if (($candidates[$role] ?? collect())->isEmpty()) {
                    throw ValidationException::withMessages(['catalog' => 'Faltam alimentos revisados para montar uma refeição completa neste horário.']);
                }
            }
            foreach ($definition['composition']['required_any'] ?? [] as $alternatives) {
                if (collect($alternatives)->every(fn ($role) => ($candidates[$role] ?? collect())->isEmpty())) {
                    throw ValidationException::withMessages(['catalog' => 'Faltam opções revisadas para montar um lanche compatível.']);
                }
            }
        }
    }

    private function foodData(Collection $foods): array { return $foods->map(fn (Alimento $food) => ['id' => $food->id, 'descricao' => $food->descricao, 'qtd_base_g' => (float) $food->qtd, 'calorias' => (float) $food->caloria, 'proteina_g' => (float) $food->proteina, 'carbo_g' => (float) $food->carbo, 'gordura_g' => (float) $food->gordura, 'tags' => $food->planTags->pluck('slug')->all()])->values()->all(); }
    private function foodMacros(Alimento $food, float $quantity): array { return $this->scale($this->macros($food->caloria, $food->proteina, $food->carbo, $food->gordura), $quantity / max((float) $food->qtd, .001)); }
    private function planSchema(int $mealCount): array { return ['type' => 'object', 'properties' => ['summary' => ['type' => 'string'], 'meals' => ['type' => 'array', 'minItems' => $mealCount, 'maxItems' => $mealCount, 'items' => ['type' => 'object', 'properties' => ['position' => ['type' => 'integer'], 'explanation' => ['type' => 'string'], 'items' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 4, 'items' => ['type' => 'object', 'properties' => ['food_id' => ['type' => 'integer'], 'role' => ['type' => 'string'], 'quantity_g' => ['type' => 'number']], 'required' => ['food_id', 'role', 'quantity_g']]],], 'required' => ['position', 'explanation', 'items']]]], 'required' => ['summary', 'meals']]; }
    private function suggestionSchema(): array { return ['type' => 'object', 'properties' => ['suggestions' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 5, 'items' => ['type' => 'object', 'properties' => ['food_id' => ['type' => 'integer'], 'quantity_g' => ['type' => 'number'], 'reason' => ['type' => 'string']], 'required' => ['food_id', 'quantity_g', 'reason']]]], 'required' => ['suggestions']]; }
    private function macros(float $caloria, float $proteina, float $carbo, float $gordura): array { return ['caloria' => round($caloria, 3), 'proteina' => round($proteina, 3), 'carbo' => round($carbo, 3), 'gordura' => round($gordura, 3), 'quantidade' => 0.0]; }
    private function scale(array $macros, float $factor): array { return collect($macros)->map(fn ($value, $key) => $key === 'quantidade' ? 0.0 : round($value * $factor, 3))->all(); }
    private function sum(array $macros): array { $total = $this->macros(0, 0, 0, 0); foreach ($macros as $macro) foreach ($total as $key => $value) $total[$key] = round($value + ($macro[$key] ?? 0), 3); return $total; }
    private function delta(array $before, array $after): array { return collect($before)->map(fn ($value, $key) => $key === 'quantidade' ? 0 : round(($after[$key] ?? 0) - $value, 1))->all(); }
    private function withinTarget(array $target, array $totals): bool { foreach (['caloria' => .10, 'proteina' => .15, 'carbo' => .15, 'gordura' => .15] as $key => $tolerance) if (($target[$key] ?? 0) > 0 && abs($totals[$key] - $target[$key]) / $target[$key] > $tolerance) return false; return true; }
}

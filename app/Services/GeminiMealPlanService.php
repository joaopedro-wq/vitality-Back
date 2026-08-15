<?php

namespace App\Services;

use App\Models\Alimento;
use App\Models\MealPlanDraft;
use App\Models\Meta_diaria;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GeminiMealPlanService
{
    public function preview(User $user, array $preferences): MealPlanDraft
    {
        $meta = $this->meta($user);
        $target = $this->macros($meta->meta_calorias, $meta->meta_proteinas, $meta->meta_carboidratos, $meta->meta_gorduras);
        $meals = $this->mealDefinitions($preferences, $target);
        $foods = $this->candidates($preferences);
        $answer = $this->generate($target, $preferences, $foods, $meals, null);
        $payload = $this->validatePlan($answer, $foods, $target, $meals, $preferences);

        return MealPlanDraft::create([
            'user_id' => $user->id,
            'provider' => 'gemini',
            'model' => config('gemini.model'),
            'preferences' => $preferences,
            'payload' => $payload,
            'expires_at' => now()->addMinutes(config('gemini.draft_ttl_minutes')),
        ]);
    }

    public function replaceMeal(User $user, MealPlanDraft $draft, int $position, ?string $instruction): MealPlanDraft
    {
        $preferences = $draft->preferences;
        $definitions = $this->mealDefinitions($preferences, $draft->payload['target']);
        $definition = $definitions->firstWhere('position', $position);
        if (! $definition) {
            throw ValidationException::withMessages(['position' => 'Refeição inválida.']);
        }
        $existing = collect($draft->payload['meals'])->firstWhere('position', $position);
        $preferences['excluded_food_ids'] = array_values(array_unique([
            ...($preferences['excluded_food_ids'] ?? []),
            ...collect($existing['items'] ?? [])->pluck('food_id')->all(),
        ]));
        $foods = $this->candidates($preferences);
        $answer = $this->generate($draft->payload['target'], $preferences, $foods, collect([$definition]), $instruction);
        $replacement = $this->validatePlan($answer, $foods, $definition['target'], collect([$definition]), $preferences);

        $payload = $draft->payload;
        $payload['summary'] = $answer['summary'];
        $payload['meals'] = collect($payload['meals'])->map(fn (array $meal) => $meal['position'] === $position ? $replacement['meals'][0] : $meal)->values()->all();
        $payload['totals'] = $this->sum(collect($payload['meals'])->pluck('totals')->all());
        if (! $this->withinTarget($payload['target'], $payload['totals'])) {
            throw ValidationException::withMessages(['plan' => 'A nova refeição não mantém o plano dentro das metas. Tente novamente.']);
        }
        $draft->update(['payload' => $payload, 'expires_at' => now()->addMinutes(config('gemini.draft_ttl_minutes'))]);
        return $draft->fresh();
    }

    public function save(User $user, MealPlanDraft $draft, string $title): \App\Models\MealPlan
    {
        return DB::transaction(function () use ($user, $draft, $title) {
            $draft = MealPlanDraft::query()->whereKey($draft->id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            if ($draft->expires_at->isPast()) throw ValidationException::withMessages(['draft_id' => 'Esta prévia expirou. Gere um novo plano.']);
            $payload = $draft->payload;
            $plan = \App\Models\MealPlan::create([
                'user_id' => $user->id, 'titulo' => $title, 'style' => $payload['preferences']['style'],
                'generation_provider' => $draft->provider, 'generation_model' => $draft->model, 'generation_version' => 'ai-v1',
                'meal_count' => $payload['preferences']['meal_count'], 'preferences' => $payload['preferences'],
                'target' => $payload['target'], 'totals' => $payload['totals'], 'warning' => $payload['warning'],
            ]);
            foreach ($payload['meals'] as $meal) {
                $stored = $plan->meals()->create(collect($meal)->only(['position', 'descricao', 'horario', 'target', 'totals'])->all());
                foreach ($meal['items'] as $item) $stored->items()->create(['food_id' => $item['food_id'], 'descricao_snapshot' => $item['descricao'], 'quantity' => $item['quantity'], 'macros' => $item['macros']]);
            }
            $draft->delete();
            return $plan->load('meals.items');
        });
    }

    private function meta(User $user): Meta_diaria
    {
        $meta = Meta_diaria::query()->where('id_usuario', $user->id)
            ->orderByRaw('case when data is null then 0 else 1 end')->orderByDesc('data')->first();
        if (! $meta) throw ValidationException::withMessages(['meta' => 'Defina sua meta diária antes de gerar um plano.']);
        return $meta;
    }

    private function candidates(array $preferences): Collection
    {
        $query = Alimento::query()->where('status', 'ativo')->with(['planTags', 'restrictions'])
            ->whereNotIn('id', $preferences['excluded_food_ids'] ?? []);
        $required = array_values(array_unique([
            ...($preferences['restriction_slugs'] ?? []),
            ...match ($preferences['diet_type'] ?? 'onivora') {
                'vegetariana' => ['vegetariano'],
                'vegana' => ['vegano'],
                default => [],
            },
        ]));
        foreach ($required as $slug) $query->whereHas('restrictions', fn ($relation) => $relation->where('slug', $slug));
        $foods = $query->get()->sortByDesc(fn (Alimento $food) => ($food->planTags->contains('slug', 'base_alimentar') ? 1000 : 0) + ($food->planTags->contains('slug', $preferences['style']) ? 100 : 0))->take(160)->values();
        if ($foods->count() < 8) throw ValidationException::withMessages(['restrictions' => 'Não há alimentos suficientes revisados para essas restrições. Ajuste o perfil ou peça a revisão do catálogo.']);
        return $foods;
    }

    private function generate(array $target, array $preferences, Collection $foods, Collection $definitions, ?string $instruction): array
    {
        if (! config('gemini.enabled') || ! config('gemini.api_key')) {
            throw ValidationException::withMessages(['ai' => 'A geração por IA não está configurada no momento.']);
        }
        $foodData = $foods->map(fn (Alimento $food) => [
            'id' => $food->id, 'descricao' => $food->descricao, 'qtd_base_g' => (float) $food->qtd,
            'calorias' => (float) $food->caloria, 'proteina_g' => (float) $food->proteina, 'carbo_g' => (float) $food->carbo, 'gordura_g' => (float) $food->gordura,
            'tags' => $food->planTags->pluck('slug')->all(),
        ])->all();
        $prompt = json_encode([
            'papel' => 'Você é um assistente de planejamento alimentar geral. Não dê aconselhamento médico.',
            'regras' => ['Use exclusivamente IDs do catálogo fornecido.', 'Respeite as metas de cada refeição.', 'Prefira variedade, alimentos base e a preferência de estilo.', 'Não escreva recomendações clínicas.'],
            'meta_do_dia' => $target,
            'preferencias' => collect($preferences)->except(['excluded_food_ids'])->all(),
            'instrucao_de_troca' => $instruction,
            'refeicoes' => $definitions->values()->all(),
            'catalogo_permitido' => $foodData,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $started = microtime(true);
        try {
            $response = Http::acceptJson()->withHeaders(['x-goog-api-key' => config('gemini.api_key')])
                ->timeout(config('gemini.timeout'))->post(config('gemini.endpoint'), [
                    'model' => config('gemini.model'), 'input' => $prompt,
                    'response_format' => ['type' => 'text', 'mime_type' => 'application/json', 'schema' => $this->schema($definitions->count())],
                ])->throw()->json();
            $raw = data_get($response, 'output_text');
            if (! is_string($raw)) throw new \RuntimeException('Gemini não retornou conteúdo estruturado.');
            $answer = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            Log::info('meal_plan.ai.generated', ['provider' => 'gemini', 'model' => config('gemini.model'), 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);
            return $answer;
        } catch (\Throwable $exception) {
            Log::warning('meal_plan.ai.failed', ['provider' => 'gemini', 'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'error' => $exception->getMessage()]);
            throw ValidationException::withMessages(['ai' => 'Não foi possível gerar seu plano com IA agora. Tente novamente.']);
        }
    }

    private function validatePlan(array $answer, Collection $foods, array $target, Collection $definitions, array $preferences): array
    {
        if (! is_string($answer['summary'] ?? null) || ! is_array($answer['meals'] ?? null) || count($answer['meals']) !== $definitions->count()) {
            throw ValidationException::withMessages(['ai' => 'A IA retornou um plano incompleto. Tente novamente.']);
        }
        $foodById = $foods->keyBy('id');
        $result = [];
        foreach ($definitions->values() as $index => $definition) {
            $aiMeal = $answer['meals'][$index] ?? null;
            if (! is_array($aiMeal) || (int) ($aiMeal['position'] ?? -1) !== $definition['position'] || ! is_array($aiMeal['items'] ?? null) || count($aiMeal['items']) < 2 || count($aiMeal['items']) > 5) {
                throw ValidationException::withMessages(['ai' => 'A IA retornou uma refeição inválida. Tente novamente.']);
            }
            $ids = [];
            $items = collect($aiMeal['items'])->map(function ($item) use ($foodById, &$ids) {
                $id = (int) ($item['food_id'] ?? 0);
                $quantity = (float) ($item['quantity_g'] ?? 0);
                $food = $foodById->get($id);
                if (! $food || in_array($id, $ids, true) || $quantity < 20 || $quantity > 800) {
                    throw ValidationException::withMessages(['ai' => 'A IA escolheu um alimento ou porção inválida. Tente novamente.']);
                }
                $ids[] = $id;
                return ['food_id' => $food->id, 'descricao' => $food->descricao, 'quantity' => round($quantity, 1), 'macros' => $this->scale($this->macros($food->caloria, $food->proteina, $food->carbo, $food->gordura), $quantity / max((float) $food->qtd, .001))];
            })->values();
            $totals = $this->sum($items->pluck('macros')->all());
            if (! $this->withinTarget($definition['target'], $totals)) throw ValidationException::withMessages(['ai' => 'A IA não atingiu a meta desta refeição. Tente novamente.']);
            $result[] = ['position' => $definition['position'], 'descricao' => $definition['descricao'], 'horario' => $definition['horario'], 'target' => $definition['target'], 'totals' => $totals, 'explanation' => Str::limit(strip_tags((string) ($aiMeal['explanation'] ?? '')), 220, ''), 'items' => $items->all()];
        }
        $totals = $this->sum(array_column($result, 'totals'));
        if (! $this->withinTarget($target, $totals)) throw ValidationException::withMessages(['ai' => 'A IA não atingiu a meta diária. Tente novamente.']);
        return ['preferences' => $preferences ?? [], 'target' => $target, 'totals' => $totals, 'within_target' => true, 'warning' => null, 'summary' => Str::limit(strip_tags($answer['summary']), 300, ''), 'meals' => $result];
    }

    private function mealDefinitions(array $preferences, array $target): Collection
    {
        $ratios = match ((int) $preferences['meal_count']) { 3 => [0.25, .4, .35], 4 => [.25, .35, .15, .25], 5 => [.2, .1, .3, .15, .25] };
        $labels = match ((int) $preferences['meal_count']) { 3 => ['Café da manhã', 'Almoço', 'Jantar'], 4 => ['Café da manhã', 'Almoço', 'Lanche', 'Jantar'], 5 => ['Café da manhã', 'Lanche da manhã', 'Almoço', 'Lanche da tarde', 'Jantar'] };
        return collect($ratios)->map(fn ($ratio, $position) => ['position' => $position, 'descricao' => $labels[$position], 'horario' => $preferences['meal_times'][$position], 'target' => $this->scale($target, $ratio)]);
    }

    private function schema(int $mealCount): array
    {
        return ['type' => 'object', 'properties' => [
            'summary' => ['type' => 'string'],
            'meals' => ['type' => 'array', 'minItems' => $mealCount, 'maxItems' => $mealCount, 'items' => ['type' => 'object', 'properties' => [
                'position' => ['type' => 'integer'], 'explanation' => ['type' => 'string'],
                'items' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 5, 'items' => ['type' => 'object', 'properties' => ['food_id' => ['type' => 'integer'], 'quantity_g' => ['type' => 'number']], 'required' => ['food_id', 'quantity_g']]],
            ], 'required' => ['position', 'explanation', 'items']]],
        ], 'required' => ['summary', 'meals']];
    }

    private function macros(float $caloria, float $proteina, float $carbo, float $gordura): array { return ['caloria' => round($caloria, 3), 'proteina' => round($proteina, 3), 'carbo' => round($carbo, 3), 'gordura' => round($gordura, 3), 'quantidade' => 0.0]; }
    private function scale(?array $macros, float $factor): array { return collect($macros ?? $this->macros(0, 0, 0, 0))->map(fn ($value, $key) => $key === 'quantidade' ? 0.0 : round($value * $factor, 3))->all(); }
    private function sum(array $macros): array { $total = $this->macros(0, 0, 0, 0); foreach ($macros as $macro) foreach ($total as $key => $value) $total[$key] = round($value + ($macro[$key] ?? 0), 3); return $total; }
    private function withinTarget(array $target, array $totals): bool { foreach (['caloria' => .10, 'proteina' => .15, 'carbo' => .15, 'gordura' => .15] as $key => $tolerance) if (($target[$key] ?? 0) > 0 && abs($totals[$key] - $target[$key]) / $target[$key] > $tolerance) return false; return true; }
}

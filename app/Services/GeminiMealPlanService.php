<?php

namespace App\Services;

use App\Models\Alimento;
use App\Models\MealPlan;
use App\Models\MealPlanDraft;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GeminiMealPlanService
{
    public function __construct(
        private readonly MealCompositionService $composition,
        private readonly MealPlanMacroCalculator $macroCalculator,
        private readonly MealPlanFeasibilityService $feasibility,
    ) {}

    public function preview(User $user, array $preferences): MealPlanDraft
    {
        $preferences['objective'] = $user->objetivo;
        $target = $this->macroCalculator->resolveDailyTarget($user);
        $definitions = $this->composition->definitions($preferences, $target);
        $assessment = $this->feasibility->assess($preferences);
        if (! $assessment['feasible']) {
            throw ValidationException::withMessages(['preferences' => $assessment['message']]);
        }
        $foods = $this->candidates($preferences);
        $this->assertIncludedFoodsEligible($foods, $definitions, $preferences);
        $payload = $this->validatedGeneration($target, $preferences, $foods, $definitions, null);

        return $this->draft($user, $preferences, $payload);
    }

    /**
     * Checagem antecipada e determinística: não confia no prompt para garantir que
     * `included_food_ids` apareça no plano. Cada id obrigatório precisa ser um candidato
     * elegível (ativo, não excluído, compatível com diet_type/restrições) e precisa ter
     * pelo menos um papel culinário usado em algum template do dia — senão nunca haveria
     * onde encaixá-lo, e `enforceIncludedFoods()` falharia silenciosamente mais tarde.
     */
    private function assertIncludedFoodsEligible(Collection $foods, Collection $definitions, array $preferences): void
    {
        $includedIds = $preferences['included_food_ids'] ?? [];
        if (empty($includedIds)) {
            return;
        }
        $foodById = $foods->keyBy('id');
        $validRoles = $definitions->flatMap(fn (array $definition) => $this->definitionRoles($definition))->unique()->values()->all();

        foreach ($includedIds as $includedId) {
            $food = $foodById->get((int) $includedId);
            if (! $food) {
                throw ValidationException::withMessages(['included_food_ids' => __('messages.meal_plan.included_food_not_eligible')]);
            }
            if (empty(array_intersect($this->composition->rolesForFood($food), $validRoles))) {
                throw ValidationException::withMessages(['included_food_ids' => __('messages.meal_plan.included_food_incompatible_role')]);
            }
        }
    }

    public function clonePlan(User $user, MealPlan $plan): MealPlanDraft
    {
        abort_unless($plan->user_id === $user->id, 404);
        $plan->load('meals.items.food.planTags');
        $preferences = [...$plan->preferences, 'objective' => $user->objetivo];
        $payload = [
            'titulo' => $plan->titulo,
            'preferences' => $preferences, 'target' => $plan->target, 'totals' => $plan->totals,
            'within_target' => ! $plan->warning, 'warning' => $plan->warning, 'summary' => __('messages.meal_plan.editable_copy_of', ['title' => $plan->titulo]),
            'meals' => $plan->meals->map(fn ($meal) => [
                'position' => $meal->position, 'descricao' => $meal->descricao, 'horario' => substr((string) $meal->horario, 0, 5),
                'target' => $meal->target, 'totals' => $meal->totals, 'explanation' => __('messages.meal_plan.copied_for_editing'),
                'items' => $meal->items->map(fn ($item) => [
                    'food_id' => $item->food_id, 'descricao' => $item->descricao_snapshot,
                    'descricao_exibicao' => $item->nome_exibicao_snapshot ?? $item->food->nome_exibicao,
                    'detalhe_exibicao' => $item->detalhe_exibicao_snapshot ?? $item->food->detalhe_exibicao,
                    'role' => $item->culinary_role ?: (collect($this->composition->rolesForFood($item->food))->first() ?? 'acompanhamento'),
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
        if (! $definition) {
            throw ValidationException::withMessages(['position' => __('messages.invalid_meal')]);
        }
        $existing = collect($draft->payload['meals'])->firstWhere('position', $position);
        $existingItems = collect($existing['items'] ?? []);
        // Escopa included_food_ids só aos ids que já pertenciam a ESTA refeição — passar
        // obrigatórios de outras refeições do dia faria enforceIncludedFoods() tentar
        // encaixá-los aqui, e uma chamada com escopo de 1 refeição não tem onde mais olhar.
        $scopedIncludedIds = collect($draft->preferences['included_food_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->intersect($existingItems->pluck('food_id')->map(fn ($id) => (int) $id))
            ->values()->all();
        $preferences = [
            ...$draft->preferences,
            'included_food_ids' => $scopedIncludedIds,
            'excluded_food_ids' => array_values(array_unique([
                ...($draft->preferences['excluded_food_ids'] ?? []),
                ...$existingItems->pluck('food_id')->reject(fn ($id) => in_array((int) $id, $scopedIncludedIds, true))->all(),
            ])),
        ];
        $replacement = $this->validatedGeneration($definition['target'], $preferences, $this->candidates($preferences, 3), collect([$definition]), $instruction ?: 'Reorganize esta refeição inteira com uma combinação culinária natural.');
        $payload = $draft->payload;
        $payload['summary'] = $replacement['summary'];
        $payload['meals'] = collect($payload['meals'])->map(fn ($meal) => $meal['position'] === $position ? $replacement['meals'][0] : $meal)->values()->all();
        $payload['totals'] = $this->macroCalculator->sum(collect($payload['meals'])->pluck('totals')->all());
        if (! $this->macroCalculator->withinTarget($payload['target'], $payload['totals'], 'swap_day')) {
            throw ValidationException::withMessages(['plan' => __('messages.meal_plan.reorganize_off_target')]);
        }
        $draft->update(['previous_payload' => $draft->payload, 'payload' => $payload, 'expires_at' => now()->addMinutes(config('gemini.draft_ttl_minutes'))]);

        return $draft->fresh();
    }

    /** @return list<array<string, mixed>> */
    public function itemSuggestions(User $user, MealPlanDraft $draft, int $position, int $foodId): array
    {
        $this->assertDraft($user, $draft);
        if ($this->isIncludedFood($draft, $foodId)) {
            throw ValidationException::withMessages(['food_id' => __('messages.meal_plan.included_food_locked')]);
        }
        $meal = collect($draft->payload['meals'])->firstWhere('position', $position);
        $item = collect($meal['items'] ?? [])->firstWhere('food_id', $foodId);
        if (! $meal || ! $item) {
            throw ValidationException::withMessages(['food_id' => __('messages.meal_plan.item_not_in_meal')]);
        }
        $role = $item['role'] ?? null;
        if (! $role) {
            throw ValidationException::withMessages(['food_id' => __('messages.meal_plan.item_no_role')]);
        }
        $foods = $this->candidates($draft->preferences)
            ->filter(fn (Alimento $food) => $food->id !== $foodId && in_array($role, $this->composition->rolesForFood($food), true))
            ->sortByDesc(fn (Alimento $food) => $this->replacementCandidateScore($food, $meal, $item))
            ->take(40)
            ->values();
        if ($foods->count() < 2) {
            Log::info('meal_plan.swap.rejected', ['reason' => 'no_replacements', 'position' => $position, 'food_id' => $foodId]);
            throw ValidationException::withMessages(['food_id' => __('messages.no_replacements')]);
        }

        try {
            $answer = $this->ask('substituicao', [
                'alimento_atual' => $item, 'papel_culinario' => $role, 'refeicao_atual' => $meal,
                'candidatos' => $this->foodData($foods), 'regras' => ['Sugira entre 2 e 5 substituições para o mesmo papel culinário.', 'Mantenha a refeição coerente e as calorias/macros próximas.', 'Use somente IDs candidatos.', $this->languageInstruction('O campo reason de cada sugestão deve ser escrito em')],
            ], $this->suggestionSchema());
        } catch (ValidationException $exception) {
            Log::warning('meal_plan.swap.ai_failed', ['position' => $position, 'food_id' => $foodId, 'error' => $exception->getMessage()]);
            $answer = ['suggestions' => []];
        }
        $foodById = $foods->keyBy('id');
        $answer['suggestions'] = collect($answer['suggestions'] ?? [])
            ->merge($foods->take(12)->map(fn (Alimento $food) => ['food_id' => $food->id, 'quantity_g' => $item['quantity'], 'reason' => __('messages.meal_plan.fallback_swap_reason')])->all())
            ->unique('food_id')
            ->values()
            ->all();
        $suggestions = collect($answer['suggestions'] ?? [])->map(function ($suggestion) use ($foodById, $meal, $foodId, $item, $draft) {
            $food = $foodById->get((int) ($suggestion['food_id'] ?? 0));
            // bestSingleReplacementQuantity() já normaliza pro min/max/step do alimento
            // internamente (mesma normalizeQuantity() que rebalanceItems() usa) — nada a
            // clampar aqui, só checar coerência.
            $quantity = $food ? $this->bestSingleReplacementQuantity($meal, $foodId, $food) : 0.0;
            if (! $food || ! $this->replacementKeepsMealCoherent($meal, $foodId, $food)) {
                return null;
            }
            $replacement = ['food_id' => $food->id, 'descricao' => $food->descricao, 'descricao_exibicao' => $food->nome_exibicao, 'detalhe_exibicao' => $food->detalhe_exibicao, 'role' => $item['role'], 'quantity' => round($quantity, 1), 'macros' => $this->macroCalculator->foodMacros($food, $quantity)];
            $items = collect($meal['items'])->map(fn ($mealItem) => $mealItem['food_id'] === $foodId ? $replacement : $mealItem)->all();
            $totals = $this->macroCalculator->sum(collect($items)->pluck('macros')->all());
            // Confere também a margem do DIA (não só da refeição) com essa troca aplicada —
            // sem isso, a sugestão passa aqui (tolerância de refeição é larga) e só falha depois,
            // quando a pessoa confirma em applyItemReplacement() (que já checa as duas).
            $dayMeals = collect($draft->payload['meals'])->map(fn ($mealDoDia) => $mealDoDia['position'] === $meal['position'] ? [...$mealDoDia, 'totals' => $totals] : $mealDoDia);
            $dayTotals = $this->macroCalculator->sum($dayMeals->pluck('totals')->all());
            $withinDay = $this->macroCalculator->withinTarget($draft->payload['target'], $dayTotals, 'swap_day');

            return ['food_id' => $food->id, 'descricao' => $food->descricao, 'descricao_exibicao' => $food->nome_exibicao, 'detalhe_exibicao' => $food->detalhe_exibicao, 'quantity' => $replacement['quantity'], 'role' => $item['role'], 'macros' => $replacement['macros'], 'reason' => Str::limit(strip_tags((string) ($suggestion['reason'] ?? __('messages.meal_plan.fallback_suggestion_reason'))), 160, ''), 'meal_totals' => $totals, 'delta' => $this->macroCalculator->delta($meal['totals'], $totals), 'within_target' => $withinDay && $this->macroCalculator->withinTarget($meal['target'], $totals, 'swap')];
        })->filter(fn ($suggestion) => $suggestion && $suggestion['within_target'])->unique('food_id')->take(5)->values();
        if ($suggestions->isEmpty()) {
            Log::info('meal_plan.swap.rejected', ['reason' => 'no_close_swap', 'position' => $position, 'food_id' => $foodId]);
            throw ValidationException::withMessages(['food_id' => __('messages.meal_plan.no_close_swap')]);
        }

        return $suggestions->all();
    }

    public function applyItemReplacement(User $user, MealPlanDraft $draft, int $position, int $foodId, int $replacementFoodId, float $quantity): MealPlanDraft
    {
        $this->assertDraft($user, $draft);
        if ($this->isIncludedFood($draft, $foodId)) {
            throw ValidationException::withMessages(['food_id' => __('messages.meal_plan.included_food_locked')]);
        }
        $payload = $draft->payload;
        $mealIndex = collect($payload['meals'])->search(fn ($meal) => $meal['position'] === $position);
        if ($mealIndex === false) {
            throw ValidationException::withMessages(['position' => __('messages.invalid_meal')]);
        }
        $meal = $payload['meals'][$mealIndex];
        $current = collect($meal['items'])->firstWhere('food_id', $foodId);
        if (! $current || ! ($current['role'] ?? null)) {
            throw ValidationException::withMessages(['food_id' => __('messages.meal_plan.invalid_replacement_food')]);
        }
        $food = $this->candidates($draft->preferences)->firstWhere('id', $replacementFoodId);
        if (! $food || ! in_array($current['role'], $this->composition->rolesForFood($food), true) || ! $this->replacementKeepsMealCoherent($meal, $foodId, $food)) {
            Log::info('meal_plan.swap.rejected', ['reason' => 'incompatible_replacement', 'position' => $position, 'food_id' => $foodId, 'replacement_food_id' => $replacementFoodId]);
            throw ValidationException::withMessages(['replacement_food_id' => __('messages.meal_plan.incompatible_replacement')]);
        }
        $quantity = $this->bestSingleReplacementQuantity($meal, $foodId, $food) ?: $quantity;
        $replacement = ['food_id' => $food->id, 'descricao' => $food->descricao, 'descricao_exibicao' => $food->nome_exibicao, 'detalhe_exibicao' => $food->detalhe_exibicao, 'role' => $current['role'], 'quantity' => round($quantity, 1), 'macros' => $this->macroCalculator->foodMacros($food, $quantity)];
        $meal['items'] = collect($meal['items'])->map(fn ($item) => $item['food_id'] === $foodId ? $replacement : $item)->values()->all();
        $meal['totals'] = $this->macroCalculator->sum(collect($meal['items'])->pluck('macros')->all());
        if (! $this->macroCalculator->withinTarget($meal['target'], $meal['totals'], 'swap')) {
            Log::info('meal_plan.swap.rejected', ['reason' => 'swap_out_of_meal_margin', 'position' => $position, 'food_id' => $foodId, 'replacement_food_id' => $replacementFoodId]);
            throw ValidationException::withMessages(['replacement_food_id' => __('messages.meal_plan.swap_out_of_meal_margin')]);
        }
        $payload['meals'][$mealIndex] = $meal;
        $payload['totals'] = $this->macroCalculator->sum(collect($payload['meals'])->pluck('totals')->all());
        if (! $this->macroCalculator->withinTarget($payload['target'], $payload['totals'], 'swap_day')) {
            Log::info('meal_plan.swap.rejected', ['reason' => 'swap_out_of_day_margin', 'position' => $position, 'food_id' => $foodId, 'replacement_food_id' => $replacementFoodId]);
            throw ValidationException::withMessages(['replacement_food_id' => __('messages.meal_plan.swap_out_of_day_margin')]);
        }
        $draft->update(['previous_payload' => $draft->payload, 'payload' => $payload, 'expires_at' => now()->addMinutes(config('gemini.draft_ttl_minutes'))]);

        return $draft->fresh();
    }

    public function refreshDraftLocale(User $user, MealPlanDraft $draft): MealPlanDraft
    {
        $this->assertDraft($user, $draft);
        $payload = $draft->payload;

        $descricaoPorPosicao = $this->composition
            ->definitions($payload['preferences'], $payload['target'])
            ->pluck('descricao', 'position');

        $foodIds = collect($payload['meals'])
            ->flatMap(fn ($meal) => collect($meal['items'])->pluck('food_id'))
            ->unique()
            ->values();
        $foodsById = Alimento::query()->whereIn('id', $foodIds)->get()->keyBy('id');

        $payload['meals'] = collect($payload['meals'])->map(function ($meal) use ($descricaoPorPosicao, $foodsById) {
            $meal['descricao'] = $descricaoPorPosicao->get($meal['position'], $meal['descricao']);
            $meal['items'] = collect($meal['items'])->map(function ($item) use ($foodsById) {
                $food = $foodsById->get((int) ($item['food_id'] ?? 0));
                if ($food) {
                    $item['descricao_exibicao'] = $food->nome_exibicao;
                    $item['detalhe_exibicao'] = $food->detalhe_exibicao;
                }

                return $item;
            })->values()->all();

            return $meal;
        })->values()->all();

        $draft->update(['payload' => $payload]);

        return $draft->fresh();
    }

    public function undo(User $user, MealPlanDraft $draft): MealPlanDraft
    {
        $this->assertDraft($user, $draft);
        if (! $draft->previous_payload) {
            throw ValidationException::withMessages(['draft_id' => __('messages.meal_plan.nothing_to_undo')]);
        }
        $draft->update(['payload' => $draft->previous_payload, 'previous_payload' => null]);

        return $draft->fresh();
    }

    public function save(User $user, MealPlanDraft $draft, string $title): MealPlan
    {
        return DB::transaction(function () use ($user, $draft, $title) {
            $draft = MealPlanDraft::query()->whereKey($draft->id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            if ($draft->expires_at->isPast()) {
                throw ValidationException::withMessages(['draft_id' => __('messages.preview_expired')]);
            }
            $payload = $draft->payload;
            $plan = MealPlan::create(['user_id' => $user->id, 'titulo' => $title, 'style' => $payload['preferences']['style'], 'generation_provider' => $draft->provider, 'generation_model' => $draft->model, 'generation_version' => 'ai-composition-v3', 'meal_count' => $payload['preferences']['meal_count'], 'preferences' => $payload['preferences'], 'target' => $payload['target'], 'totals' => $payload['totals'], 'warning' => $payload['warning']]);
            $this->persistMeals($plan, $payload);
            $draft->delete();

            return $plan->load('meals.items');
        });
    }

    public function update(User $user, MealPlan $plan, MealPlanDraft $draft, string $title): MealPlan
    {
        abort_unless($plan->user_id === $user->id, 404);

        return DB::transaction(function () use ($user, $plan, $draft, $title) {
            $plan = MealPlan::query()->whereKey($plan->id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $draft = MealPlanDraft::query()->whereKey($draft->id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            if ($draft->expires_at->isPast()) {
                throw ValidationException::withMessages(['draft_id' => __('messages.preview_expired')]);
            }
            $payload = $draft->payload;
            $plan->update(['titulo' => $title, 'style' => $payload['preferences']['style'], 'generation_provider' => $draft->provider, 'generation_model' => $draft->model, 'generation_version' => 'ai-composition-v3', 'meal_count' => $payload['preferences']['meal_count'], 'preferences' => $payload['preferences'], 'target' => $payload['target'], 'totals' => $payload['totals'], 'warning' => $payload['warning']]);
            $plan->meals()->delete();
            $this->persistMeals($plan, $payload);
            $draft->delete();

            return $plan->load('meals.items');
        });
    }

    private function persistMeals(MealPlan $plan, array $payload): void
    {
        foreach ($payload['meals'] as $meal) {
            $stored = $plan->meals()->create(collect($meal)->only(['position', 'descricao', 'horario', 'target', 'totals'])->all());
            foreach ($meal['items'] as $item) {
                $stored->items()->create(['food_id' => $item['food_id'], 'descricao_snapshot' => $item['descricao'], 'nome_exibicao_snapshot' => $item['descricao_exibicao'] ?? $item['descricao'], 'detalhe_exibicao_snapshot' => $item['detalhe_exibicao'] ?? null, 'quantity' => $item['quantity'], 'culinary_role' => $item['role'] ?? null, 'macros' => $item['macros']]);
            }
        }
    }

    private function draft(User $user, array $preferences, array $payload): MealPlanDraft
    {
        return MealPlanDraft::create(['user_id' => $user->id, 'provider' => 'gemini', 'model' => config('gemini.model'), 'preferences' => $preferences, 'payload' => $payload, 'expires_at' => now()->addMinutes(config('gemini.draft_ttl_minutes'))]);
    }

    private function assertDraft(User $user, MealPlanDraft $draft): void
    {
        abort_unless($draft->user_id === $user->id, 404);
        if ($draft->expires_at->isPast()) {
            throw ValidationException::withMessages(['draft_id' => __('messages.preview_expired')]);
        }
    }

    private function isIncludedFood(MealPlanDraft $draft, int $foodId): bool
    {
        return collect($draft->preferences['included_food_ids'] ?? [])->contains(fn ($id) => (int) $id === $foodId);
    }

    private function candidates(array $preferences, int $minimum = 8): Collection
    {
        $foods = $this->feasibility->candidates($preferences)
            ->sortByDesc(fn (Alimento $food) => ($food->planTags->contains('slug', 'base_alimentar') ? 1000 : 0) + ($food->planTags->contains('slug', $preferences['style']) ? 100 : 0))->values();
        if ($foods->count() < $minimum) {
            throw ValidationException::withMessages(['restrictions' => __('messages.meal_plan.insufficient_reviewed_foods')]);
        }

        return $foods;
    }

    private function replacementCandidateScore(Alimento $food, array $meal, array $current): float
    {
        $score = ($food->planTags->contains('slug', 'base_alimentar') ? 1000 : 0) + ($food->planTags->contains('slug', 'caseiro') ? 100 : 0);
        $currentFamily = $this->foodFamilyFromItem($current);
        $family = $this->composition->foodFamily($food);
        if ($family === $currentFamily) {
            $score += 180;
        }
        if ($this->isBreakfastMeal($meal)) {
            $score += match ($family) {
                'fruta', 'ovo', 'iogurte', 'aveia_cereal', 'tapioca', 'pao_torrada', 'queijo', 'leite_liquido' => 240,
                'leite_po' => -700,
                default => 0,
            };
        }

        $quantity = $this->bestSingleReplacementQuantity($meal, (int) $current['food_id'], $food);
        if ($quantity > 0) {
            $replacement = ['food_id' => $food->id, 'descricao' => $food->descricao, 'descricao_exibicao' => $food->nome_exibicao, 'detalhe_exibicao' => $food->detalhe_exibicao, 'role' => $current['role'], 'quantity' => $quantity, 'macros' => $this->macroCalculator->foodMacros($food, $quantity)];
            $items = collect($meal['items'])->map(fn ($item) => $item['food_id'] === $current['food_id'] ? $replacement : $item)->all();
            $score -= $this->targetScore($meal['target'], $this->macroCalculator->sum(collect($items)->pluck('macros')->all())) * 1000;
        }

        return $score;
    }

    private function bestSingleReplacementQuantity(array $meal, int $foodId, Alimento $food): float
    {
        $other = $this->macroCalculator->macros(0, 0, 0, 0);
        foreach ($meal['items'] as $item) {
            if ((int) $item['food_id'] === $foodId) {
                continue;
            }
            foreach ($other as $key => $value) {
                $other[$key] = round($value + ($item['macros'][$key] ?? 0), 3);
            }
        }

        $coefficients = $this->macroCalculator->foodMacros($food, 1);
        $numerator = 0.0;
        $denominator = 0.0;
        foreach (['caloria', 'proteina', 'carbo', 'gordura'] as $key) {
            if (($meal['target'][$key] ?? 0) <= 0 || ($coefficients[$key] ?? 0) <= 0) {
                continue;
            }
            $weight = 1 / ($meal['target'][$key] * $meal['target'][$key]);
            $numerator += $weight * $coefficients[$key] * ($meal['target'][$key] - $other[$key]);
            $denominator += $weight * $coefficients[$key] * $coefficients[$key];
        }
        if ($denominator <= 0) {
            return 0.0;
        }

        $current = collect($meal['items'])->firstWhere('food_id', $foodId);

        return $this->composition->normalizeQuantity($food, $numerator / $denominator, $current['role'] ?? null);
    }

    private function replacementKeepsMealCoherent(array $meal, int $foodId, Alimento $replacement): bool
    {
        $current = collect($meal['items'])->firstWhere('food_id', $foodId);
        if (! $current || ! in_array($current['role'] ?? '', $this->composition->rolesForFood($replacement), true)) {
            return false;
        }
        if (Str::contains(Str::lower(Str::ascii($replacement->descricao)), ['charque', 'carne seca'])) {
            $roles = collect($meal['items'])->pluck('role');
            if (! $roles->contains('prato_leguminosa') || ! $roles->contains('prato_vegetal')) {
                return false;
            }
        }
        if (! $this->isBreakfastMeal($meal)) {
            return true;
        }
        $families = collect($meal['items'])
            ->filter(fn ($item) => (int) $item['food_id'] !== $foodId)
            ->map(fn ($item) => $this->foodFamilyFromItem($item))
            ->filter()
            ->push($this->composition->foodFamily($replacement))
            ->values();

        $counts = array_count_values($families->all());
        foreach (['pao_torrada', 'iogurte', 'queijo', 'ovo', 'leite_liquido'] as $family) {
            if (($counts[$family] ?? 0) > 1) {
                return false;
            }
        }

        return true;
    }

    private function isBreakfastMeal(array $meal): bool
    {
        return Str::contains(Str::lower(Str::ascii((string) ($meal['descricao'] ?? ''))), 'cafe');
    }

    private function foodFamilyFromItem(array $item): ?string
    {
        $name = Str::lower(Str::ascii((string) ($item['descricao'] ?? '')));
        $role = $item['role'] ?? null;

        if (in_array($role, ['prato_vegetal', 'prato_leguminosa'], true)) {
            return $role === 'prato_vegetal' ? 'vegetal' : 'leguminosa';
        }

        return match (true) {
            str_contains($name, 'leite') && Str::contains($name, [' po', 'po ', ' em po']) => 'leite_po',
            str_contains($name, 'iogurte') => 'iogurte',
            str_contains($name, 'leite') => 'leite_liquido',
            Str::contains($name, ['ovo', 'omelete']) => 'ovo',
            Str::contains($name, ['queijo', 'requeijao', 'ricota', 'tofu']) => 'queijo',
            Str::contains($name, ['pao', 'torrada']) => 'pao_torrada',
            str_contains($name, 'tapioca') => 'tapioca',
            Str::contains($name, ['aveia', 'cereal', 'granola']) => 'aveia_cereal',
            $role === 'fruta_lanche' => 'fruta',
            default => null,
        };
    }

    private function validatedGeneration(array $target, array $preferences, Collection $foods, Collection $definitions, ?string $instruction): array
    {
        $this->assertCandidateCoverage($foods, $definitions, $preferences);
        $lastException = null;
        // A IA recebe uma única oportunidade curta. Caso a resposta não passe nas
        // regras do catálogo, o servidor entrega a composição determinística em vez
        // de reenviar três prompts grandes e deixar o usuário esperando.
        for ($attempt = 0; $attempt < 1; $attempt++) {
            $attemptPreferences = [
                ...$preferences,
                'generation_seed' => Str::uuid()->toString(),
                ...($attempt === 0 ? [] : ['internal_retry' => 'A resposta anterior foi recusada porque não respeitou a composição ou a margem nutricional. Mantenha a estrutura do prato, escolha outros candidatos se necessário e recalcule cada quantidade pela porção base.']),
            ];
            try {
                return $this->validatePlan($this->generate($target, $attemptPreferences, $foods, $definitions, $instruction), $foods, $target, $definitions, $preferences);
            } catch (ValidationException $exception) {
                $lastException = $exception;
            }
        }
        $fallback = $this->deterministicGeneration($target, $preferences, $foods, $definitions);
        if ($fallback) {
            Log::info('meal_plan.catalog_fallback.day_used', ['reason' => $lastException?->getMessage()]);

            return $fallback;
        }

        throw $lastException;
    }

    private function generate(array $target, array $preferences, Collection $foods, Collection $definitions, ?string $instruction): array
    {
        $context = ['papel' => 'Você atua como nutricionista montando planos alimentares brasileiros realistas, coesos e aplicáveis no dia a dia. Não monte combinações apenas para bater macros e não dê aconselhamento médico individualizado.', 'meta_do_dia' => $target, 'objetivo' => $preferences['objective'] ?? null, 'preferencias' => collect($preferences)->except(['excluded_food_ids', 'included_food_ids', 'objective'])->all(), 'instrucao_de_troca' => $instruction, 'regras' => ['Use apenas IDs de candidatos por papel culinário.', 'Cada item deve trazer food_id, role e quantity_g.', 'Cada refeição deve fazer sentido culinário, cultural e nutricionalmente.', 'No café da manhã, use combinações naturais como ovos + pão/tapioca + fruta, iogurte + fruta + aveia, pão + queijo + ovo, tapioca + proteína + fruta ou vitamina com fruta + leite/iogurte + aveia.', 'No café da manhã, não use dois tipos de leite em pó; leite em pó não deve ser escolha recorrente nem padrão para fechar macros.', 'Varie a estrutura do café da manhã entre gerações quando houver candidatos compatíveis.', 'Onívora permite vegetais, frutas, ovos, laticínios, carnes, cereais e leguminosas compatíveis; não trate onívora como restrição.', 'Ovos, frutas, iogurte, leite líquido, aveia, tapioca, pães e queijos são opções válidas de café da manhã quando aparecem como candidatos compatíveis.', 'Almoço e jantar precisam manter a estrutura de prato e incluir exatamente um item com role prato_vegetal.', 'Evite os alimentos indicados como preferência de alternativa quando houver opções equivalentes.', 'Não misture fruta, conservas, pães e queijo em almoço/jantar sem a estrutura de prato exigida.', 'Antes de responder, calcule cada porção pelos nutrientes da quantidade base e confira a meta de cada refeição.', 'Respeite os componentes obrigatórios. A refeição pode usar margem moderada; o dia precisa fechar em calorias ±8%, proteína -10%/+25%, carboidratos ±15% e gorduras ±15%.'], 'refeicoes' => $definitions->map(fn ($definition) => [...$definition, 'candidatos_por_papel' => $this->composition->candidatesByRole($foods, $definition)->map(fn ($items) => $this->foodData($items))->all()])->values()->all()];

        $context['papel'] = 'Você atua como nutricionista montando planos alimentares brasileiros realistas, coesos e aplicáveis no dia a dia. Primeiro escolha a estrutura culinária da refeição; somente depois ajuste as porções. Nunca forme uma lista aleatória apenas para bater macros.';
        $context['preferencias'] = collect($preferences)->except(['excluded_food_ids', 'included_food_ids', 'objective', 'internal_retry', 'generation_seed'])->all();
        $includedFoods = $foods->whereIn('id', $preferences['included_food_ids'] ?? []);
        $context['alimentos_obrigatorios'] = $this->foodData($includedFoods);
        $context['regras'] = [
            'Use somente IDs de candidatos por papel culinário. Eles já foram filtrados por consumo direto, preparo e porção realista.',
            'Cada item deve trazer food_id, role e quantity_g, respeitando a porção mínima, máxima e o incremento do candidato.',
            'Monte primeiro uma refeição brasileira que possa ser servida; ajuste macros pelas porções sem destruir a coerência culinária.',
            'Café da manhã: base + proteína + fruta; varie entre ovos com pão/tapioca e fruta, iogurte com fruta e aveia, ou pão, queijo e ovo.',
            'Almoço: exatamente proteína + carboidrato principal + leguminosa + vegetal. Jantar: proteína + carboidrato principal + vegetal, com leguminosa quando completar naturalmente o prato.',
            'Lanches devem ser combinações simples: fruta com iogurte/aveia, sanduíche com proteína, castanhas ou vitamina coerente.',
            'Não use alimentos crus que exigem cozimento, farinhas/amidos isolados, frituras como carboidrato principal, nem porções irrelevantes como 3 g de pão.',
            'Ingredientes regionais como charque e farinhas só entram se formarem um prato completo e compatível; nunca como atalho para macro.',
            'Onívora permite vegetais, frutas, ovos, laticínios, carnes, cereais e leguminosas. O estilo orienta apenas praticidade, preparo e custo entre opções que já são coerentes.',
            'Evite repetir o mesmo alimento em várias refeições se houver equivalente disponível. Aproximar macros dentro da margem é suficiente.',
            'Os alimentos listados em alimentos_obrigatorios devem aparecer no plano, um em cada refeição diferente, no papel culinário mais coerente disponível para eles; o servidor garante essa inclusão de forma determinística, independentemente da resposta da IA.',
            $this->languageInstruction('O campo summary do plano e o campo explanation de cada refeição devem ser escritos em'),
        ];
        $context['refeicoes'] = $definitions->map(fn ($definition) => [
            ...$definition,
            'candidatos_por_papel' => $this->composition->candidatesByRole($foods, $definition, $preferences)
                ->map(fn ($items, $role) => $this->foodData($items, $role))
                ->all(),
        ])->values()->all();

        return $this->ask('plano_alimentar', $context, $this->planSchema($definitions->count()));
    }

    private function deterministicGeneration(array $target, array $preferences, Collection $foods, Collection $definitions): ?array
    {
        $foodById = $foods->keyBy('id');
        $meals = [];
        foreach ($definitions as $definition) {
            $items = $this->fallbackItems($definition, $foods, $foodById);
            if (! $items) {
                return null;
            }
            $totals = $this->macroCalculator->sum($items->pluck('macros')->all());
            $meals[] = [
                'position' => $definition['position'],
                'descricao' => $definition['descricao'],
                'horario' => $definition['horario'],
                'target' => $definition['target'],
                'totals' => $totals,
                'explanation' => __('messages.meal_plan.catalog_fallback_explanation'),
                'items' => $items->all(),
            ];
        }
        $meals = $this->enforceIncludedFoods($meals, $definitions, $foods, $preferences);
        $totals = $this->macroCalculator->sum(array_column($meals, 'totals'));
        if (! $this->macroCalculator->withinTarget($target, $totals, 'day')) {
            return null;
        }

        return [
            'preferences' => $preferences,
            'target' => $target,
            'totals' => $totals,
            'within_target' => true,
            'warning' => null,
            'summary' => __('messages.meal_plan.catalog_fallback_summary'),
            'meals' => $meals,
        ];
    }

    /**
     * As regras operacionais do prompt (estrutura culinária, composição,
     * porções) ficam em português — é o que orienta a IA na tarefa, não
     * texto exibido ao usuário, então não precisa mudar com o locale. Só o
     * texto livre que a IA escreve para o usuário (summary, explanation,
     * reason) precisa sair no idioma ativo (`app()->getLocale()`) — esta
     * instrução é o único ponto que carrega essa informação pro prompt.
     */
    private function languageInstruction(string $prefix): string
    {
        $idioma = app()->getLocale() === 'en-US' ? 'inglês (EUA)' : 'português do Brasil';

        return "{$prefix} {$idioma}.";
    }

    private function ask(string $task, array $context, array $schema): array
    {
        if (! config('gemini.enabled') || ! config('gemini.api_key')) {
            throw ValidationException::withMessages(['ai' => __('messages.meal_plan.ai_not_configured')]);
        }
        $started = microtime(true);
        try {
            $response = Http::acceptJson()->withHeaders(['x-goog-api-key' => config('gemini.api_key')])->timeout(min((int) config('gemini.timeout'), 10))->post(config('gemini.endpoint'), ['model' => config('gemini.model'), 'input' => json_encode(['task' => $task, ...$context], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'response_format' => [['type' => 'text', 'mime_type' => 'application/json', 'schema' => $schema]]])->throw()->json();
            $step = collect($response['steps'] ?? [])->reverse()->first(fn ($item) => ($item['type'] ?? null) === 'model_output');
            $raw = data_get($response, 'output_text') ?? data_get($step, 'content.0.text');
            if (! is_string($raw)) {
                throw new \RuntimeException('Gemini não retornou conteúdo estruturado.');
            }
            Log::info('meal_plan.ai.generated', ['provider' => 'gemini', 'task' => $task, 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);

            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            Log::warning('meal_plan.ai.failed', ['provider' => 'gemini', 'task' => $task, 'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'error' => $exception->getMessage()]);
            throw ValidationException::withMessages(['ai' => __('messages.meal_plan.ai_generation_failed')]);
        }
    }

    private function validatePlan(array $answer, Collection $foods, array $target, Collection $definitions, array $preferences): array
    {
        if (! is_string($answer['summary'] ?? null) || ! is_array($answer['meals'] ?? null) || count($answer['meals']) !== $definitions->count()) {
            throw ValidationException::withMessages(['ai' => __('messages.meal_plan.ai_incomplete_plan')]);
        }
        $foodById = $foods->keyBy('id');
        $meals = [];
        foreach ($definitions->values() as $index => $definition) {
            $aiMeal = $answer['meals'][$index] ?? null;
            if (! is_array($aiMeal) || (int) ($aiMeal['position'] ?? -1) !== $definition['position'] || ! is_array($aiMeal['items'] ?? null)) {
                throw ValidationException::withMessages(['ai' => __('messages.meal_plan.ai_invalid_meal')]);
            }
            $this->composition->validate($aiMeal['items'], $definition, $foods);
            $ids = [];
            $items = collect($aiMeal['items'])->map(function ($item) use ($foodById, &$ids) {
                $food = $foodById->get((int) ($item['food_id'] ?? 0));
                $quantity = (float) ($item['quantity_g'] ?? 0);
                if (! $food || in_array($food->id, $ids, true) || $quantity < 1 || $quantity > 800) {
                    throw ValidationException::withMessages(['ai' => __('messages.meal_plan.ai_invalid_food_or_portion')]);
                }
                $ids[] = $food->id;

                return ['food_id' => $food->id, 'descricao' => $food->descricao, 'descricao_exibicao' => $food->nome_exibicao, 'detalhe_exibicao' => $food->detalhe_exibicao, 'role' => $item['role'], 'quantity' => round($quantity, 1), 'macros' => $this->macroCalculator->foodMacros($food, $quantity)];
            })->values();
            // A IA define a combinação; o servidor normaliza porções realistas antes de validar macros.
            $items = $this->rebalanceItems($items, $definition['target'], $foodById, $definition);
            $totals = $this->macroCalculator->sum($items->pluck('macros')->all());
            if (! $this->macroCalculator->withinTarget($definition['target'], $totals)) {
                $fallback = $this->fallbackItems($definition, $foods, $foodById);
                if ($fallback) {
                    $items = $fallback;
                    $totals = $this->macroCalculator->sum($items->pluck('macros')->all());
                    Log::info('meal_plan.catalog_fallback.used', ['position' => $definition['position']]);
                }
            }
            if (! $this->itemsHaveRealisticPortions($items, $foodById)) {
                throw ValidationException::withMessages(['ai' => __('messages.meal_plan.ai_unrealistic_portion')]);
            }
            if (! $this->macroCalculator->withinTarget($definition['target'], $totals)) {
                // A distribuição entre refeições é uma referência culinária, não uma
                // exigência rígida de macro. Exigir que toda refeição feche todos os
                // macros inviabiliza combinações perfeitamente saudáveis (por exemplo,
                // café da manhã sem glúten). A aprovação nutricional obrigatória é do
                // dia completo, validada abaixo com a tolerância diária.
                Log::info('meal_plan.ai.meal_target_deviation', ['position' => $definition['position'], 'target' => $definition['target'], 'totals' => $totals]);
            }
            $meals[] = ['position' => $definition['position'], 'descricao' => $definition['descricao'], 'horario' => $definition['horario'], 'target' => $definition['target'], 'totals' => $totals, 'explanation' => Str::limit(strip_tags((string) ($aiMeal['explanation'] ?? '')), 220, ''), 'items' => $items->all()];
        }
        $meals = $this->enforceIncludedFoods($meals, $definitions, $foods, $preferences);
        $totals = $this->macroCalculator->sum(array_column($meals, 'totals'));
        $scope = $definitions->count() === 1 ? 'meal' : 'day';
        if (! $this->macroCalculator->withinTarget($target, $totals, $scope)) {
            throw ValidationException::withMessages(['ai' => __('messages.meal_plan.ai_day_target_miss')]);
        }

        return ['preferences' => $preferences, 'target' => $target, 'totals' => $totals, 'within_target' => true, 'warning' => null, 'summary' => Str::limit(strip_tags($answer['summary']), 300, ''), 'meals' => $meals];
    }

    /**
     * Garantia determinística de `included_food_ids`: não confia que a IA tenha obedecido a
     * regra do prompt. Para cada obrigatório ainda ausente do plano, encaixa no servidor —
     * numa refeição com papel livre (adiciona) ou substituindo um item não-obrigatório que
     * ocupe um papel compatível (troca) —, escolhendo entre as refeições candidatas a que tem
     * o `targetScore()` mais baixo (mais folga para absorver o novo item). Nunca re-roda
     * `MealCompositionService::validate()`: o papel já vem do template legítimo da própria
     * refeição, só troca qual alimento o ocupa.
     *
     * @param  list<array<string, mixed>>  $meals
     * @return list<array<string, mixed>>
     */
    private function enforceIncludedFoods(array $meals, Collection $definitions, Collection $foods, array $preferences): array
    {
        $includedIds = collect($preferences['included_food_ids'] ?? [])->map(fn ($id) => (int) $id)->values();
        if ($includedIds->isEmpty()) {
            return $meals;
        }
        $foodById = $foods->keyBy('id');
        $definitionsByIndex = $definitions->values();

        foreach ($includedIds as $includedId) {
            $alreadyPresent = collect($meals)->contains(fn (array $meal) => collect($meal['items'])->contains(fn (array $item) => (int) $item['food_id'] === $includedId));
            if ($alreadyPresent) {
                continue;
            }
            $food = $foodById->get($includedId);
            if (! $food) {
                // Defensivo: assertIncludedFoodsEligible() já deveria ter barrado isso em preview().
                throw ValidationException::withMessages(['ai' => __('messages.meal_plan.included_food_could_not_fit')]);
            }
            $roles = $this->composition->rolesForFood($food);

            $candidateIndexes = [];
            foreach ($meals as $index => $meal) {
                $definition = $definitionsByIndex->get($index);
                if (! $definition || empty(array_intersect($roles, $this->definitionRoles($definition)))) {
                    continue;
                }
                $candidateIndexes[] = $index;
            }
            if (empty($candidateIndexes)) {
                throw ValidationException::withMessages(['ai' => __('messages.meal_plan.included_food_could_not_fit')]);
            }
            usort($candidateIndexes, fn ($a, $b) => $this->targetScore($meals[$a]['target'], $meals[$a]['totals']) <=> $this->targetScore($meals[$b]['target'], $meals[$b]['totals']));

            $applied = false;
            foreach ($candidateIndexes as $index) {
                $meal = $meals[$index];
                $definition = $definitionsByIndex->get($index);
                $definitionRoles = $this->definitionRoles($definition);
                $usableRoles = array_values(array_intersect($roles, $definitionRoles));
                $items = collect($meal['items'])->values();
                $occupiedRoles = $items->pluck('role')->all();

                $freeRole = collect($usableRoles)->first(fn (string $role) => ! in_array($role, $occupiedRoles, true) && $items->count() < $definition['composition']['max_items']);

                if ($freeRole !== null) {
                    $newItem = ['food_id' => $food->id, 'descricao' => $food->descricao, 'descricao_exibicao' => $food->nome_exibicao, 'detalhe_exibicao' => $food->detalhe_exibicao, 'role' => $freeRole, 'quantity' => 100.0, 'macros' => $this->macroCalculator->foodMacros($food, 100.0)];
                    $candidateItems = $items->push($newItem)->values();
                } else {
                    $replaceIndex = $items->search(fn (array $item) => in_array($item['role'], $usableRoles, true) && ! $includedIds->contains((int) $item['food_id']));
                    if ($replaceIndex === false) {
                        continue;
                    }
                    $newItem = ['food_id' => $food->id, 'descricao' => $food->descricao, 'descricao_exibicao' => $food->nome_exibicao, 'detalhe_exibicao' => $food->detalhe_exibicao, 'role' => $items[$replaceIndex]['role'], 'quantity' => 100.0, 'macros' => $this->macroCalculator->foodMacros($food, 100.0)];
                    $candidateItems = $items->map(fn (array $item, int $itemIndex) => $itemIndex === $replaceIndex ? $newItem : $item)->values();
                }

                $rebalanced = $this->rebalanceItems($candidateItems, $definition['target'], $foodById, $definition);
                if (! $this->itemsHaveRealisticPortions($rebalanced, $foodById)) {
                    continue;
                }
                $rebalancedTotals = $this->macroCalculator->sum($rebalanced->pluck('macros')->all());
                // Mesmas duas barreiras que o item da IA já passa nesta refeição (linha ~587) e
                // que `fallbackCandidates()` já usa pra aceitar um item não vindo da IA (linha
                // ~810, mesmo limiar de 50): a inclusão forçada não pode silenciosamente deixar
                // a refeição fora da própria meta, nem quebrar coerência culinária (ex. dois
                // itens da mesma família no café da manhã) só porque veio do servidor e não da
                // IA. Se falhar, tenta a próxima refeição candidata em vez de aceitar.
                if (! $this->macroCalculator->withinTarget($definition['target'], $rebalancedTotals)) {
                    continue;
                }
                if ($this->coherencePenalty($definition, $rebalanced) >= 50) {
                    continue;
                }
                $meals[$index]['items'] = $rebalanced->all();
                $meals[$index]['totals'] = $rebalancedTotals;
                $applied = true;
                break;
            }
            if (! $applied) {
                throw ValidationException::withMessages(['ai' => __('messages.meal_plan.included_food_could_not_fit')]);
            }
        }

        return $meals;
    }

    /** @return list<string> */
    private function definitionRoles(array $definition): array
    {
        return collect([
            ...(array) ($definition['composition']['required'] ?? []),
            ...(array) ($definition['composition']['optional'] ?? []),
            ...collect($definition['composition']['required_any'] ?? [])->flatten()->all(),
        ])->unique()->values()->all();
    }

    private function assertCandidateCoverage(Collection $foods, Collection $definitions, array $preferences): void
    {
        foreach ($definitions as $definition) {
            $candidates = $this->composition->candidatesByRole($foods, $definition, $preferences);
            foreach ($definition['composition']['required'] ?? [] as $role) {
                if (($candidates[$role] ?? collect())->isEmpty()) {
                    throw ValidationException::withMessages(['catalog' => __('messages.meal_plan.missing_meal_candidates')]);
                }
            }
            foreach ($definition['composition']['required_any'] ?? [] as $alternatives) {
                if (collect($alternatives)->every(fn ($role) => ($candidates[$role] ?? collect())->isEmpty())) {
                    throw ValidationException::withMessages(['catalog' => __('messages.meal_plan.missing_snack_candidates')]);
                }
            }
        }
    }

    /**
     * Mantém os alimentos e os papéis culinários escolhidos pela IA, mas ajusta
     * as porções no servidor quando o cálculo dela não fecha a meta da refeição.
     */
    private function rebalanceItems(Collection $items, array $target, Collection $foodById, array $definition): Collection
    {
        $keys = ['caloria', 'proteina', 'carbo', 'gordura'];
        $coefficients = $items->map(fn (array $item) => $this->macroCalculator->foodMacros($foodById->get($item['food_id']), 1));
        $quantities = $items->map(fn (array $item) => $this->composition->normalizeQuantity($foodById->get($item['food_id']), (float) $item['quantity'], $item['role'] ?? null))->values();

        for ($round = 0; $round < 24; $round++) {
            foreach ($items->keys() as $index) {
                $other = $this->macroCalculator->macros(0, 0, 0, 0);
                foreach ($items->keys() as $otherIndex) {
                    if ($otherIndex === $index) {
                        continue;
                    }
                    foreach ($keys as $key) {
                        $other[$key] += $coefficients[$otherIndex][$key] * $quantities[$otherIndex];
                    }
                }
                $numerator = 0.0;
                $denominator = 0.0;
                foreach ($keys as $key) {
                    if (($target[$key] ?? 0) <= 0) {
                        continue;
                    }
                    $weight = 1 / ($target[$key] * $target[$key]);
                    $numerator += $weight * $coefficients[$index][$key] * ($target[$key] - $other[$key]);
                    $denominator += $weight * $coefficients[$index][$key] * $coefficients[$index][$key];
                }
                if ($denominator > 0) {
                    $food = $foodById->get($items[$index]['food_id']);
                    $quantities[$index] = $this->composition->normalizeQuantity($food, $numerator / $denominator, $items[$index]['role'] ?? null);
                }
            }
        }

        return $items->map(function (array $item, int $index) use ($foodById, $quantities) {
            $quantity = $this->composition->normalizeQuantity($foodById->get($item['food_id']), $quantities[$index], $item['role'] ?? null);

            return [...$item, 'quantity' => $quantity, 'macros' => $this->macroCalculator->foodMacros($foodById->get($item['food_id']), $quantity)];
        })->values();
    }

    /** @return Collection<int, array<string, mixed>>|null */
    private function fallbackItems(array $definition, Collection $foods, Collection $foodById): ?Collection
    {
        $template = $definition['composition'];
        $baseSets = $template['required'] ?? $template['required_any'] ?? [];
        if (($template['required'] ?? []) !== []) {
            $baseSets = [$baseSets];
        }
        $roleSets = collect($baseSets)->flatMap(function (array $base) use ($template) {
            $sets = [array_values($base)];
            foreach ($template['optional'] ?? [] as $optional) {
                if (count($base) < $template['max_items']) {
                    $sets[] = [...$base, $optional];
                }
            }

            return $sets;
        })->unique(fn (array $roles) => implode('|', $roles))->values();

        $best = null;
        $bestScore = INF;
        foreach ($roleSets as $roles) {
            $lists = collect($roles)->map(fn (string $role) => $this->fallbackCandidates($foods, $role));
            if ($lists->contains(fn (Collection $items) => $items->isEmpty())) {
                continue;
            }
            $visit = null;
            $visit = function (int $index, array $selected) use (&$visit, $lists, $roles, $definition, $foodById, &$best, &$bestScore) {
                if ($index === count($roles)) {
                    if (count(array_unique(array_column($selected, 'food_id'))) !== count($selected)) {
                        return;
                    }
                    $items = collect($selected)->map(fn (array $item) => [...$item, 'quantity' => 100.0, 'macros' => $this->macroCalculator->foodMacros($foodById->get($item['food_id']), 100)])->values();
                    $items = $this->rebalanceItems($items, $definition['target'], $foodById, $definition);
                    $score = $this->targetScore($definition['target'], $this->macroCalculator->sum($items->pluck('macros')->all())) + $this->coherencePenalty($definition, $items);
                    if ($score < $bestScore) {
                        $bestScore = $score;
                        $best = $items;
                    }

                    return;
                }
                foreach ($lists[$index] as $food) {
                    $visit($index + 1, [...$selected, ['food_id' => $food->id, 'descricao' => $food->descricao, 'descricao_exibicao' => $food->nome_exibicao, 'detalhe_exibicao' => $food->detalhe_exibicao, 'role' => $roles[$index]]]);
                }
            };
            $visit(0, []);
        }

        // O melhor conjunto coerente é útil mesmo que uma refeição isolada não feche
        // todos os macros. A decisão final é feita no total diário em
        // deterministicGeneration(), onde os macros efetivamente importam.
        return $best && $this->coherencePenalty($definition, $best) < 50 ? $best : null;
    }

    private function fallbackCandidates(Collection $foods, string $role): Collection
    {
        return $foods
            ->filter(fn (Alimento $food) => in_array($role, $this->composition->rolesForFood($food), true))
            ->sortByDesc(function (Alimento $food) use ($role) {
                $family = $this->composition->foodFamily($food);
                $name = Str::lower(Str::ascii($food->descricao));
                $score = ($food->planTags->contains('slug', 'base_alimentar') ? 200 : 0);
                if (Str::contains($name, ['charque', 'carne seca', 'frito', 'embutido'])) {
                    $score -= 150;
                }

                return $score + match ($role) {
                    'cafe_proteina' => match ($family) {
                        'ovo' => 240, 'iogurte' => 220, 'queijo' => 180, 'leite_liquido' => 160, default => 0
                    },
                    'cafe_base' => match ($family) {
                        'aveia_cereal' => 230, 'tapioca' => 220, 'pao_torrada' => 210, 'cuscuz' => 200, default => 0
                    },
                    'fruta_lanche' => $family === 'fruta' ? 260 : 0,
                    'prato_proteina' => in_array($family, ['carne', 'peixe', 'ovo', 'proteina_vegetal'], true) ? 180 : 0,
                    'prato_base' => in_array($family, ['arroz', 'tuberculo', 'massa', 'cuscuz'], true) ? 170 : 0,
                    'prato_leguminosa' => $family === 'leguminosa' ? 170 : 0,
                    'prato_vegetal' => $family === 'vegetal' ? 170 : 0,
                    default => 0,
                };
            })->take(8)->values();
    }

    private function coherencePenalty(array $definition, Collection $items): float
    {
        $families = $items->map(fn (array $item) => $this->foodFamilyFromItem($item))->filter()->values();
        $counts = array_count_values($families->all());
        $penalty = 0.0;
        if (($definition['kind'] ?? null) === 'cafe') {
            foreach (['pao_torrada', 'iogurte', 'queijo', 'ovo', 'leite_liquido'] as $family) {
                if (($counts[$family] ?? 0) > 1) {
                    $penalty += 50.0;
                }
            }
            if (($counts['fruta'] ?? 0) === 0) {
                $penalty += 25.0;
            }
        }
        if (in_array($definition['kind'] ?? null, ['almoco', 'jantar'], true)) {
            if (($counts['vegetal'] ?? 0) === 0) {
                $penalty += 100.0;
            }
            if (($definition['kind'] ?? null) === 'almoco' && ($counts['leguminosa'] ?? 0) === 0) {
                $penalty += 100.0;
            }
        }

        return $penalty;
    }

    private function targetScore(array $target, array $totals): float
    {
        return collect(['caloria', 'proteina', 'carbo', 'gordura'])->sum(function (string $key) use ($target, $totals) {
            if (($target[$key] ?? 0) <= 0) {
                return 0;
            }

            return (($totals[$key] - $target[$key]) / $target[$key]) ** 2;
        });
    }

    private function itemsHaveRealisticPortions(Collection $items, Collection $foodById): bool
    {
        return $items->every(fn (array $item) => ($food = $foodById->get($item['food_id']))
            && $this->composition->quantityIsRealistic($food, (float) $item['quantity'], $item['role'] ?? null));
    }

    private function foodData(Collection $foods, ?string $role = null): array
    {
        return $foods->map(function (Alimento $food) use ($role) {
            $profile = $this->composition->foodProfile($food);

            return [
                'id' => $food->id,
                'descricao' => $food->descricao,
                'nome_exibicao' => $food->nome_exibicao,
                'qtd_base_g' => (float) $food->qtd,
                'calorias' => (float) $food->caloria,
                'proteina_g' => (float) $food->proteina,
                'carbo_g' => (float) $food->carbo,
                'gordura_g' => (float) $food->gordura,
                'tags' => $food->planTags->pluck('slug')->all(),
                'perfil_culinario' => [
                    ...collect($profile)->except('porcao')->all(),
                    'papel_solicitado' => $role,
                    'porcao' => $this->composition->portionRange($food, $role),
                ],
            ];
        })->values()->all();
    }

    private function planSchema(int $mealCount): array
    {
        return ['type' => 'object', 'properties' => ['summary' => ['type' => 'string'], 'meals' => ['type' => 'array', 'minItems' => $mealCount, 'maxItems' => $mealCount, 'items' => ['type' => 'object', 'properties' => ['position' => ['type' => 'integer'], 'explanation' => ['type' => 'string'], 'items' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 4, 'items' => ['type' => 'object', 'properties' => ['food_id' => ['type' => 'integer'], 'role' => ['type' => 'string'], 'quantity_g' => ['type' => 'number']], 'required' => ['food_id', 'role', 'quantity_g']]]], 'required' => ['position', 'explanation', 'items']]]], 'required' => ['summary', 'meals']];
    }

    private function suggestionSchema(): array
    {
        return ['type' => 'object', 'properties' => ['suggestions' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 5, 'items' => ['type' => 'object', 'properties' => ['food_id' => ['type' => 'integer'], 'quantity_g' => ['type' => 'number'], 'reason' => ['type' => 'string']], 'required' => ['food_id', 'quantity_g', 'reason']]]], 'required' => ['suggestions']];
    }
}

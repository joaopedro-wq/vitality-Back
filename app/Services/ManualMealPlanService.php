<?php

namespace App\Services;

use App\Models\Alimento;
use App\Models\MealPlanDraft;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Contraparte manual (sem IA) do `GeminiMealPlanService`: o usuário escolhe os
 * próprios alimentos/quantidades por refeição, o servidor só calcula macros e
 * monta o mesmo formato de payload de draft (para `MealPlanController::serializeDraft()`
 * e `GeminiMealPlanService::save()/update()` servirem sem alteração). Nunca chama Gemini.
 */
class ManualMealPlanService
{
    public function __construct(
        private readonly MealPlanMacroCalculator $macroCalculator,
        private readonly MealCompositionService $composition,
    ) {}

    public function preview(User $user, array $payload): MealPlanDraft
    {
        $preferences = [
            'meal_count' => $payload['meal_count'],
            'meal_times' => $payload['meal_times'],
            'style' => 'manual',
            'objective' => $user->objetivo,
        ];
        $target = $this->macroCalculator->resolveDailyTarget($user);

        $mealCount = (int) $payload['meal_count'];
        $meals = collect($payload['meals'])
            ->sortBy('position')
            ->map(fn (array $meal) => $this->buildMeal($meal, $mealCount))
            ->values()
            ->all();

        $totals = $this->macroCalculator->sum(collect($meals)->pluck('totals')->all());
        [$withinTarget, $warning] = $this->targetStatus($target, $totals);

        $planPayload = [
            'preferences' => $preferences,
            'target' => $target,
            'totals' => $totals,
            'within_target' => $withinTarget,
            'warning' => $warning,
            'summary' => __('messages.meal_plan.manual_summary'),
            'meals' => $meals,
        ];

        return MealPlanDraft::create([
            'user_id' => $user->id,
            'provider' => 'manual',
            'model' => null,
            'preferences' => $preferences,
            'payload' => $planPayload,
            'expires_at' => now()->addMinutes(config('gemini.draft_ttl_minutes')),
        ]);
    }

    public function updateMealItems(User $user, MealPlanDraft $draft, int $position, array $items): MealPlanDraft
    {
        $this->assertDraft($user, $draft);
        $payload = $draft->payload;
        $mealIndex = collect($payload['meals'])->search(fn ($meal) => (int) $meal['position'] === $position);
        if ($mealIndex === false) {
            throw ValidationException::withMessages(['position' => __('messages.invalid_meal')]);
        }
        $meal = $payload['meals'][$mealIndex];
        $meal['items'] = $this->buildItems($items);
        $meal['totals'] = $this->macroCalculator->sum(collect($meal['items'])->pluck('macros')->all());
        $payload['meals'][$mealIndex] = $meal;
        $payload = $this->recalculateDayTotals($payload);
        $draft->update(['previous_payload' => $draft->payload, 'payload' => $payload, 'expires_at' => now()->addMinutes(config('gemini.draft_ttl_minutes'))]);

        return $draft->fresh();
    }

    public function addMeal(User $user, MealPlanDraft $draft, array $meal): MealPlanDraft
    {
        $this->assertDraft($user, $draft);
        $payload = $draft->payload;
        $meals = collect($payload['meals']);
        if ($meals->contains(fn ($existing) => (int) $existing['position'] === (int) $meal['position'])) {
            throw ValidationException::withMessages(['position' => __('messages.meal_plan.meal_position_taken')]);
        }
        $mealCount = (int) ($payload['preferences']['meal_count'] ?? $meals->count() + 1);
        $meals->push($this->buildMeal($meal, $mealCount));
        $payload['meals'] = $meals->sortBy('position')->values()->all();
        $payload = $this->reindexPositions($payload);
        $payload = $this->recalculateDayTotals($payload);
        $draft->update(['previous_payload' => $draft->payload, 'payload' => $payload, 'expires_at' => now()->addMinutes(config('gemini.draft_ttl_minutes'))]);

        return $draft->fresh();
    }

    public function removeMeal(User $user, MealPlanDraft $draft, int $position): MealPlanDraft
    {
        $this->assertDraft($user, $draft);
        $payload = $draft->payload;
        $meals = collect($payload['meals']);
        if (! $meals->contains(fn ($existing) => (int) $existing['position'] === $position)) {
            throw ValidationException::withMessages(['position' => __('messages.invalid_meal')]);
        }
        $payload['meals'] = $meals->reject(fn ($existing) => (int) $existing['position'] === $position)->values()->all();
        $payload = $this->reindexPositions($payload);
        $payload = $this->recalculateDayTotals($payload);
        $draft->update(['previous_payload' => $draft->payload, 'payload' => $payload, 'expires_at' => now()->addMinutes(config('gemini.draft_ttl_minutes'))]);

        return $draft->fresh();
    }

    private function assertDraft(User $user, MealPlanDraft $draft): void
    {
        abort_unless($draft->user_id === $user->id, 404);
        if ($draft->expires_at->isPast()) {
            throw ValidationException::withMessages(['draft_id' => __('messages.preview_expired')]);
        }
    }

    /** Reindexa `position` das refeições em ordem 0..n-1, preservando a ordem atual. */
    private function reindexPositions(array $payload): array
    {
        $payload['meals'] = collect($payload['meals'])->values()->map(function (array $meal, int $index) {
            $meal['position'] = $index;

            return $meal;
        })->all();

        return $payload;
    }

    private function recalculateDayTotals(array $payload): array
    {
        $payload['totals'] = $this->macroCalculator->sum(collect($payload['meals'])->pluck('totals')->all());
        [$withinTarget, $warning] = $this->targetStatus($payload['target'], $payload['totals']);
        $payload['within_target'] = $withinTarget;
        $payload['warning'] = $warning;

        return $payload;
    }

    /** @return array{0: bool, 1: string|null} */
    private function targetStatus(array $target, array $totals): array
    {
        $withinTarget = $this->macroCalculator->withinTarget($target, $totals, 'day');

        return [$withinTarget, $withinTarget ? null : __('messages.meal_plan.manual_off_target')];
    }

    private function buildMeal(array $meal, int $mealCount): array
    {
        $position = (int) $meal['position'];
        $items = $this->buildItems($meal['items']);
        $totals = $this->macroCalculator->sum(collect($items)->pluck('macros')->all());
        $descricao = $meal['descricao'] ?? null;
        if (! $descricao) {
            $kind = $this->composition->kindForPosition($mealCount, $position);
            $descricao = $this->composition->defaultLabel($kind, $position);
        }

        return [
            'position' => $position,
            'descricao' => $descricao,
            'horario' => $meal['horario'],
            'target' => $totals,
            'totals' => $totals,
            'explanation' => null,
            'items' => $items,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function buildItems(array $items): array
    {
        $foodIds = collect($items)->pluck('food_id')->unique()->values();
        $foods = Alimento::query()->where('status', 'ativo')->whereIn('id', $foodIds)->get()->keyBy('id');
        if ($foods->count() !== $foodIds->count()) {
            $missing = $foodIds->diff($foods->keys())->values()->all();
            throw ValidationException::withMessages(['items' => __('messages.meal_plan.manual_invalid_food', ['ids' => implode(', ', $missing)])]);
        }

        $seen = [];

        return collect($items)->map(function (array $item) use ($foods, &$seen) {
            $foodId = (int) $item['food_id'];
            if (in_array($foodId, $seen, true)) {
                throw ValidationException::withMessages(['items' => __('messages.meal_plan.manual_duplicate_food')]);
            }
            $seen[] = $foodId;
            $food = $foods->get($foodId);
            $quantity = round((float) $item['quantity'], 1);

            return [
                'food_id' => $food->id,
                'descricao' => $food->descricao,
                'descricao_exibicao' => $food->nome_exibicao,
                'detalhe_exibicao' => $food->detalhe_exibicao,
                'role' => null,
                'quantity' => $quantity,
                'macros' => $this->macroCalculator->foodMacros($food, $quantity),
            ];
        })->values()->all();
    }
}

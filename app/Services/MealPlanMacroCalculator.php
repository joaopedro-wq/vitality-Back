<?php

namespace App\Services;

use App\Models\Alimento;
use App\Models\Meta_diaria;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Cálculo de macro compartilhado entre os provedores de plano alimentar
 * (Gemini e manual): normaliza a estrutura de macros, escala/soma porções e
 * decide se um total está dentro da tolerância da meta. Extraído de
 * `GeminiMealPlanService` (2026-08-20) para que `ManualMealPlanService` não
 * duplique a mesma matemática.
 */
class MealPlanMacroCalculator
{
    public const DAY_TOLERANCE = [
        'caloria' => ['min' => .92, 'max' => 1.08],
        'proteina' => ['min' => .90, 'max' => 1.25],
        'carbo' => ['min' => .85, 'max' => 1.15],
        'gordura' => ['min' => .80, 'max' => 1.15],
    ];

    public const MEAL_TOLERANCE = [
        'caloria' => ['min' => .80, 'max' => 1.20],
        'proteina' => ['min' => .80, 'max' => 1.35],
        'carbo' => ['min' => .75, 'max' => 1.25],
        'gordura' => ['min' => .25, 'max' => 1.30],
    ];

    public const SWAP_TOLERANCE = [
        'caloria' => ['min' => .70, 'max' => 1.30],
        'proteina' => ['min' => .65, 'max' => 1.60],
        'carbo' => ['min' => .65, 'max' => 1.35],
        'gordura' => ['min' => .25, 'max' => 1.65],
    ];

    public const SWAP_DAY_TOLERANCE = [
        'caloria' => ['min' => .85, 'max' => 1.15],
        'proteina' => ['min' => .85, 'max' => 1.30],
        'carbo' => ['min' => .80, 'max' => 1.20],
        'gordura' => ['min' => .60, 'max' => 1.35],
    ];

    /**
     * Meta diária vigente do usuário (vigente = `data` nula, ou a mais recente se não
     * houver — mesmo critério de "meta vigente" do resto do domínio) convertida para a
     * estrutura de macros do plano alimentar. Compartilhado por Gemini e manual: os dois
     * provedores de plano alimentar resolvem o alvo do dia do mesmo jeito.
     */
    public function resolveDailyTarget(User $user): array
    {
        $meta = Meta_diaria::query()->where('id_usuario', $user->id)->orderByRaw('case when data is null then 0 else 1 end')->orderByDesc('data')->first();
        if (! $meta) {
            throw ValidationException::withMessages(['meta' => __('messages.meal_plan.missing_daily_goal')]);
        }

        return $this->macros($meta->meta_calorias, $meta->meta_proteinas, $meta->meta_carboidratos, $meta->meta_gorduras);
    }

    public function macros(float $caloria, float $proteina, float $carbo, float $gordura): array
    {
        return ['caloria' => round($caloria, 3), 'proteina' => round($proteina, 3), 'carbo' => round($carbo, 3), 'gordura' => round($gordura, 3), 'quantidade' => 0.0];
    }

    public function scale(array $macros, float $factor): array
    {
        return collect($macros)->map(fn ($value, $key) => $key === 'quantidade' ? 0.0 : round($value * $factor, 3))->all();
    }

    public function sum(array $macros): array
    {
        $total = $this->macros(0, 0, 0, 0);
        foreach ($macros as $macro) {
            foreach ($total as $key => $value) {
                $total[$key] = round($value + ($macro[$key] ?? 0), 3);
            }
        }

        return $total;
    }

    public function delta(array $before, array $after): array
    {
        return collect($before)->map(fn ($value, $key) => $key === 'quantidade' ? 0 : round(($after[$key] ?? 0) - $value, 1))->all();
    }

    public function foodMacros(Alimento $food, float $quantity): array
    {
        return $this->scale($this->macros($food->caloria, $food->proteina, $food->carbo, $food->gordura), $quantity / max((float) $food->qtd, .001));
    }

    public function withinTarget(array $target, array $totals, string $scope = 'meal'): bool
    {
        $tolerances = match ($scope) {
            'day' => self::DAY_TOLERANCE,
            'swap' => self::SWAP_TOLERANCE,
            'swap_day' => self::SWAP_DAY_TOLERANCE,
            default => self::MEAL_TOLERANCE,
        };
        foreach ($tolerances as $key => $tolerance) {
            if (($target[$key] ?? 0) <= 0) {
                continue;
            }
            $ratio = ($totals[$key] ?? 0) / $target[$key];
            if ($ratio < $tolerance['min'] || $ratio > $tolerance['max']) {
                return false;
            }
        }

        return true;
    }
}

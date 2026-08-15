<?php

namespace App\Services;

use App\Models\Alimento;
use App\Models\MealPlan;
use App\Models\Meta_diaria;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Geração explicável: usa somente alimentos ativos e a meta confirmada do usuário. */
class MealPlanGenerator
{
    public function preview(User $user, array $preferences): array
    {
        $meta = Meta_diaria::query()->where('id_usuario', $user->id)
            ->orderByRaw('case when data is null then 0 else 1 end')->orderByDesc('data')->first();
        if (! $meta) {
            throw ValidationException::withMessages(['meta' => 'Defina sua meta diária antes de gerar um plano.']);
        }

        $target = $this->macros($meta->meta_calorias, $meta->meta_proteinas, $meta->meta_carboidratos, $meta->meta_gorduras);
        $foods = Alimento::query()->where('status', 'ativo')->whereNotIn('id', $preferences['excluded_food_ids'] ?? [])
            ->with('planTags')->get();
        if ($foods->count() < 3) {
            throw ValidationException::withMessages(['excluded_food_ids' => 'Não há alimentos suficientes no catálogo com essas preferências.']);
        }

        $warning = null;
        $styled = $foods->filter(fn (Alimento $food) => $food->planTags->contains('slug', $preferences['style']));
        if ($styled->count() >= 6) {
            $foods = $styled->values();
        } else {
            $warning = 'O catálogo ainda tem poucos alimentos classificados para este estilo; usamos alternativas ativas compatíveis.';
        }

        $count = (int) $preferences['meal_count'];
        $ratios = match ($count) {
            3 => [0.25, 0.40, 0.35],
            4 => [0.25, 0.35, 0.15, 0.25],
            5 => [0.20, 0.10, 0.30, 0.15, 0.25],
        };
        $labels = match ($count) {
            3 => ['Café da manhã', 'Almoço', 'Jantar'],
            4 => ['Café da manhã', 'Almoço', 'Lanche', 'Jantar'],
            5 => ['Café da manhã', 'Lanche da manhã', 'Almoço', 'Lanche da tarde', 'Jantar'],
        };

        $used = [];
        $meals = [];
        foreach ($ratios as $position => $ratio) {
            $mealTarget = $this->scale($target, $ratio);
            $items = $this->itemsFor($foods, $mealTarget, $used);
            $items->each(fn (array $item) => $used[] = $item['food_id']);
            $totals = $this->sum($items->pluck('macros')->all());
            $meals[] = [
                'position' => $position,
                'descricao' => $labels[$position],
                'horario' => $preferences['meal_times'][$position],
                'target' => $mealTarget,
                'totals' => $totals,
                'items' => $items->values()->all(),
            ];
        }

        $totals = $this->sum(array_column($meals, 'totals'));
        $within = $this->withinTarget($target, $totals);
        if (! $within) {
            $warning = trim(($warning ? $warning.' ' : '').'Este é um ponto de partida: ajuste porções ou gere novamente para aproximar ainda mais da sua meta.');
        }
        return compact('preferences', 'target', 'totals', 'within', 'warning', 'meals');
    }

    public function save(User $user, string $title, array $draft): MealPlan
    {
        return DB::transaction(function () use ($user, $title, $draft) {
            $plan = MealPlan::create([
                'user_id' => $user->id, 'titulo' => $title, 'style' => $draft['preferences']['style'],
                'meal_count' => $draft['preferences']['meal_count'], 'preferences' => $draft['preferences'],
                'target' => $draft['target'], 'totals' => $draft['totals'], 'warning' => $draft['warning'],
            ]);
            foreach ($draft['meals'] as $meal) {
                $storedMeal = $plan->meals()->create(collect($meal)->only(['position', 'descricao', 'horario', 'target', 'totals'])->all());
                foreach ($meal['items'] as $item) {
                    $storedMeal->items()->create([
                        'food_id' => $item['food_id'], 'descricao_snapshot' => $item['descricao'],
                        'quantity' => $item['quantity'], 'macros' => $item['macros'],
                    ]);
                }
            }
            return $plan->load('meals.items');
        });
    }

    private function itemsFor(Collection $foods, array $target, array $used): Collection
    {
        $selected = collect();
        foreach ([['proteina', $target['proteina']], ['carbo', $target['carbo']], ['gordura', $target['gordura']]] as [$metric, $amount]) {
            if ($amount <= 0) continue;
            $food = $this->bestFood($foods, $metric, $used, $selected->pluck('id')->all());
            if (! $food || $this->perGram($food, $metric) <= 0) continue;
            $quantity = min(800, max(20, $amount / $this->perGram($food, $metric)));
            $selected->push(['id' => $food->id, 'food' => $food, 'quantity' => round($quantity, 1)]);
        }
        if ($selected->isEmpty()) {
            throw ValidationException::withMessages(['catalog' => 'Não foi possível montar uma combinação nutricional com o catálogo atual.']);
        }
        return $selected->map(function (array $selection) {
            /** @var Alimento $food */
            $food = $selection['food'];
            $quantity = $selection['quantity'];
            return ['food_id' => $food->id, 'descricao' => $food->descricao, 'quantity' => $quantity, 'macros' => $this->scale($this->macros($food->caloria, $food->proteina, $food->carbo, $food->gordura), $quantity / max((float) $food->qtd, 0.001))];
        });
    }

    /** Prioriza papel nutricional e base alimentar; o catálogo inteiro continua como fallback. */
    private function bestFood(Collection $foods, string $metric, array $used, array $selected): ?Alimento
    {
        $available = $foods->filter(fn (Alimento $food) => ! in_array($food->id, $used, true) && ! in_array($food->id, $selected, true));
        $role = ['proteina' => 'proteina', 'carbo' => 'carboidrato', 'gordura' => 'gordura'][$metric];
        $tiers = [
            $available->filter(fn (Alimento $food) => $food->planTags->contains('slug', 'base_alimentar') && $food->planTags->contains('slug', $role)),
            $available->filter(fn (Alimento $food) => $food->planTags->contains('slug', $role)),
            $available->filter(fn (Alimento $food) => $food->planTags->contains('slug', 'base_alimentar')),
            $available,
            $foods,
        ];
        foreach ($tiers as $tier) {
            $food = $tier->sortByDesc(fn (Alimento $candidate) => $this->perGram($candidate, $metric))->first();
            if ($food && $this->perGram($food, $metric) > 0) return $food;
        }
        return null;
    }

    private function perGram(Alimento $food, string $metric): float { return (float) $food->{$metric} / max((float) $food->qtd, 0.001); }
    private function macros(float $caloria, float $proteina, float $carbo, float $gordura): array { return ['caloria' => round($caloria, 3), 'proteina' => round($proteina, 3), 'carbo' => round($carbo, 3), 'gordura' => round($gordura, 3), 'quantidade' => 0.0]; }
    private function scale(array $macros, float $factor): array { return collect($macros)->map(fn ($value, $key) => $key === 'quantidade' ? 0.0 : round($value * $factor, 3))->all(); }
    private function sum(array $macros): array { $total = $this->macros(0, 0, 0, 0); foreach ($macros as $macro) foreach ($total as $key => $value) $total[$key] = round($value + ($macro[$key] ?? 0), 3); return $total; }
    private function withinTarget(array $target, array $totals): bool { foreach (['caloria' => .10, 'proteina' => .15, 'carbo' => .15, 'gordura' => .15] as $key => $tolerance) if ($target[$key] > 0 && abs($totals[$key] - $target[$key]) / $target[$key] > $tolerance) return false; return true; }
}

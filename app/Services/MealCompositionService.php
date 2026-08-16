<?php

namespace App\Services;

use App\Models\Alimento;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MealCompositionService
{
    public function definitions(array $preferences, array $target): Collection
    {
        $ratios = match ((int) $preferences['meal_count']) {
            3 => [.25, .40, .35],
            4 => [.25, .35, .15, .25],
            5 => [.20, .10, .30, .15, .25],
        };
        $labels = match ((int) $preferences['meal_count']) {
            3 => ['Café da manhã', 'Almoço', 'Jantar'],
            4 => ['Café da manhã', 'Almoço', 'Lanche', 'Jantar'],
            5 => ['Café da manhã', 'Lanche da manhã', 'Almoço', 'Lanche da tarde', 'Jantar'],
        };

        return collect($ratios)->map(function (float $ratio, int $position) use ($labels, $preferences, $target) {
            $kind = str_contains($labels[$position], 'Café') ? 'cafe' : (str_contains($labels[$position], 'Lanche') ? 'lanche' : 'prato');

            return [
                'position' => $position, 'descricao' => $labels[$position], 'horario' => $preferences['meal_times'][$position],
                'kind' => $kind, 'target' => $this->scale($target, $ratio), 'composition' => $this->template($kind),
            ];
        });
    }

    /** @return Collection<string, Collection<int, Alimento>> */
    public function template(string $kind): array
    {
        return match ($kind) {
            'cafe' => ['required' => ['cafe_base', 'cafe_proteina', 'fruta_lanche'], 'optional' => ['lanche_pratico'], 'max_items' => 4],
            'lanche' => ['required_any' => [['lanche_pratico', 'fruta_lanche'], ['cafe_proteina', 'cafe_base']], 'optional' => ['fruta_lanche', 'cafe_proteina', 'cafe_base'], 'max_items' => 3],
            default => ['required' => ['prato_proteina', 'prato_base', 'prato_vegetal'], 'optional' => ['prato_leguminosa', 'acompanhamento'], 'max_items' => 4],
        };
    }

    /** @return list<string> */
    public function rolesForFood(Alimento $food): array
    {
        return $food->planTags->pluck('slug')->intersect($this->allRoles())->values()->all();
    }

    public function candidatesByRole(Collection $foods, array $definition): Collection
    {
        $roles = collect([...(array) ($definition['composition']['required'] ?? []), ...(array) ($definition['composition']['optional'] ?? [])])
            ->merge(collect($definition['composition']['required_any'] ?? [])->flatten())->unique();

        return $roles->mapWithKeys(fn (string $role) => [$role => $foods->filter(fn (Alimento $food) => $food->planTags->contains('slug', $role))
            ->sortByDesc(fn (Alimento $food) => $this->candidateScore($food, $role, (string) ($definition['kind'] ?? '')))
            ->take(24)->values()]);
    }

    public function validate(array $items, array $definition, Collection $foods): void
    {
        $template = $definition['composition'];
        if (count($items) < 2 || count($items) > $template['max_items']) {
            throw ValidationException::withMessages(['ai' => 'A composição da refeição tem uma quantidade inválida de itens.']);
        }
        $foodById = $foods->keyBy('id');
        $allowedRoles = $this->candidatesByRole($foods, $definition)->keys()->all();
        $roles = [];
        foreach ($items as $item) {
            $food = $foodById->get((int) ($item['food_id'] ?? 0));
            $role = (string) ($item['role'] ?? '');
            if (! $food || ! in_array($role, $allowedRoles, true) || ! in_array($role, $this->rolesForFood($food), true) || in_array($role, $roles, true)) {
                throw ValidationException::withMessages(['ai' => 'A IA retornou uma composição culinária incompatível.']);
            }
            $roles[] = $role;
        }
        foreach ($template['required'] ?? [] as $role) {
            if (! in_array($role, $roles, true)) {
                throw ValidationException::withMessages(['ai' => 'A refeição não contém os componentes essenciais para este horário.']);
            }
        }
        foreach ($template['required_any'] ?? [] as $alternatives) {
            if (! collect($alternatives)->intersect($roles)->isNotEmpty()) {
                throw ValidationException::withMessages(['ai' => 'A refeição não contém uma combinação adequada para este horário.']);
            }
        }
        $this->ensureBreakfastCoherence($items, $definition, $foodById);
    }

    private function ensureBreakfastCoherence(array $items, array $definition, Collection $foodById): void
    {
        if (($definition['kind'] ?? null) === 'cafe') {
            $this->validateBreakfastCoherence($items, $foodById);
        }
    }

    public function foodFamily(Alimento $food): string
    {
        $name = $this->normalize($food->descricao);
        $group = $this->normalize((string) $food->grupo);

        return match (true) {
            str_contains($name, 'leite') && Str::contains($name, [' po', 'po ', ' em po', 'desnatado po', 'integral po']) => 'leite_po',
            str_contains($name, 'iogurte') => 'iogurte',
            str_contains($name, 'leite') && str_contains($group, 'leite') => 'leite_liquido',
            Str::contains($name, ['ovo', 'omelete']) || str_contains($group, 'ovos') => 'ovo',
            Str::contains($name, ['queijo', 'requeijao', 'ricota', 'tofu']) => 'queijo',
            Str::contains($name, ['pao', 'torrada']) => 'pao_torrada',
            str_contains($name, 'tapioca') => 'tapioca',
            Str::contains($name, ['aveia', 'cereal', 'granola']) => 'aveia_cereal',
            str_contains($group, 'frutas') || $food->planTags->contains('slug', 'fruta_lanche') => 'fruta',
            default => str_replace(' ', '_', $group ?: 'outros'),
        };
    }

    /** @return list<string> */
    private function allRoles(): array
    {
        return ['cafe_base', 'cafe_proteina', 'lanche_pratico', 'fruta_lanche', 'prato_proteina', 'prato_base', 'prato_leguminosa', 'prato_vegetal', 'acompanhamento'];
    }

    private function candidateScore(Alimento $food, string $role, string $kind): float
    {
        $score = ($food->planTags->contains('slug', 'base_alimentar') ? 1000 : 0) + ($food->planTags->contains('slug', 'caseiro') ? 100 : 0);
        if ($kind !== 'cafe') {
            return $score;
        }

        $family = $this->foodFamily($food);
        $name = $this->normalize($food->descricao);
        if ($this->isPoorBreakfastFood($food)) {
            $score -= 900;
        }
        if ($family === 'leite_po') {
            $score -= 650;
        }

        return $score + match ($role) {
            'fruta_lanche' => $family === 'fruta' ? 360 : 0,
            'cafe_proteina' => match ($family) {
                'ovo' => 360,
                'iogurte' => 330,
                'queijo' => 260,
                'leite_liquido' => 200,
                'leite_po' => -500,
                default => 0,
            },
            'cafe_base' => match (true) {
                $family === 'aveia_cereal' => 320,
                $family === 'tapioca' => 300,
                $family === 'pao_torrada' => 260,
                str_contains($name, 'cuscuz') => 220,
                default => 0,
            },
            'lanche_pratico' => match ($family) {
                'iogurte' => 260,
                'fruta' => 230,
                'leite_liquido' => 180,
                'aveia_cereal' => 140,
                'leite_po' => -450,
                default => 0,
            },
            default => 0,
        };
    }

    private function validateBreakfastCoherence(array $items, Collection $foodById): void
    {
        $families = [];
        foreach ($items as $item) {
            $food = $foodById->get((int) ($item['food_id'] ?? 0));
            if (! $food) {
                continue;
            }
            if ($this->isPoorBreakfastFood($food)) {
                throw ValidationException::withMessages(['ai' => 'A IA escolheu um alimento incoerente para o cafe da manha.']);
            }
            $families[] = $this->foodFamily($food);
        }

        $counts = array_count_values($families);
        foreach (['leite_po', 'pao_torrada', 'iogurte', 'queijo', 'ovo'] as $family) {
            if (($counts[$family] ?? 0) > 1) {
                throw ValidationException::withMessages(['ai' => 'A IA repetiu alimentos da mesma familia no cafe da manha.']);
            }
        }
    }

    private function isPoorBreakfastFood(Alimento $food): bool
    {
        $name = $this->normalize($food->descricao);

        return Str::contains($name, ['macarrao', 'pastel', 'maionese', 'doce', 'calda', 'xarope', 'chantilly', 'refrigerante']);
    }

    private function normalize(string $value): string
    {
        return Str::lower(Str::ascii($value));
    }

    private function scale(array $macros, float $factor): array
    {
        return collect($macros)->map(fn ($value, $key) => $key === 'quantidade' ? 0.0 : round($value * $factor, 3))->all();
    }
}

<?php

namespace App\Services;

use App\Models\Alimento;
use Illuminate\Support\Collection;
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
            'cafe' => ['required' => ['cafe_base', 'cafe_proteina'], 'optional' => ['fruta_lanche', 'lanche_pratico'], 'max_items' => 3],
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
            ->sortByDesc(fn (Alimento $food) => ($food->planTags->contains('slug', 'base_alimentar') ? 1000 : 0) + ($food->planTags->contains('slug', 'caseiro') ? 100 : 0))
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
    }

    /** @return list<string> */
    private function allRoles(): array
    {
        return ['cafe_base', 'cafe_proteina', 'lanche_pratico', 'fruta_lanche', 'prato_proteina', 'prato_base', 'prato_leguminosa', 'prato_vegetal', 'acompanhamento'];
    }

    private function scale(array $macros, float $factor): array
    {
        return collect($macros)->map(fn ($value, $key) => $key === 'quantidade' ? 0.0 : round($value * $factor, 3))->all();
    }
}

<?php

namespace App\Services;

use App\Models\Alimento;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;


class MealCompositionService
{
    public function __construct(private readonly MealFoodCatalogService $catalog) {}

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
            $label = $labels[$position];
            $kind = str_contains($label, 'Café') ? 'cafe' : (str_contains($label, 'Lanche') ? 'lanche' : ($label === 'Almoço' ? 'almoco' : 'jantar'));

            return [
                'position' => $position,
                'descricao' => $label,
                'horario' => $preferences['meal_times'][$position],
                'kind' => $kind,
                'target' => $this->scale($target, $ratio),
                'composition' => $this->template($kind),
            ];
        });
    }

    /** @return array{required?: list<string>, required_any?: list<list<string>>, optional: list<string>, max_items: int} */
    public function template(string $kind): array
    {
        return match ($kind) {
            'cafe' => ['required' => ['cafe_base', 'cafe_proteina', 'fruta_lanche'], 'optional' => ['lanche_pratico'], 'max_items' => 4],
            'lanche' => ['required_any' => [['fruta_lanche', 'lanche_pratico'], ['cafe_base', 'cafe_proteina']], 'optional' => ['fruta_lanche', 'lanche_pratico', 'cafe_proteina'], 'max_items' => 3],
            'almoco' => ['required' => ['prato_proteina', 'prato_base', 'prato_leguminosa', 'prato_vegetal'], 'optional' => [], 'max_items' => 4],
            'jantar' => ['required' => ['prato_proteina', 'prato_base', 'prato_vegetal'], 'optional' => ['prato_leguminosa'], 'max_items' => 4],
            default => throw new \InvalidArgumentException("Tipo de refeição desconhecido: {$kind}"),
        };
    }

    /** @return list<string> */
    public function rolesForFood(Alimento $food): array
    {
        return collect($this->allRoles())->filter(fn (string $role) => $this->catalog->supportsRole($food, $role))->values()->all();
    }

    /** @return Collection<string, Collection<int, Alimento>> */
    public function candidatesByRole(Collection $foods, array $definition, array $preferences = []): Collection
    {
        $roles = collect([...(array) ($definition['composition']['required'] ?? []), ...(array) ($definition['composition']['optional'] ?? [])])
            ->merge(collect($definition['composition']['required_any'] ?? [])->flatten())
            ->unique()
            ->values();

        return $roles->mapWithKeys(fn (string $role) => [$role => $foods
            ->filter(fn (Alimento $food) => $this->catalog->supportsRole($food, $role))
            ->sortByDesc(fn (Alimento $food) => $this->candidateScore($food, $role, (string) $definition['kind'], (string) ($preferences['style'] ?? 'rapido'), (string) ($preferences['generation_seed'] ?? '')))
            ->take(16)
            ->values()]);
    }

    public function validate(array $items, array $definition, Collection $foods): void
    {
        $template = $definition['composition'];
        if (count($items) < 2 || count($items) > $template['max_items']) {
            throw ValidationException::withMessages(['ai' => 'A composição da refeição tem uma quantidade inválida de itens.']);
        }
        $foodById = $foods->keyBy('id');
        $allowedRoles = collect([...(array) ($template['required'] ?? []), ...(array) ($template['optional'] ?? [])])
            ->merge(collect($template['required_any'] ?? [])->flatten())
            ->unique()
            ->all();
        $roles = [];
        foreach ($items as $item) {
            $food = $foodById->get((int) ($item['food_id'] ?? 0));
            $role = (string) ($item['role'] ?? '');
            if (! $food || ! in_array($role, $allowedRoles, true) || ! $this->catalog->supportsRole($food, $role) || in_array($role, $roles, true)) {
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

        $this->validateMealCoherence($items, $definition, $foodById);
    }

    public function foodFamily(Alimento $food): string
    {
        return $this->catalog->foodFamily($food);
    }

    /** @return array{min: float, max: float, step: float} */
    public function portionRange(Alimento $food, ?string $role = null): array
    {
        return $this->catalog->portionRange($food, $role);
    }

    public function normalizeQuantity(Alimento $food, float $quantity, ?string $role = null): float
    {
        return $this->catalog->normalizeQuantity($food, $quantity, $role);
    }

    public function quantityIsRealistic(Alimento $food, float $quantity, ?string $role = null): bool
    {
        return $this->catalog->quantityIsRealistic($food, $quantity, $role);
    }

    /** @return array<string, mixed> */
    public function foodProfile(Alimento $food): array
    {
        return $this->catalog->profile($food);
    }

    private function validateMealCoherence(array $items, array $definition, Collection $foodById): void
    {
        $kind = (string) ($definition['kind'] ?? '');
        $familiesByRole = [];
        foreach ($items as $item) {
            $food = $foodById->get((int) ($item['food_id'] ?? 0));
            if (! $food) continue;
            $profile = $this->catalog->profile($food);
            if (! $profile['adequado_para_consumo_direto']) {
                throw ValidationException::withMessages(['ai' => 'A IA escolheu um ingrediente cru ou que exige preparo para consumo direto.']);
            }
            $familiesByRole[(string) $item['role']] = $profile['familia'];
        }

        if ($kind === 'cafe') {
            $this->validateBreakfastCoherence($familiesByRole, $foodById, $items);
            return;
        }

        if (in_array($kind, ['almoco', 'jantar'], true)) {
            $protein = $this->foodForRole($items, $foodById, 'prato_proteina');
            if ($protein && Str::contains($this->normalize($protein->descricao), ['charque', 'carne seca']) && ! isset($familiesByRole['prato_leguminosa'])) {
                throw ValidationException::withMessages(['ai' => 'Charque precisa vir em um prato completo, com leguminosa e vegetal.']);
            }
        }
    }

    private function validateBreakfastCoherence(array $familiesByRole, Collection $foodById, array $items): void
    {
        $families = array_values($familiesByRole);
        foreach (['pao_torrada', 'iogurte', 'queijo', 'ovo', 'leite_liquido'] as $family) {
            if (count(array_filter($families, fn (string $value) => $value === $family)) > 1) {
                throw ValidationException::withMessages(['ai' => 'A IA repetiu alimentos da mesma família no café da manhã.']);
            }
        }
        foreach ($items as $item) {
            $food = $foodById->get((int) ($item['food_id'] ?? 0));
            if ($food && $this->isPoorBreakfastFood($food)) {
                throw ValidationException::withMessages(['ai' => 'A IA escolheu um alimento incoerente para o café da manhã.']);
            }
        }
    }

    private function foodForRole(array $items, Collection $foodById, string $role): ?Alimento
    {
        $item = collect($items)->firstWhere('role', $role);

        return $item ? $foodById->get((int) $item['food_id']) : null;
    }

    /** @return list<string> */
    private function allRoles(): array
    {
        return ['cafe_base', 'cafe_proteina', 'lanche_pratico', 'fruta_lanche', 'prato_proteina', 'prato_base', 'prato_leguminosa', 'prato_vegetal', 'acompanhamento'];
    }

    private function candidateScore(Alimento $food, string $role, string $kind, string $style, string $generationSeed): float
    {
        $family = $this->catalog->foodFamily($food);
        $name = $this->normalize($food->descricao);
        $score = ($food->planTags->contains('slug', 'base_alimentar') ? 300 : 0) + $this->catalog->scoreForStyle($food, $style);

        if (Str::contains($name, ['charque', 'carne seca', 'frito', 'embutido'])) $score -= 120;
        if ($kind === 'cafe') {
            if ($this->isPoorBreakfastFood($food)) $score -= 900;
            $score += match ($role) {
                'fruta_lanche' => $family === 'fruta' ? 500 : 0,
                'cafe_proteina' => match ($family) {
                    'ovo' => 440, 'iogurte' => 400, 'queijo' => 340, 'leite_liquido' => 260, default => 0,
                },
                'cafe_base' => match ($family) {
                    'aveia_cereal' => 400, 'tapioca' => 380, 'pao_torrada' => 360, 'cuscuz' => 340, default => 0,
                },
                'lanche_pratico' => match ($family) {
                    'iogurte' => 320, 'fruta' => 300, 'leite_liquido' => 260, 'aveia_cereal' => 200, default => 0,
                },
                default => 0,
            };
        }
        if ($kind === 'almoco' || $kind === 'jantar') {
            $score += match ($role) {
                'prato_proteina' => in_array($family, ['carne', 'peixe', 'ovo', 'proteina_vegetal'], true) ? 220 : 0,
                'prato_base' => in_array($family, ['arroz', 'tuberculo', 'massa', 'cuscuz'], true) ? 180 : 0,
                'prato_leguminosa' => $family === 'leguminosa' ? 180 : 0,
                'prato_vegetal' => $family === 'vegetal' ? 180 : 0,
                default => 0,
            };
        }

        // A variação só desempata candidatos com a mesma qualidade culinária; ela nunca supera regras de preparo ou papel.
        $varietyTieBreaker = $generationSeed === '' ? 0.0 : (crc32($generationSeed.'-'.$food->id) % 100) / 100;

        return $score + $varietyTieBreaker;
    }

    private function isPoorBreakfastFood(Alimento $food): bool
    {
        return Str::contains($this->normalize($food->descricao), ['macarrao', 'pastel', 'maionese', 'doce', 'calda', 'xarope', 'chantilly', 'refrigerante']);
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

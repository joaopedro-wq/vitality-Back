<?php

namespace App\Services;

use App\Models\Alimento;
use App\Models\FoodRestriction;
use Illuminate\Support\Collection;

/**
 * Decide se uma combinação de padrão alimentar e restrições possui os papéis
 * culinários necessários antes de acionar a IA. A checagem usa exatamente as
 * mesmas regras de compatibilidade do gerador, evitando opções aparentamente
 * disponíveis que nunca conseguiriam formar um dia completo.
 */
class MealPlanFeasibilityService
{
    public function __construct(private readonly MealCompositionService $composition) {}

    /** @return array{feasible:bool,candidate_count:int,missing_roles:list<string>,message:?string} */
    public function assess(array $preferences): array
    {
        $foods = $this->candidates($preferences);
        $definitions = $this->composition->definitions($preferences, [
            'caloria' => 0, 'proteina' => 0, 'carbo' => 0, 'gordura' => 0, 'quantidade' => 0,
        ]);
        $missingRoles = [];

        foreach ($definitions as $definition) {
            $candidates = $this->composition->candidatesByRole($foods, $definition, $preferences);
            foreach ($definition['composition']['required'] ?? [] as $role) {
                if (($candidates[$role] ?? collect())->isEmpty()) {
                    $missingRoles[] = $role;
                }
            }
            foreach ($definition['composition']['required_any'] ?? [] as $roles) {
                if (collect($roles)->every(fn (string $role) => ($candidates[$role] ?? collect())->isEmpty())) {
                    $missingRoles[] = implode('_ou_', $roles);
                }
            }
        }

        $missingRoles = array_values(array_unique($missingRoles));
        $feasible = $foods->count() >= 8 && $missingRoles === [];

        return [
            'feasible' => $feasible,
            'candidate_count' => $foods->count(),
            'missing_roles' => $missingRoles,
            'message' => $feasible ? null : __('messages.meal_plan.infeasible_preferences'),
        ];
    }

    /** @return array{current:array{feasible:bool,candidate_count:int,missing_roles:list<string>,message:?string},diet_options:array<string,array{feasible:bool,candidate_count:int,missing_roles:list<string>,message:?string}>,restrictions:list<array{slug:string,label:string,type:string,food_count:int,available:bool,unavailable_reason:?string}>} */
    public function describe(array $preferences): array
    {
        $current = $this->assess($preferences);
        $dietOptions = collect(['onivora', 'vegetariana'])->mapWithKeys(function (string $dietType) use ($preferences) {
            return [$dietType => $this->assess([...$preferences, 'diet_type' => $dietType])];
        })->all();

        $selected = collect($preferences['restriction_slugs'] ?? []);
        $restrictions = FoodRestriction::query()
            ->where('type', '!=', 'diet')
            ->where('slug', '!=', 'vegano')
            ->orderBy('type')
            ->orderBy('label')
            ->get()
            ->map(function (FoodRestriction $restriction) use ($preferences, $selected) {
                $slugs = $selected->contains($restriction->slug)
                    ? $selected->all()
                    : [...$selected->all(), $restriction->slug];
                $assessment = $this->assess([...$preferences, 'restriction_slugs' => $slugs]);

                return [
                    'slug' => $restriction->slug,
                    'label' => $restriction->label,
                    'type' => $restriction->type,
                    'food_count' => $assessment['candidate_count'],
                    'available' => $assessment['feasible'],
                    'unavailable_reason' => $assessment['message'],
                ];
            })
            ->values()
            ->all();

        return ['current' => $current, 'diet_options' => $dietOptions, 'restrictions' => $restrictions];
    }

    /** @return Collection<int, Alimento> */
    /** @return Collection<int, Alimento> */
    public function candidates(array $preferences): Collection
    {
        return Alimento::query()
            ->where('status', 'ativo')
            ->with(['planTags', 'planningProfile', 'restrictions'])
            ->whereNotIn('id', $preferences['excluded_food_ids'] ?? [])
            ->get()
            ->filter(fn (Alimento $food) => $this->supportsPreferences($food, $preferences))
            ->values();
    }

    private function supportsPreferences(Alimento $food, array $preferences): bool
    {
        $profile = $food->planningProfile;
        if (! $profile) {
            $required = [...(array) ($preferences['restriction_slugs'] ?? []), ...match ($preferences['diet_type'] ?? 'onivora') {
                'vegetariana' => ['vegetariano'], default => [],
            }];

            return collect($required)->every(fn (string $slug) => $food->restrictions->contains('slug', $slug));
        }
        if (($profile->diet_compatibility[$preferences['diet_type'] ?? 'onivora'] ?? 'desconhecido') !== 'compativel') {
            return false;
        }
        $keys = [
            'sem_lactose' => 'lactose',
            'sem_gluten' => 'gluten',
            'sem_ovo' => 'egg',
            'sem_amendoim' => 'peanut',
            'sem_crustaceos' => 'shellfish',
        ];
        foreach ($preferences['restriction_slugs'] ?? [] as $slug) {
            if (($profile->restriction_compatibility[$keys[$slug] ?? $slug] ?? 'desconhecido') !== 'compativel') {
                return false;
            }
        }

        return true;
    }
}

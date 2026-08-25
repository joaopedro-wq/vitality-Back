<?php

namespace Tests\Unit;

use App\Models\Alimento;
use App\Models\FoodPlanTag;
use App\Models\FoodPlanningProfile;
use App\Models\FoodRestriction;
use App\Services\MealPlanFeasibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MealPlanFeasibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_detects_when_a_restriction_removes_required_vegetarian_proteins(): void
    {
        FoodRestriction::firstOrCreate(['slug' => 'sem_ovo'], ['label' => 'Sem ovo', 'type' => 'allergy']);
        $this->food('Ovo cozido', ['cafe_proteina', 'prato_proteina'], 'ovo', ['egg' => 'incompativel']);
        $this->food('Aveia', ['cafe_base'], 'aveia_cereal');
        $this->food('Cuscuz', ['cafe_base'], 'cuscuz');
        $this->food('Banana', ['fruta_lanche', 'lanche_pratico'], 'fruta');
        $this->food('Maçã', ['fruta_lanche', 'lanche_pratico'], 'fruta');
        $this->food('Arroz', ['prato_base'], 'arroz');
        $this->food('Feijão', ['prato_leguminosa'], 'leguminosa');
        $this->food('Couve', ['prato_vegetal'], 'vegetal');
        $service = app(MealPlanFeasibilityService::class);

        $vegetarian = $service->assess($this->preferences());
        $withoutEgg = $service->assess([...$this->preferences(), 'restriction_slugs' => ['sem_ovo']]);

        $this->assertTrue($vegetarian['feasible']);
        $this->assertFalse($withoutEgg['feasible']);
        $this->assertContains('cafe_proteina', $withoutEgg['missing_roles']);
        $this->assertContains('prato_proteina', $withoutEgg['missing_roles']);
    }

    private function preferences(): array
    {
        return [
            'meal_count' => 3,
            'meal_times' => ['08:00', '12:30', '19:30'],
            'style' => 'caseiro',
            'diet_type' => 'vegetariana',
            'restriction_slugs' => [],
            'excluded_food_ids' => [],
        ];
    }

    private function food(string $name, array $tags, string $family, array $restrictions = []): void
    {
        $food = Alimento::create([
            'descricao' => $name,
            'nome_normalizado' => mb_strtolower($name),
            'fonte' => 'taco',
            'source_reference' => $name,
            'status' => 'ativo',
            'grupo' => 'Teste',
            'qtd' => 100,
            'proteina' => 12,
            'carbo' => 20,
            'gordura' => 5,
            'caloria' => 180,
        ]);
        $food->planTags()->sync(FoodPlanTag::query()->whereIn('slug', $tags)->pluck('id'));
        FoodPlanningProfile::create([
            'alimento_id' => $food->id,
            'family' => $family,
            'consumption_form' => 'pronto_para_consumo',
            'preparation' => 'preparo_domestico',
            'direct_consumption' => true,
            'support_ingredient' => false,
            'portion_min_g' => 50,
            'portion_max_g' => 250,
            'portion_step_g' => 10,
            'diet_compatibility' => ['onivora' => 'compativel', 'vegetariana' => 'compativel'],
            'restriction_compatibility' => [
                'gluten' => 'compativel',
                'lactose' => 'compativel',
                'egg' => 'compativel',
                'peanut' => 'compativel',
                'shellfish' => 'compativel',
                ...$restrictions,
            ],
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Alimento;
use App\Models\FoodPlanTag;
use App\Models\Meta_diaria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IncludedFoodTest extends TestCase
{
    use RefreshDatabase;

    private function food(string $name, float $protein = 0, float $carbs = 0, float $fat = 0, float $calories = 0, array $roles = []): Alimento
    {
        $food = Alimento::create([
            'descricao' => $name, 'nome_normalizado' => mb_strtolower($name), 'fonte' => 'taco', 'source_reference' => $name,
            'status' => 'ativo', 'grupo' => 'Teste', 'qtd' => 100, 'proteina' => $protein, 'carbo' => $carbs, 'gordura' => $fat, 'caloria' => $calories,
        ]);
        $food->planTags()->sync(FoodPlanTag::query()->whereIn('slug', $roles)->pluck('id'));

        return $food;
    }

    /** Igual ao fake do MealPlanApiTest, mas nunca escolhe o alimento indicado em $omitFoodId — simula a IA ignorando a obrigatoriedade. */
    private function fakeGeminiOmitting(?int $omitFoodId): void
    {
        Http::fake(['https://generativelanguage.googleapis.com/*' => function ($request) use ($omitFoodId) {
            $input = json_decode($request->data()['input'], true);
            if ($input['task'] === 'substituicao') {
                $answer = ['suggestions' => collect($input['candidatos'])->take(2)->map(fn ($food) => [
                    'food_id' => $food['id'], 'quantity_g' => $input['alimento_atual']['quantity'], 'reason' => 'Mantém o mesmo papel na refeição.',
                ])->all()];

                return Http::response(['steps' => [[
                    'type' => 'model_output', 'content' => [['type' => 'text', 'text' => json_encode($answer)]],
                ]]]);
            }

            $answer = [
                'summary' => 'Plano prático montado com alimentos do seu catálogo.',
                'meals' => collect($input['refeicoes'])->map(function ($meal) use ($omitFoodId) {
                    $candidate = function (string $role) use ($meal, $omitFoodId) {
                        return collect($meal['candidatos_por_papel'][$role] ?? [])->first(fn ($food) => $food['id'] !== $omitFoodId)
                            ?? collect($meal['candidatos_por_papel'][$role] ?? [])->first();
                    };
                    $items = $meal['kind'] === 'cafe'
                        ? [
                            ['food_id' => $candidate('cafe_proteina')['id'], 'role' => 'cafe_proteina', 'quantity_g' => $meal['target']['proteina'] * .65],
                            ['food_id' => $candidate('cafe_base')['id'], 'role' => 'cafe_base', 'quantity_g' => $meal['target']['carbo'] * .65],
                            ['food_id' => $candidate('fruta_lanche')['id'], 'role' => 'fruta_lanche', 'quantity_g' => 80],
                        ]
                        : [
                            ['food_id' => $candidate('prato_proteina')['id'], 'role' => 'prato_proteina', 'quantity_g' => $meal['target']['proteina'] * .65],
                            ['food_id' => $candidate('prato_base')['id'], 'role' => 'prato_base', 'quantity_g' => $meal['target']['carbo'] * .65],
                            ['food_id' => $candidate('prato_leguminosa')['id'], 'role' => 'prato_leguminosa', 'quantity_g' => 80],
                            ['food_id' => $candidate('prato_vegetal')['id'], 'role' => 'prato_vegetal', 'quantity_g' => 80],
                        ];

                    return ['position' => $meal['position'], 'explanation' => 'Combinação equilibrada para este horário.', 'items' => $items];
                })->all(),
            ];

            return Http::response(['steps' => [[
                'type' => 'model_output', 'content' => [['type' => 'text', 'text' => json_encode($answer)]],
            ]]]);
        }]);
    }

    private function seedCatalog(): array
    {
        $foods = [
            'ovos' => $this->food('Ovos', 13, 1, 10, 155, ['cafe_proteina']),
            'omelete' => $this->food('Omelete', 13, 1, 10, 155, ['cafe_proteina']),
            'pao' => $this->food('Pão integral', 9, 50, 5, 260, ['cafe_base']),
            'tapioca' => $this->food('Tapioca', 0, 50, 3, 210, ['cafe_base']),
            'banana' => $this->food('Banana', 1, 22, 0, 89, ['fruta_lanche', 'lanche_pratico']),
            'frango' => $this->food('Frango', 31, 0, 9, 195, ['prato_proteina']),
            'carne' => $this->food('Carne bovina', 26, 0, 12, 220, ['prato_proteina']),
            'peixe' => $this->food('Peixe', 26, 0, 9, 185, ['prato_proteina']),
            'arroz' => $this->food('Arroz cozido', 2, 28, 2, 138, ['prato_base']),
            'batata' => $this->food('Batata cozida', 2, 18, 2, 94, ['prato_base']),
            'feijao' => $this->food('Feijão cozido', 5, 14, 3, 96, ['prato_leguminosa']),
            'lentilha' => $this->food('Lentilha cozida', 5, 14, 3, 96, ['prato_leguminosa']),
            'brocolis' => $this->food('Brócolis', 3, 7, 1, 44, ['prato_vegetal']),
            'abobrinha' => $this->food('Abobrinha', 1, 3, 1, 29, ['prato_vegetal']),
        ];

        return $foods;
    }

    private function actingUser(): User
    {
        config()->set('gemini.api_key', 'test-key');
        $user = User::factory()->create();
        Meta_diaria::create(['id_usuario' => $user->id, 'data' => null, 'meta_calorias' => 2000, 'meta_proteinas' => 120, 'meta_carboidratos' => 220, 'meta_gorduras' => 65]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_enforce_included_foods_injects_the_food_even_when_the_ai_omits_it(): void
    {
        $this->actingUser();
        $foods = $this->seedCatalog();
        $this->fakeGeminiOmitting($foods['carne']->id);

        $payload = [
            'meal_count' => 3, 'meal_times' => ['08:00', '12:30', '19:30'], 'style' => 'rapido', 'diet_type' => 'onivora',
            'restriction_slugs' => [], 'excluded_food_ids' => [], 'included_food_ids' => [$foods['carne']->id],
        ];

        $draft = $this->postJson('/api/meal-plans/preview', $payload)->assertOk()->json('data');

        $foodIdsInPlan = collect($draft['meals'])->flatMap(fn ($meal) => collect($meal['items'])->pluck('food_id'))->all();
        $this->assertContains($foods['carne']->id, $foodIdsInPlan);
    }

    public function test_preview_returns_422_when_included_and_excluded_overlap(): void
    {
        $this->actingUser();
        $foods = $this->seedCatalog();

        $payload = [
            'meal_count' => 3, 'meal_times' => ['08:00', '12:30', '19:30'], 'style' => 'rapido', 'diet_type' => 'onivora',
            'restriction_slugs' => [], 'excluded_food_ids' => [$foods['frango']->id], 'included_food_ids' => [$foods['frango']->id],
        ];

        $this->postJson('/api/meal-plans/preview', $payload)->assertStatus(422)->assertJsonValidationErrors('included_food_ids.0');
    }

    public function test_preview_rejects_the_removed_vegan_diet_type(): void
    {
        $this->actingUser();
        $payload = [
            'meal_count' => 3, 'meal_times' => ['08:00', '12:30', '19:30'], 'style' => 'rapido', 'diet_type' => 'vegana',
            'restriction_slugs' => [], 'excluded_food_ids' => [], 'included_food_ids' => [],
        ];

        $this->postJson('/api/meal-plans/preview', $payload)->assertStatus(422)->assertJsonValidationErrors('diet_type');
    }

    public function test_replacing_a_locked_item_returns_an_error_instead_of_applying_the_swap(): void
    {
        $this->actingUser();
        $foods = $this->seedCatalog();
        $this->fakeGeminiOmitting(null);

        $payload = [
            'meal_count' => 3, 'meal_times' => ['08:00', '12:30', '19:30'], 'style' => 'rapido', 'diet_type' => 'onivora',
            'restriction_slugs' => [], 'excluded_food_ids' => [], 'included_food_ids' => [$foods['carne']->id],
        ];
        $draft = $this->postJson('/api/meal-plans/preview', $payload)->assertOk()->json('data');

        $lockedFoodId = $foods['carne']->id;
        $mealWithLockedItem = collect($draft['meals'])->first(fn ($meal) => collect($meal['items'])->contains('food_id', $lockedFoodId));
        $this->assertNotNull($mealWithLockedItem, 'O alimento obrigatório deveria estar em alguma refeição do plano.');

        $this->postJson("/api/meal-plans/preview/meal/{$mealWithLockedItem['position']}/item/{$lockedFoodId}/suggestions", ['draft_id' => $draft['draft_id']])
            ->assertStatus(422)->assertJsonValidationErrors('food_id');

        $this->postJson("/api/meal-plans/preview/meal/{$mealWithLockedItem['position']}/item/{$lockedFoodId}/replace", [
            'draft_id' => $draft['draft_id'], 'replacement_food_id' => $foods['peixe']->id, 'quantity' => 150,
        ])->assertStatus(422)->assertJsonValidationErrors('food_id');
    }
}

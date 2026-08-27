<?php

namespace Tests\Feature;

use App\Models\Alimento;
use App\Models\FoodPlanTag;
use App\Models\Meta_diaria;
use App\Models\User;
use App\Services\MealCompositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MealPlanApiTest extends TestCase
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

    private function fakeGemini(): void
    {
        Http::fake(['https://generativelanguage.googleapis.com/*' => function ($request) {
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
                'meals' => collect($input['refeicoes'])->map(function ($meal) {
                    $candidate = fn (string $role) => collect($meal['candidatos_por_papel'][$role] ?? [])->first();
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

    public function test_user_can_preview_swap_reorganize_save_and_edit_a_meal_plan(): void
    {
        config()->set('gemini.api_key', 'test-key');
        $user = User::factory()->create();
        Meta_diaria::create(['id_usuario' => $user->id, 'data' => null, 'meta_calorias' => 2000, 'meta_proteinas' => 120, 'meta_carboidratos' => 220, 'meta_gorduras' => 65]);
        $this->food('Ovos', 13, 1, 10, 155, ['cafe_proteina']);
        $this->food('Pão integral', 9, 50, 3, 253, ['cafe_base']);
        $this->food('Banana', 1, 22, 0, 89, ['fruta_lanche', 'lanche_pratico']);
        $this->food('Frango', 31, 0, 4, 165, ['prato_proteina']);
        $this->food('Arroz cozido', 2, 28, 0, 130, ['prato_base']);
        $this->food('Feijão cozido', 5, 14, 1, 76, ['prato_leguminosa']);
        $this->food('Brócolis', 3, 7, 0, 35, ['prato_vegetal']);
        $this->food('Omelete', 13, 1, 10, 155, ['cafe_proteina']);
        $this->food('Tapioca', 0, 50, 0, 200, ['cafe_base']);
        $this->food('Carne bovina', 26, 0, 10, 200, ['prato_proteina']);
        $this->food('Peixe', 26, 0, 4, 145, ['prato_proteina']);
        $this->food('Batata cozida', 2, 18, 0, 86, ['prato_base']);
        $this->food('Lentilha cozida', 5, 14, 1, 76, ['prato_leguminosa']);
        $this->food('Abobrinha', 1, 3, 0, 20, ['prato_vegetal']);
        $this->fakeGemini();
        Sanctum::actingAs($user);

        $payload = ['meal_count' => 3, 'meal_times' => ['08:00', '12:30', '19:30'], 'style' => 'rapido', 'diet_type' => 'onivora', 'restriction_slugs' => [], 'excluded_food_ids' => [], 'included_food_ids' => []];
        $this->postJson('/api/meal-plan-feasibility', $payload)
            ->assertOk()
            ->assertJsonPath('data.current.feasible', true);
        $draft = $this->postJson('/api/meal-plans/preview', $payload)->assertOk()->assertJsonCount(3, 'data.meals')->json('data');
        $this->assertArrayHasKey('draft_id', $draft);
        $protein = collect($draft['meals'][1]['items'])->firstWhere('role', 'prato_proteina');
        $suggestion = $this->postJson("/api/meal-plans/preview/meal/1/item/{$protein['food_id']}/suggestions", ['draft_id' => $draft['draft_id']])
            ->assertOk()->assertJsonCount(2, 'data')->json('data.0');
        $updated = $this->postJson("/api/meal-plans/preview/meal/1/item/{$protein['food_id']}/replace", [
            'draft_id' => $draft['draft_id'], 'replacement_food_id' => $suggestion['food_id'], 'quantity' => $suggestion['quantity'],
        ])->assertOk()->assertJsonPath('data.can_undo', true)->json('data');
        $this->postJson('/api/meal-plans/preview/undo', ['draft_id' => $updated['draft_id']])->assertOk()->assertJsonPath('data.can_undo', false);
        $originalMealFoodIds = collect($draft['meals'][1]['items'])->pluck('food_id')->all();
        $reorganized = $this->postJson('/api/meal-plans/preview/meal/1', ['draft_id' => $draft['draft_id']])->assertOk()->assertJsonPath('data.draft_id', $draft['draft_id'])->json('data');
        $this->assertEmpty(array_intersect($originalMealFoodIds, collect($reorganized['meals'][1]['items'])->pluck('food_id')->all()));
        $plan = $this->postJson('/api/meal-plans', ['titulo' => 'Minha rotina', 'draft_id' => $draft['draft_id']])
            ->assertCreated()->assertJsonPath('data.titulo', 'Minha rotina')->assertJsonCount(3, 'data.meals');
        $editDraft = $this->postJson('/api/meal-plans/'.$plan->json('data.id').'/edit-draft')->assertOk()->assertJsonCount(3, 'data.meals')->json('data');
        $this->putJson('/api/meal-plans/'.$plan->json('data.id'), ['titulo' => 'Minha rotina ajustada', 'draft_id' => $editDraft['draft_id']])
            ->assertOk()->assertJsonPath('data.titulo', 'Minha rotina ajustada')->assertJsonCount(3, 'data.meals');
        $this->assertDatabaseCount('meal_plans', 1);
        $this->assertDatabaseCount('registros', 0);
    }

    public function test_item_suggestions_fall_back_to_catalog_when_ai_is_unavailable_and_every_suggestion_is_applicable(): void
    {
        $user = User::factory()->create();
        Meta_diaria::create(['id_usuario' => $user->id, 'data' => null, 'meta_calorias' => 2000, 'meta_proteinas' => 120, 'meta_carboidratos' => 220, 'meta_gorduras' => 65]);
        $this->food('Ovos', 13, 1, 10, 155, ['cafe_proteina']);
        $this->food('Pão integral', 9, 50, 3, 253, ['cafe_base']);
        $this->food('Banana', 1, 22, 0, 89, ['fruta_lanche', 'lanche_pratico']);
        $this->food('Frango', 31, 0, 4, 165, ['prato_proteina']);
        $this->food('Arroz cozido', 2, 28, 0, 130, ['prato_base']);
        $this->food('Feijão cozido', 5, 14, 1, 76, ['prato_leguminosa']);
        $this->food('Brócolis', 3, 7, 0, 35, ['prato_vegetal']);
        $this->food('Omelete', 13, 1, 10, 155, ['cafe_proteina']);
        $this->food('Tapioca', 0, 50, 0, 200, ['cafe_base']);
        $this->food('Carne bovina', 26, 0, 10, 200, ['prato_proteina']);
        $this->food('Peixe', 26, 0, 4, 145, ['prato_proteina']);
        $this->food('Batata cozida', 2, 18, 0, 86, ['prato_base']);
        $this->food('Lentilha cozida', 5, 14, 1, 76, ['prato_leguminosa']);
        $this->food('Abobrinha', 1, 3, 0, 20, ['prato_vegetal']);
        Sanctum::actingAs($user);

        $payload = ['meal_count' => 3, 'meal_times' => ['08:00', '12:30', '19:30'], 'style' => 'rapido', 'diet_type' => 'onivora', 'restriction_slugs' => [], 'excluded_food_ids' => [], 'included_food_ids' => []];
        $draft = $this->postJson('/api/meal-plans/preview', $payload)->assertOk()->assertJsonCount(3, 'data.meals')->json('data');
        $protein = collect($draft['meals'][1]['items'])->firstWhere('role', 'prato_proteina');

        $suggestions = $this->postJson("/api/meal-plans/preview/meal/1/item/{$protein['food_id']}/suggestions", ['draft_id' => $draft['draft_id']])
            ->assertOk()->json('data');
        $this->assertNotEmpty($suggestions, 'Sem IA disponível, a rota deveria cair no catálogo em vez de falhar.');

        // Toda sugestão devolvida precisa ser de fato aplicável — sem isso, a pessoa escolhe uma
        // opção "válida" na lista e recebe erro só na confirmação (causa raiz nº2 do plano).
        // undo() entre cada tentativa porque replace() muda o item da posição — sem desfazer, a
        // 2ª sugestão tentaria substituir um food_id que não está mais na refeição.
        foreach ($suggestions as $suggestion) {
            $this->postJson("/api/meal-plans/preview/meal/1/item/{$protein['food_id']}/replace", [
                'draft_id' => $draft['draft_id'], 'replacement_food_id' => $suggestion['food_id'], 'quantity' => $suggestion['quantity'],
            ])->assertOk();
            $this->postJson('/api/meal-plans/preview/undo', ['draft_id' => $draft['draft_id']])->assertOk();
        }
    }

    public function test_profile_persists_restrictions_and_a_draft_cannot_be_saved_by_another_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $profile = ['meal_count' => 4, 'meal_times' => ['08:00', '12:30', '16:30', '20:00'], 'style' => 'caseiro', 'diet_type' => 'vegetariana', 'restriction_slugs' => [], 'excluded_food_ids' => [], 'included_food_ids' => []];
        $this->putJson('/api/meal-plan-profile', $profile)->assertOk()->assertJsonPath('data.diet_type', 'vegetariana');
        $this->getJson('/api/meal-plan-profile')->assertOk()->assertJsonPath('data.style', 'caseiro');

        $draft = \App\Models\MealPlanDraft::create(['user_id' => $user->id, 'preferences' => $profile, 'payload' => [], 'expires_at' => now()->addMinutes(10)]);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/meal-plans', ['titulo' => 'Invasão', 'draft_id' => $draft->id])->assertNotFound();
    }

    public function test_breakfast_rejects_duplicate_powdered_milk_and_accepts_natural_combo(): void
    {
        $composition = app(MealCompositionService::class);
        $definition = ['kind' => 'cafe', 'composition' => $composition->template('cafe')];
        $powderedSkim = $this->food('Leite de vaca desnatado em pó', 34, 53, 1, 360, ['cafe_proteina', 'lanche_pratico']);
        $powderedWhole = $this->food('Leite de vaca integral em pó', 25, 39, 27, 496, ['cafe_proteina', 'lanche_pratico']);
        $bread = $this->food('Pão integral', 9, 50, 3, 253, ['cafe_base']);
        $banana = $this->food('Banana', 1, 23, 0, 96, ['fruta_lanche', 'lanche_pratico']);
        $egg = $this->food('Ovo cozido', 13, 1, 9, 145, ['cafe_proteina']);
        $foods = Alimento::query()->with('planTags')->get();

        try {
            $composition->validate([
                ['food_id' => $powderedSkim->id, 'role' => 'cafe_proteina'],
                ['food_id' => $bread->id, 'role' => 'cafe_base'],
                ['food_id' => $banana->id, 'role' => 'fruta_lanche'],
                ['food_id' => $powderedWhole->id, 'role' => 'lanche_pratico'],
            ], $definition, $foods);
            $this->fail('Breakfast accepted duplicate powdered milk.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $composition->validate([
            ['food_id' => $egg->id, 'role' => 'cafe_proteina'],
            ['food_id' => $bread->id, 'role' => 'cafe_base'],
            ['food_id' => $banana->id, 'role' => 'fruta_lanche'],
        ], $definition, $foods);

        $this->assertTrue(true);
    }

    public function test_lunch_filters_raw_or_ingredient_foods_and_accepts_a_real_brazilian_plate(): void
    {
        $composition = app(MealCompositionService::class);
        $definition = ['kind' => 'almoco', 'composition' => $composition->template('almoco')];
        $frango = $this->food('Frango grelhado', 31, 0, 4, 165, ['prato_proteina']);
        $arroz = $this->food('Arroz cozido', 2, 28, 0, 130, ['prato_base']);
        $feijao = $this->food('Feijão carioca cozido', 5, 14, 1, 76, ['prato_leguminosa']);
        $couve = $this->food('Couve refogada', 2, 4, 0, 28, ['prato_vegetal']);
        $feijaoCru = $this->food('Feijão jalo cru', 20, 60, 1, 330, ['prato_leguminosa']);
        $mandiocaFrita = $this->food('Mandioca frita', 1, 45, 15, 330, ['prato_base']);
        $farinhaPuba = $this->food('Farinha de puba', 1, 85, 0, 360, ['prato_base']);
        $foods = Alimento::query()->with('planTags')->get();

        $this->assertNotContains('prato_leguminosa', $composition->rolesForFood($feijaoCru));
        $this->assertNotContains('prato_base', $composition->rolesForFood($mandiocaFrita));
        $this->assertNotContains('prato_base', $composition->rolesForFood($farinhaPuba));

        $composition->validate([
            ['food_id' => $frango->id, 'role' => 'prato_proteina'],
            ['food_id' => $arroz->id, 'role' => 'prato_base'],
            ['food_id' => $feijao->id, 'role' => 'prato_leguminosa'],
            ['food_id' => $couve->id, 'role' => 'prato_vegetal'],
        ], $definition, $foods);

        $this->expectException(ValidationException::class);
        $composition->validate([
            ['food_id' => $frango->id, 'role' => 'prato_proteina'],
            ['food_id' => $arroz->id, 'role' => 'prato_base'],
            ['food_id' => $feijaoCru->id, 'role' => 'prato_leguminosa'],
            ['food_id' => $couve->id, 'role' => 'prato_vegetal'],
        ], $definition, $foods);
    }
}

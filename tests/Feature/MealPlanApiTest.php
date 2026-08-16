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
                        ]
                        : [
                            ['food_id' => $candidate('prato_proteina')['id'], 'role' => 'prato_proteina', 'quantity_g' => $meal['target']['proteina'] * .65],
                            ['food_id' => $candidate('prato_base')['id'], 'role' => 'prato_base', 'quantity_g' => $meal['target']['carbo'] * .65],
                            ['food_id' => $candidate('prato_vegetal')['id'], 'role' => 'prato_vegetal', 'quantity_g' => 80],
                            ['food_id' => $candidate('acompanhamento')['id'], 'role' => 'acompanhamento', 'quantity_g' => ($meal['target']['gordura'] / .8) * .65],
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
        $this->food('Ovos', 100, 0, 54.167, 887.5, ['cafe_proteina']);
        $this->food('Pão integral', 0, 100, 0, 400, ['cafe_base']);
        $this->food('Frango', 100, 0, 0, 400, ['prato_proteina']);
        $this->food('Arroz', 0, 100, 0, 400, ['prato_base']);
        $this->food('Brócolis', 0, 0, 0, 0, ['prato_vegetal']);
        $this->food('Azeite', 0, 0, 80, 720, ['acompanhamento']);
        $this->food('Omelete', 100, 0, 54.167, 887.5, ['cafe_proteina']);
        $this->food('Tapioca', 0, 100, 0, 400, ['cafe_base']);
        $this->food('Carne', 100, 0, 0, 400, ['prato_proteina']);
        $this->food('Peixe', 100, 0, 0, 400, ['prato_proteina']);
        $this->food('Batata', 0, 100, 0, 400, ['prato_base']);
        $this->food('Abobrinha', 0, 0, 0, 0, ['prato_vegetal']);
        $this->food('Óleo', 0, 0, 80, 720, ['acompanhamento']);
        $this->fakeGemini();
        Sanctum::actingAs($user);

        $payload = ['meal_count' => 3, 'meal_times' => ['08:00', '12:30', '19:30'], 'style' => 'rapido', 'diet_type' => 'onivora', 'restriction_slugs' => [], 'excluded_food_ids' => []];
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

    public function test_profile_persists_restrictions_and_a_draft_cannot_be_saved_by_another_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $profile = ['meal_count' => 4, 'meal_times' => ['08:00', '12:30', '16:30', '20:00'], 'style' => 'caseiro', 'diet_type' => 'vegana', 'restriction_slugs' => [], 'excluded_food_ids' => []];
        $this->putJson('/api/meal-plan-profile', $profile)->assertOk()->assertJsonPath('data.diet_type', 'vegana');
        $this->getJson('/api/meal-plan-profile')->assertOk()->assertJsonPath('data.style', 'caseiro');

        $draft = \App\Models\MealPlanDraft::create(['user_id' => $user->id, 'preferences' => $profile, 'payload' => [], 'expires_at' => now()->addMinutes(10)]);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/meal-plans', ['titulo' => 'Invasão', 'draft_id' => $draft->id])->assertNotFound();
    }
}

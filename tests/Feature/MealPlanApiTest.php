<?php

namespace Tests\Feature;

use App\Models\Alimento;
use App\Models\Meta_diaria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MealPlanApiTest extends TestCase
{
    use RefreshDatabase;

    private function food(string $name, float $protein = 0, float $carbs = 0, float $fat = 0, float $calories = 0): Alimento
    {
        return Alimento::create(['descricao' => $name, 'nome_normalizado' => mb_strtolower($name), 'fonte' => 'taco', 'source_reference' => $name, 'status' => 'ativo', 'grupo' => 'Teste', 'qtd' => 100, 'proteina' => $protein, 'carbo' => $carbs, 'gordura' => $fat, 'caloria' => $calories]);
    }

    private function fakeGemini(Alimento $protein, Alimento $carb, Alimento $fat): void
    {
        Http::fake(['https://generativelanguage.googleapis.com/*' => function ($request) {
            $input = json_decode($request->data()['input'], true);
            $catalog = collect($input['catalogo_permitido']);
            $protein = $catalog->first(fn ($food) => $food['proteina_g'] >= 100);
            $carb = $catalog->first(fn ($food) => $food['carbo_g'] >= 100);
            $fat = $catalog->first(fn ($food) => $food['gordura_g'] >= 80);
            return Http::response(['output_text' => json_encode([
                'summary' => 'Plano prático montado com alimentos do seu catálogo.',
                'meals' => collect($input['refeicoes'])->map(fn ($meal) => ['position' => $meal['position'], 'explanation' => 'Combinação equilibrada para este horário.', 'items' => [
                    ['food_id' => $protein['id'], 'quantity_g' => $meal['target']['proteina']], ['food_id' => $carb['id'], 'quantity_g' => $meal['target']['carbo']], ['food_id' => $fat['id'], 'quantity_g' => $meal['target']['gordura'] / .8],
                ]])->all(),
            ])]);
        }]);
    }

    public function test_user_can_preview_save_and_replace_a_meal_from_a_gemini_draft(): void
    {
        config()->set('gemini.api_key', 'test-key');
        $user = User::factory()->create();
        Meta_diaria::create(['id_usuario' => $user->id, 'data' => null, 'meta_calorias' => 2000, 'meta_proteinas' => 120, 'meta_carboidratos' => 220, 'meta_gorduras' => 65]);
        $protein = $this->food('Proteína', 100, 0, 0, 400);
        $carb = $this->food('Carboidrato', 0, 100, 0, 400);
        $fat = $this->food('Gordura', 0, 0, 80, 720);
        $this->food('Proteína alternativa', 100, 0, 0, 400);
        $this->food('Carboidrato alternativo', 0, 100, 0, 400);
        $this->food('Gordura alternativa', 0, 0, 80, 720);
        foreach (range(1, 2) as $number) $this->food("Extra {$number}", 5, 5, 5, 80);
        $this->fakeGemini($protein, $carb, $fat);
        Sanctum::actingAs($user);

        $payload = ['meal_count' => 3, 'meal_times' => ['08:00', '12:30', '19:30'], 'style' => 'rapido', 'diet_type' => 'onivora', 'restriction_slugs' => [], 'excluded_food_ids' => []];
        $draft = $this->postJson('/api/meal-plans/preview', $payload)->assertOk()->assertJsonCount(3, 'data.meals')->json('data');
        $this->assertArrayHasKey('draft_id', $draft);
        $this->postJson('/api/meal-plans/preview/meal/1', ['draft_id' => $draft['draft_id']])->assertOk()->assertJsonPath('data.draft_id', $draft['draft_id']);
        $this->postJson('/api/meal-plans', ['titulo' => 'Minha rotina', 'draft_id' => $draft['draft_id']])
            ->assertCreated()->assertJsonPath('data.titulo', 'Minha rotina')->assertJsonCount(3, 'data.meals');
        $this->assertDatabaseCount('meal_plans', 1);
        $this->assertDatabaseCount('meal_plan_drafts', 0);
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

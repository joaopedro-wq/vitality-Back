<?php

namespace Tests\Feature;

use App\Models\Alimento;
use App\Models\Meta_diaria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MealPlanApiTest extends TestCase
{
    use RefreshDatabase;

    private function food(string $name, float $protein, float $carbs, float $fat, float $calories): Alimento
    {
        return Alimento::create([
            'descricao' => $name, 'nome_normalizado' => mb_strtolower($name), 'fonte' => 'taco',
            'source_reference' => $name, 'status' => 'ativo', 'grupo' => 'Teste', 'qtd' => 100,
            'proteina' => $protein, 'carbo' => $carbs, 'gordura' => $fat, 'caloria' => $calories,
        ]);
    }

    public function test_user_can_preview_and_save_a_personal_plan_without_creating_diary_entries(): void
    {
        $user = User::factory()->create();
        Meta_diaria::create(['id_usuario' => $user->id, 'data' => null, 'meta_calorias' => 2000, 'meta_proteinas' => 120, 'meta_carboidratos' => 220, 'meta_gorduras' => 65]);
        $rice = $this->food('Arroz', 3, 28, 1, 130);
        $this->food('Frango', 31, 0, 4, 165);
        $this->food('Azeite', 0, 0, 100, 884);
        $this->food('Banana', 1, 23, 0, 89);
        Sanctum::actingAs($user);

        $payload = ['meal_count' => 3, 'meal_times' => ['08:00', '12:30', '19:30'], 'style' => 'rapido', 'excluded_food_ids' => []];
        $preview = $this->postJson('/api/meal-plans/preview', $payload)
            ->assertOk()->assertJsonPath('data.meals.0.descricao', 'Café da manhã')->assertJsonCount(3, 'data.meals');

        $this->postJson('/api/meal-plans', ['titulo' => 'Minha rotina', ...$payload])
            ->assertCreated()->assertJsonPath('data.titulo', 'Minha rotina')->assertJsonCount(3, 'data.meals');
        $this->assertDatabaseCount('meal_plans', 1);
        $this->assertDatabaseCount('registros', 0);
        $this->getJson('/api/meal-plans')->assertOk()->assertJsonCount(1, 'data');

        $this->postJson('/api/meal-plans/preview', [...$payload, 'excluded_food_ids' => [$rice->id]])
            ->assertOk()->assertJsonMissing(['food_id' => $rice->id]);
    }

    public function test_a_user_cannot_archive_another_users_plan(): void
    {
        $owner = User::factory()->create();
        $plan = \App\Models\MealPlan::create(['user_id' => $owner->id, 'titulo' => 'Privado', 'style' => 'rapido', 'meal_count' => 3, 'preferences' => [], 'target' => [], 'totals' => []]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/meal-plans/{$plan->id}/archive")->assertNotFound();
        $this->assertNull($plan->fresh()->archived_at);
    }
}

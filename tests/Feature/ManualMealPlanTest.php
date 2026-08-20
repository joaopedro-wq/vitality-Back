<?php

namespace Tests\Feature;

use App\Models\Alimento;
use App\Models\Meta_diaria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManualMealPlanTest extends TestCase
{
    use RefreshDatabase;

    private function food(string $name, float $protein = 0, float $carbs = 0, float $fat = 0, float $calories = 0): Alimento
    {
        return Alimento::create([
            'descricao' => $name, 'nome_normalizado' => mb_strtolower($name), 'fonte' => 'taco', 'source_reference' => $name,
            'status' => 'ativo', 'grupo' => 'Teste', 'qtd' => 100, 'proteina' => $protein, 'carbo' => $carbs, 'gordura' => $fat, 'caloria' => $calories,
        ]);
    }

    private function payload(int $eggId, int $breadId, int $chickenId, int $riceId): array
    {
        return [
            'meal_count' => 3,
            'meal_times' => ['08:00', '12:30', '19:30'],
            'meals' => [
                ['position' => 0, 'descricao' => 'Café da manhã', 'horario' => '08:00', 'items' => [
                    ['food_id' => $eggId, 'quantity' => 100], ['food_id' => $breadId, 'quantity' => 80],
                ]],
                ['position' => 1, 'horario' => '12:30', 'items' => [
                    ['food_id' => $chickenId, 'quantity' => 150], ['food_id' => $riceId, 'quantity' => 150],
                ]],
                ['position' => 2, 'horario' => '19:30', 'items' => [
                    ['food_id' => $chickenId, 'quantity' => 120], ['food_id' => $riceId, 'quantity' => 120],
                ]],
            ],
        ];
    }

    public function test_user_can_preview_and_save_a_manual_meal_plan(): void
    {
        $user = User::factory()->create();
        Meta_diaria::create(['id_usuario' => $user->id, 'data' => null, 'meta_calorias' => 2000, 'meta_proteinas' => 120, 'meta_carboidratos' => 220, 'meta_gorduras' => 65]);
        $egg = $this->food('Ovos', 13, 1, 10, 155);
        $bread = $this->food('Pão integral', 9, 50, 3, 253);
        $chicken = $this->food('Frango', 31, 0, 4, 165);
        $rice = $this->food('Arroz cozido', 2, 28, 0, 130);
        Sanctum::actingAs($user);

        $draft = $this->postJson('/api/meal-plans/manual/preview', $this->payload($egg->id, $bread->id, $chicken->id, $rice->id))
            ->assertOk()
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonCount(3, 'data.meals')
            ->json('data');

        $this->assertArrayHasKey('draft_id', $draft);
        $this->assertGreaterThan(0, $draft['totals']['caloria']);

        $plan = $this->postJson('/api/meal-plans', ['titulo' => 'Meu plano manual', 'draft_id' => $draft['draft_id']])
            ->assertCreated()
            ->assertJsonPath('data.titulo', 'Meu plano manual')
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonCount(3, 'data.meals')
            ->json('data');

        $this->assertDatabaseHas('meal_plans', ['id' => $plan['id'], 'generation_provider' => 'manual']);
    }

    public function test_manual_preview_rejects_invalid_food_id(): void
    {
        $user = User::factory()->create();
        Meta_diaria::create(['id_usuario' => $user->id, 'data' => null, 'meta_calorias' => 2000, 'meta_proteinas' => 120, 'meta_carboidratos' => 220, 'meta_gorduras' => 65]);
        Sanctum::actingAs($user);

        $payload = [
            'meal_count' => 3,
            'meal_times' => ['08:00', '12:30', '19:30'],
            'meals' => [
                ['position' => 0, 'horario' => '08:00', 'items' => [['food_id' => 999999, 'quantity' => 100]]],
                ['position' => 1, 'horario' => '12:30', 'items' => [['food_id' => 999999, 'quantity' => 100]]],
                ['position' => 2, 'horario' => '19:30', 'items' => [['food_id' => 999999, 'quantity' => 100]]],
            ],
        ];

        $this->postJson('/api/meal-plans/manual/preview', $payload)->assertStatus(422);
    }

    public function test_user_can_update_add_and_remove_manual_meals(): void
    {
        $user = User::factory()->create();
        Meta_diaria::create(['id_usuario' => $user->id, 'data' => null, 'meta_calorias' => 2000, 'meta_proteinas' => 120, 'meta_carboidratos' => 220, 'meta_gorduras' => 65]);
        $egg = $this->food('Ovos', 13, 1, 10, 155);
        $bread = $this->food('Pão integral', 9, 50, 3, 253);
        $chicken = $this->food('Frango', 31, 0, 4, 165);
        $rice = $this->food('Arroz cozido', 2, 28, 0, 130);
        $banana = $this->food('Banana', 1, 22, 0, 89);
        Sanctum::actingAs($user);

        $draft = $this->postJson('/api/meal-plans/manual/preview', $this->payload($egg->id, $bread->id, $chicken->id, $rice->id))
            ->assertOk()->json('data');

        $updated = $this->putJson("/api/meal-plans/manual/preview/meal/0", [
            'draft_id' => $draft['draft_id'], 'items' => [['food_id' => $banana->id, 'quantity' => 100]],
        ])->assertOk()->json('data');
        $this->assertCount(1, $updated['meals'][0]['items']);

        $added = $this->postJson('/api/meal-plans/manual/preview/meal', [
            'draft_id' => $draft['draft_id'], 'position' => 3, 'horario' => '16:00', 'items' => [['food_id' => $banana->id, 'quantity' => 80]],
        ])->assertOk()->json('data');
        $this->assertCount(4, $added['meals']);

        $removed = $this->deleteJson('/api/meal-plans/manual/preview/meal/3', ['draft_id' => $draft['draft_id']])
            ->assertOk()->json('data');
        $this->assertCount(3, $removed['meals']);
    }
}

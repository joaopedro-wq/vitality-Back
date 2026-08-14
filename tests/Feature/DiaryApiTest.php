<?php

namespace Tests\Feature;

use App\Models\Alimento;
use App\Models\Nutriente;
use App\Models\Registro;
use App\Models\User;
use App\Services\MealPresetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DiaryApiTest extends TestCase
{
    use RefreshDatabase;

    private function food(): Alimento
    {
        $food = Alimento::create([
            'descricao' => 'Arroz cozido', 'nome_normalizado' => 'arroz cozido',
            'fonte' => 'taco', 'source_reference' => 'diary-test', 'status' => 'ativo', 'grupo' => 'Cereais',
            'proteina' => 4, 'gordura' => 1, 'carbo' => 30, 'caloria' => 150, 'qtd' => 100,
        ]);
        $nutrient = Nutriente::create(['codigo' => '303', 'nome' => 'Ferro', 'unidade' => 'mg', 'categoria' => 'mineral']);
        $food->nutrientes()->attach($nutrient->id, ['valor' => 1.5, 'tipo_dado' => 'analitico']);

        return $food;
    }

    private function mealFor(User $user)
    {
        app(MealPresetService::class)->ensureFor($user);

        return $user->refeicoes()->orderBy('ordem')->firstOrFail();
    }

    public function test_user_can_create_multiple_entries_and_duplicate_foods_are_aggregated_with_full_snapshot(): void
    {
        $user = User::factory()->create();
        $meal = $this->mealFor($user);
        $food = $this->food();
        Sanctum::actingAs($user);
        $consumedAt = now()->subMinutes(10)->toIso8601String();

        $response = $this->postJson('/api/diary/entries', [
            'meal_id' => $meal->id,
            'consumed_at' => $consumedAt,
            'items' => [
                ['food_id' => $food->id, 'quantity' => 100],
                ['food_id' => $food->id, 'quantity' => 50],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.items.0.quantity', 150)
            ->assertJsonPath('data.items.0.macros.proteina', 6)
            ->assertJsonPath('data.items.0.nutrientes.0.codigo', '303')
            ->assertJsonPath('data.items.0.nutrientes.0.valor', 2.25);

        $entryId = $response->json('data.id');
        $this->assertDatabaseCount('registros', 1);
        $this->assertDatabaseHas('registro_alimentos', ['registro_id' => $entryId, 'alimento_id' => $food->id, 'qtd' => 150]);

        $food->update(['proteina' => 99]);
        $date = now('America/Sao_Paulo')->toDateString();
        $this->getJson('/api/diary/day?date='.$date)
            ->assertOk()
            ->assertJsonCount(1, 'data.entries')
            ->assertJsonPath('data.entries.0.items.0.macros.proteina', 6);

        $this->postJson('/api/diary/entries', [
            'meal_id' => $meal->id,
            'consumed_at' => now()->subMinute()->toIso8601String(),
            'items' => [['food_id' => $food->id, 'quantity' => 20]],
        ])->assertCreated();
        $this->assertDatabaseCount('registros', 2);
    }

    public function test_invalid_item_rolls_back_entry_and_other_users_cannot_access_it(): void
    {
        $user = User::factory()->create();
        $meal = $this->mealFor($user);
        $food = $this->food();
        Sanctum::actingAs($user);

        $this->postJson('/api/diary/entries', [
            'meal_id' => $meal->id,
            'consumed_at' => now()->subMinute()->toIso8601String(),
            'items' => [['food_id' => 999999, 'quantity' => 20]],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('registros', 0);

        $entry = Registro::create([
            'id_usuario' => $user->id, 'id_refeicao' => $meal->id, 'data' => now('America/Sao_Paulo')->toDateString(),
            'consumed_at' => now()->subMinute(), 'descricao_refeicao_snapshot' => $meal->descricao, 'horario_refeicao_snapshot' => $meal->horario,
        ]);
        $entry->alimentos()->attach($food->id, ['qtd' => 100, 'descricao_snapshot' => $food->descricao, 'qtd_base_snapshot' => 100, 'proteina_snapshot' => 4, 'gordura_snapshot' => 1, 'carbo_snapshot' => 30, 'caloria_snapshot' => 150, 'nutrientes_snapshot' => []]);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/diary/entries/'.$entry->id)->assertNotFound();
        $this->patchJson('/api/diary/entries/'.$entry->id, [])->assertNotFound();
    }

    public function test_meal_presets_and_future_entries_are_handled_safely(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/diary/meals')->assertOk()->assertJsonCount(5, 'data');
        $meal = $this->mealFor($user);
        $food = $this->food();

        $this->postJson('/api/diary/entries', [
            'meal_id' => $meal->id,
            'consumed_at' => now()->addMinute()->toIso8601String(),
            'items' => [['food_id' => $food->id, 'quantity' => 100]],
        ])->assertUnprocessable();
    }
    public function test_recent_foods_are_distinct_ordered_and_scoped_to_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $meal = $this->mealFor($user);
        $first = $this->food();
        $second = Alimento::create([
            'descricao' => 'Banana prata', 'nome_normalizado' => 'banana prata',
            'fonte' => 'taco', 'source_reference' => 'recent-test', 'status' => 'ativo', 'grupo' => 'Frutas',
            'proteina' => 1, 'gordura' => 0, 'carbo' => 20, 'caloria' => 90, 'qtd' => 100,
        ]);
        Sanctum::actingAs($user);

        foreach ([[$first, 30], [$second, 20], [$first, 10]] as [$food, $minutes]) {
            $this->postJson('/api/diary/entries', [
                'meal_id' => $meal->id,
                'consumed_at' => now()->subMinutes($minutes)->toIso8601String(),
                'items' => [['food_id' => $food->id, 'quantity' => 100]],
            ])->assertCreated();
        }

        $otherUser = User::factory()->create();
        $otherMeal = $this->mealFor($otherUser);
        Sanctum::actingAs($otherUser);
        $this->postJson('/api/diary/entries', [
            'meal_id' => $otherMeal->id,
            'consumed_at' => now()->subMinute()->toIso8601String(),
            'items' => [['food_id' => $second->id, 'quantity' => 100]],
        ])->assertCreated();

        Sanctum::actingAs($user);
        $this->getJson('/api/diary/recent-foods?limit=8')
            ->assertOk()
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.1.id', $second->id)
            ->assertJsonCount(2, 'data');
    }
}

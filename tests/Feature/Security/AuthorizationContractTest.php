<?php

namespace Tests\Feature\Security;

use App\Models\MealPlan;
use App\Models\Registro;
use App\Models\User;
use App\Services\MealPresetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthorizationContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_read_update_or_delete_another_users_diary_entry(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $meal = $this->mealFor($owner);
        $entry = Registro::create([
            'id_usuario' => $owner->id,
            'id_refeicao' => $meal->id,
            'data' => now('America/Sao_Paulo')->toDateString(),
            'consumed_at' => now()->subMinute(),
            'descricao_refeicao_snapshot' => $meal->descricao,
            'horario_refeicao_snapshot' => $meal->horario,
        ]);

        Sanctum::actingAs($attacker);

        $this->getJson("/api/diary/entries/{$entry->id}")->assertNotFound();
        $this->patchJson("/api/diary/entries/{$entry->id}", [])->assertNotFound();
        $this->deleteJson("/api/diary/entries/{$entry->id}")->assertNotFound();

        $this->assertDatabaseHas('registros', ['id' => $entry->id, 'id_usuario' => $owner->id]);
    }

    public function test_user_cannot_mutate_another_users_meal_plan(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $plan = MealPlan::create([
            'user_id' => $owner->id,
            'titulo' => 'Plano privado',
            'style' => 'caseiro',
            'meal_count' => 3,
            'preferences' => [],
            'target' => [],
            'totals' => [],
        ]);

        Sanctum::actingAs($attacker);

        $this->postJson("/api/meal-plans/{$plan->id}/edit-draft")->assertNotFound();
        $this->postJson("/api/meal-plans/{$plan->id}/archive")->assertNotFound();
        $this->postJson("/api/meal-plans/{$plan->id}/favorite")->assertNotFound();
        $this->deleteJson("/api/meal-plans/{$plan->id}")->assertNotFound();

        $this->assertDatabaseHas('meal_plans', ['id' => $plan->id, 'user_id' => $owner->id, 'archived_at' => null, 'favorited_at' => null]);
    }

    private function mealFor(User $user)
    {
        app(MealPresetService::class)->ensureFor($user);

        return $user->refeicoes()->orderBy('ordem')->firstOrFail();
    }
}

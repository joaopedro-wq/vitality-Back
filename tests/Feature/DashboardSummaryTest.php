<?php

namespace Tests\Feature;

use App\Models\Alimento;
use App\Models\MealPlan;
use App\Models\Meta_diaria;
use App\Models\Registro;
use App\Models\User;
use App\Services\MealPresetService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function food(): Alimento
    {
        return Alimento::create([
            'descricao' => 'Frango grelhado', 'nome_normalizado' => 'frango grelhado',
            'fonte' => 'taco', 'source_reference' => 'dashboard-test', 'status' => 'ativo', 'grupo' => 'Carnes',
            'proteina' => 50, 'gordura' => 0, 'carbo' => 0, 'caloria' => 1000, 'qtd' => 100,
        ]);
    }

    private function registrarDia(User $user, Alimento $food, string $data, string $horario = '12:00:00'): void
    {
        $meal = $user->refeicoes()->orderBy('ordem')->firstOrFail();
        $entry = Registro::create([
            'id_usuario' => $user->id,
            'id_refeicao' => $meal->id,
            'data' => $data,
            'consumed_at' => CarbonImmutable::parse($data.' '.$horario, 'America/Sao_Paulo'),
            'descricao_refeicao_snapshot' => $meal->descricao,
            // Snapshot do horário usa o horário passado (não o horário padrão da refeição) —
            // é ele que o Painel compara contra o horário do plano pra aderência.
            'horario_refeicao_snapshot' => $horario,
        ]);

        // 200g a 1000kcal/100g = 2000kcal, exatamente a meta do teste (100% — dentro da faixa 90–110%).
        $entry->alimentos()->attach($food->id, [
            'qtd' => 200, 'descricao_snapshot' => $food->descricao, 'qtd_base_snapshot' => 100,
            'proteina_snapshot' => 50, 'gordura_snapshot' => 0, 'carbo_snapshot' => 0, 'caloria_snapshot' => 1000,
            'nutrientes_snapshot' => [],
        ]);
    }

    public function test_summary_computes_week_adherence_and_progression(): void
    {
        $user = User::factory()->create();
        app(MealPresetService::class)->ensureFor($user);
        $food = $this->food();

        Meta_diaria::create([
            'id_usuario' => $user->id, 'meta_calorias' => 2000, 'meta_proteinas' => 100,
            'meta_carboidratos' => 250, 'meta_gorduras' => 70,
        ]);

        $plan = MealPlan::create([
            'user_id' => $user->id, 'titulo' => 'Plano de teste', 'style' => 'padrao',
            'meal_count' => 1, 'preferences' => [], 'target' => [], 'totals' => [],
        ]);
        $plan->meals()->create([
            'position' => 1, 'descricao' => 'Almoço', 'horario' => '12:00:00', 'target' => [], 'totals' => [],
        ]);

        $hoje = now('America/Sao_Paulo');
        for ($i = 0; $i < 4; $i++) {
            $this->registrarDia($user, $food, $hoje->copy()->subDays($i)->toDateString());
        }

        Sanctum::actingAs($user);

        $this->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.hoje.percentual', 100)
            ->assertJsonPath('data.plano_ativo.titulo', 'Plano de teste')
            ->assertJsonPath('data.plano_ativo.aderencia_7d', 57)
            ->assertJsonPath('data.mais_consumidos.0.food_id', $food->id)
            ->assertJsonCount(7, 'data.semana');

        // Missões de progressão — 4 dias seguidos de registro (hoje incluído) bate o marco de 4
        // dias e a missão diária "primeira refeição hoje"; a missão de 7 dias e o "dia completo"
        // (exige as 5 refeições padrão, só 1 foi registrada) continuam pendentes. Missões
        // semanais não são asserted aqui — dependem de em que dia da semana o teste roda (os 4
        // dias podem cair em semanas diferentes do calendário), então ficariam instáveis.
        $progressao = $this->getJson('/api/dashboard/summary')->json('data.progressao');
        $marcos = collect($progressao['marcos']);
        $diarias = collect($progressao['diarias']);

        $this->assertTrue($marcos->firstWhere('codigo', 'marco_streak_4')['concluida']);
        $this->assertFalse($marcos->firstWhere('codigo', 'marco_streak_7')['concluida']);
        $this->assertTrue($diarias->firstWhere('codigo', 'log_primeira_refeicao')['concluida']);
        $this->assertFalse($diarias->firstWhere('codigo', 'log_dia_completo')['concluida']);

        // XP determinístico: log_primeira_refeicao (5) + marco_streak_4 (30) com certeza contam;
        // as missões semanais podem ou não somar em cima, então só o piso é garantido.
        $this->assertGreaterThanOrEqual(35, $progressao['xp']);
        $this->assertGreaterThanOrEqual(1, $progressao['nivel']);
        $this->assertDatabaseHas('user_mission_completions', ['user_id' => $user->id, 'mission_code' => 'marco_streak_4']);
    }

    public function test_favorited_plan_wins_over_more_recent_unfavorited_one_and_suggests_food(): void
    {
        $user = User::factory()->create();
        app(MealPresetService::class)->ensureFor($user);
        $food = $this->food();
        $breakfast = $user->refeicoes()->orderBy('ordem')->firstOrFail();

        $older = MealPlan::create([
            'user_id' => $user->id, 'titulo' => 'Plano favorito', 'style' => 'padrao',
            'meal_count' => 1, 'preferences' => [], 'target' => [], 'totals' => [],
        ]);
        $meal = $older->meals()->create([
            'position' => 1, 'descricao' => 'Café', 'horario' => $breakfast->horario, 'target' => [], 'totals' => [],
        ]);
        $meal->items()->create([
            'food_id' => $food->id, 'descricao_snapshot' => $food->descricao,
            'nome_exibicao_snapshot' => 'Frango grelhado', 'quantity' => 150, 'macros' => [],
        ]);

        // Um plano mais recente, mas NUNCA favoritado — não pode vencer o favorito mais antigo.
        MealPlan::create([
            'user_id' => $user->id, 'titulo' => 'Plano mais recente (não favoritado)', 'style' => 'padrao',
            'meal_count' => 1, 'preferences' => [], 'target' => [], 'totals' => [],
        ]);

        Sanctum::actingAs($user);
        $this->postJson("/api/meal-plans/{$older->id}/favorite")->assertOk();

        $this->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.plano_ativo.titulo', 'Plano favorito')
            ->assertJsonPath('data.proxima_refeicao.sugestao_plano', 'Frango grelhado');
    }

    public function test_favoriting_a_plan_unfavorites_any_previous_one(): void
    {
        $user = User::factory()->create();
        $first = MealPlan::create(['user_id' => $user->id, 'titulo' => 'A', 'style' => 'padrao', 'meal_count' => 1, 'preferences' => [], 'target' => [], 'totals' => []]);
        $second = MealPlan::create(['user_id' => $user->id, 'titulo' => 'B', 'style' => 'padrao', 'meal_count' => 1, 'preferences' => [], 'target' => [], 'totals' => []]);

        Sanctum::actingAs($user);
        $this->postJson("/api/meal-plans/{$first->id}/favorite")->assertOk();
        $this->postJson("/api/meal-plans/{$second->id}/favorite")->assertOk();

        $this->assertNull($first->fresh()->favorited_at);
        $this->assertNotNull($second->fresh()->favorited_at);
    }

    public function test_summary_handles_a_brand_new_account_without_errors(): void
    {
        $user = User::factory()->create();
        app(MealPresetService::class)->ensureFor($user);
        Sanctum::actingAs($user);

        $this->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.progressao.nivel', 1)
            ->assertJsonPath('data.progressao.xp', 0)
            ->assertJsonPath('data.hoje.percentual', 0)
            ->assertJsonPath('data.plano_ativo', null)
            ->assertJsonPath('data.mais_consumidos', []);
    }
}

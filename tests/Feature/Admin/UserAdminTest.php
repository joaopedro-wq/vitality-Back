<?php

namespace Tests\Feature\Admin;

use App\Models\Meta_diaria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_administrators_can_list_user_engagement(): void
    {
        $regular = User::factory()->create(['is_admin' => false]);
        $this->actingAs($regular, 'sanctum')->getJson('/api/admin/users')->assertForbidden();

        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/users?period=30');

        $response
            ->assertOk()
            ->assertJsonPath('meta.summary.total_users', 3)
            ->assertJsonFragment(['id' => $user->id, 'engagement_status' => 'novo']);
    }

    public function test_administrator_can_filter_users_by_engagement_status(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $inactive = User::factory()->create(['is_admin' => false, 'created_at' => now()->subMonths(3)]);
        User::factory()->create(['is_admin' => false]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users?period=30&engagement_status=inativo')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['id' => $inactive->id, 'engagement_status' => 'inativo']);
    }

    public function test_administrator_can_delete_a_user_account_and_related_data(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $target = User::factory()->create(['is_admin' => false]);
        Meta_diaria::create(['id_usuario' => $target->id, 'data' => null, 'meta_calorias' => 2000, 'meta_proteinas' => 120, 'meta_carboidratos' => 220, 'meta_gorduras' => 65]);
        $refeicaoId = DB::table('refeicaos')->insertGetId(['descricao' => 'Almoço', 'horario' => '12:00:00', 'id_usuario' => $target->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('registros')->insert(['id_usuario' => $target->id, 'id_refeicao' => $refeicaoId, 'data' => now()->toDateString(), 'consumed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertDatabaseMissing('meta_diarias', ['id_usuario' => $target->id]);
        $this->assertDatabaseMissing('registros', ['id_usuario' => $target->id]);
        $this->assertDatabaseMissing('refeicaos', ['id_usuario' => $target->id]);
    }

    public function test_administrator_cannot_delete_their_own_account(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/users/{$admin->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_regular_user_cannot_delete_another_users_account(): void
    {
        $regular = User::factory()->create(['is_admin' => false]);
        $target = User::factory()->create(['is_admin' => false]);

        $this->actingAs($regular, 'sanctum')
            ->deleteJson("/api/admin/users/{$target->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }
}

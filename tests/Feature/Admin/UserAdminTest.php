<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}

<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_registration_marks_the_onboarding_as_pending(): void
    {
        $this->postJson('/api/criar-usuario', [
            'name' => 'Nova Pessoa',
            'email' => 'nova@example.test',
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
        ])->assertCreated()->assertJsonPath('data.onboarding_status', 'pending');
    }

    public function test_authenticated_user_can_complete_or_skip_the_onboarding(): void
    {
        $user = User::factory()->create(['onboarding_status' => 'pending']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/user/onboarding', ['status' => 'skipped'])
            ->assertOk()
            ->assertJsonPath('data.onboarding_status', 'skipped');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'onboarding_status' => 'skipped',
        ]);
        $this->assertNotNull($user->fresh()->onboarding_finished_at);
    }

    public function test_guest_cannot_update_onboarding_state(): void
    {
        $this->putJson('/api/user/onboarding', ['status' => 'completed'])->assertUnauthorized();
    }
}

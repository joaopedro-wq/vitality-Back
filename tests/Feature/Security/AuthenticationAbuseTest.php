<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthenticationAbuseTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_login_returns_the_same_generic_response_for_existing_and_unknown_emails(): void
    {
        $user = User::factory()->create(['email' => 'known@example.test']);

        $known = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password']);
        $unknown = $this->postJson('/api/login', ['email' => 'unknown@example.test', 'password' => 'wrong-password']);

        $known->assertUnauthorized();
        $unknown->assertUnauthorized();
        $this->assertSame($known->json('message'), $unknown->json('message'));
    }

    public function test_api_login_is_rate_limited_after_five_attempts(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/login', ['email' => 'abuse@example.test', 'password' => 'wrong-password'])
                ->assertUnauthorized();
        }

        $this->postJson('/api/login', ['email' => 'abuse@example.test', 'password' => 'wrong-password'])
            ->assertTooManyRequests();
    }

    public function test_api_registration_is_rate_limited_without_creating_a_sixth_user(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/criar-usuario', [
                'name' => "User {$attempt}",
                'email' => "user-{$attempt}@example.test",
                'password' => 'StrongPassword123',
                'password_confirmation' => 'StrongPassword123',
            ])->assertCreated();
        }

        $this->postJson('/api/criar-usuario', [
            'name' => 'Blocked User',
            'email' => 'blocked@example.test',
            'password' => 'StrongPassword123',
            'password_confirmation' => 'StrongPassword123',
        ])->assertTooManyRequests();

        $this->assertDatabaseMissing('users', ['email' => 'blocked@example.test']);
    }

    public function test_password_reset_acknowledgement_does_not_enumerate_users(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'known@example.test']);

        $known = $this->postJson('/forgot-password', ['email' => $user->email]);
        $unknown = $this->postJson('/forgot-password', ['email' => 'unknown@example.test']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json(), $unknown->json());
    }
}

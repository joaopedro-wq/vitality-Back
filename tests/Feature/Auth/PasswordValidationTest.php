<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_registration_accepts_a_six_character_password_without_complexity_requirements(): void
    {
        $response = $this->postJson('/api/criar-usuario', [
            'name' => 'Six Character User',
            'email' => 'six@example.test',
            'password' => 'abcdef',
            'password_confirmation' => 'abcdef',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'six@example.test']);
    }

    public function test_api_registration_returns_the_password_error_in_portuguese(): void
    {
        $response = $this->withHeader('Accept-Language', 'pt-BR')->postJson('/api/criar-usuario', [
            'name' => 'Short Password User',
            'email' => 'short-pt@example.test',
            'password' => 'abcde',
            'password_confirmation' => 'abcde',
        ]);

        $response
            ->assertUnprocessable()
            ->assertHeader('Content-Language', 'pt-BR')
            ->assertJsonPath('errors.password.0', 'O campo senha deve ter pelo menos 6 caracteres.');
    }

    public function test_api_registration_returns_the_password_error_in_english(): void
    {
        $response = $this->withHeader('Accept-Language', 'en-US')->postJson('/api/criar-usuario', [
            'name' => 'Short Password User',
            'email' => 'short-en@example.test',
            'password' => 'abcde',
            'password_confirmation' => 'abcde',
        ]);

        $response
            ->assertUnprocessable()
            ->assertHeader('Content-Language', 'en-US')
            ->assertJsonPath('errors.password.0', 'The password field must be at least 6 characters.');
    }

    public function test_password_reset_accepts_a_six_character_password(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.test']);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (object $notification) use ($user) {
            $response = $this->postJson('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'abcdef',
                'password_confirmation' => 'abcdef',
            ]);

            $response->assertOk();

            return true;
        });
    }

    public function test_password_reset_returns_the_password_error_in_the_active_locale(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset-short@example.test']);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (object $notification) use ($user) {
            $response = $this->withHeader('Accept-Language', 'en-US')->postJson('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'abcde',
                'password_confirmation' => 'abcde',
            ]);

            $response
                ->assertUnprocessable()
                ->assertHeader('Content-Language', 'en-US')
                ->assertJsonPath('errors.password.0', 'The password field must be at least 6 characters.');

            return true;
        });
    }
}

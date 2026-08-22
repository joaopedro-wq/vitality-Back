<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_logout_then_protected_api_access_returns_unauthorized(): void
    {
        $user = User::factory()->create(['email' => 'session@example.test']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertNoContent();
        $this->getJson('/api/user')->assertOk();

        $this->post('/logout')->assertNoContent();
        app('auth')->forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();
        $this->getJson('/api/dashboard/summary')->assertUnauthorized();
    }

    public function test_security_headers_are_present(): void
    {
        $this->getJson('/up')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy-Report-Only');
    }
}

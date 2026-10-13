<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /** Headers that make Sanctum treat the request as coming from the React dev server. */
    private function spa(): static
    {
        return $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/',
        ]);
    }

    public function test_health_endpoint_reports_database_ok(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'database' => true]);
    }

    public function test_owner_can_log_in(): void
    {
        $user = User::factory()->create(['password' => 'correct-password-123']);
        $user->business()->create(['name' => 'Test Phones']);

        $this->spa()->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password-123',
        ])
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.business.name', 'Test Phones')
            ->assertJsonPath('data.business.currency', 'TZS');

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'correct-password-123']);

        $this->spa()->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->spa()->postJson('/api/auth/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_me_returns_the_logged_in_user(): void
    {
        $user = User::factory()->create();
        $user->business()->create(['name' => 'Test Phones']);

        $this->actingAs($user)->spa()->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.business.name', 'Test Phones');
    }

    public function test_logout_ends_the_session(): void
{
    $user = User::factory()->create();

    $this->actingAs($user)->spa()->postJson('/api/auth/logout')->assertNoContent();

    // Check the session guard explicitly: after an auth:sanctum request the default
    // guard of this test app is 'sanctum', which still holds its cached user.
    $this->assertGuest('web');
}

    public function test_there_is_no_public_registration(): void
    {
        $this->spa()->postJson('/api/auth/register', [
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'password' => 'a-long-password-123',
        ])->assertNotFound();
    }
}

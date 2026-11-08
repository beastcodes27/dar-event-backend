<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register_and_receive_a_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Iman Ibraah',
            'username' => 'iman_ibraah',
            'email' => 'iman@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'category' => 'Event Organizer',
            'bio' => 'Hello DarEvent',
            'terms' => true,
            'terms_version' => config('legal.terms.version'),
            'privacy_policy_version' => config('legal.privacy.version'),
            'device_name' => 'pixel',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.username', 'iman_ibraah')
            ->assertJsonStructure(['token', 'data' => ['id', 'name', 'username']]);

        $this->assertDatabaseHas('users', ['username' => 'iman_ibraah']);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_registration_validates_unique_username_and_terms(): void
    {
        User::factory()->create(['username' => 'taken']);

        $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'username' => 'taken',
            'email' => 'test@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'category' => 'Artist',
            'terms' => false,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['username', 'terms']);
    }

    public function test_a_user_can_login_with_email_or_username(): void
    {
        $user = User::factory()->create([
            'username' => 'iman_ibraah',
            'email' => 'iman@example.com',
            'password' => 'secret123',
        ]);

        $this->postJson('/api/auth/login', [
            'login' => 'iman_ibraah',
            'password' => 'secret123',
        ])->assertOk()->assertJsonStructure(['token', 'data']);

        $this->postJson('/api/auth/login', [
            'email' => 'iman@example.com',
            'password' => 'secret123',
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'login' => $user->username,
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    public function test_authenticated_user_can_fetch_profile_and_logout(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);

        $this->postJson('/api/auth/logout')->assertOk();
    }

    public function test_protected_routes_require_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->getJson('/api/feed')->assertUnauthorized();
    }

    public function test_a_user_can_change_their_password(): void
    {
        $user = User::factory()->create(['password' => 'secret123']);
        Sanctum::actingAs($user);

        $this->postJson('/api/auth/password', [
            'current_password' => 'secret123',
            'password' => 'new-secret',
            'password_confirmation' => 'new-secret',
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'login' => $user->username,
            'password' => 'new-secret',
        ])->assertOk();
    }
}

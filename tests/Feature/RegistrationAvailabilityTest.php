<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_and_taken_identifiers_are_checked_without_authentication(): void
    {
        User::factory()->create(['username' => 'Taken.User', 'email' => 'Taken@Example.com']);
        foreach ([['username', ' TAKEN.USER ', false], ['email', ' TAKEN@EXAMPLE.COM ', false], ['username', 'new_person', true], ['email', 'new@example.com', true]] as [$field, $value, $available]) {
            $this->postJson('/api/auth/availability', compact('field', 'value'))->assertOk()
                ->assertJsonPath('data.available', $available)->assertJsonPath('data.value', strtolower(trim($value)))
                ->assertJsonMissingPath('data.id')->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_invalid_checks_are_rejected(): void
    {
        foreach ([['field' => 'password', 'value' => 'secret'], ['field' => 'username', 'value' => 'ab'], ['field' => 'username', 'value' => 'bad name'], ['field' => 'email', 'value' => 'invalid'], ['field' => 'email', 'value' => ['not a string']]] as $data) {
            $this->postJson('/api/auth/availability', $data)->assertUnprocessable();
        }
    }

    public function test_registration_rechecks_availability_and_normalizes_identifiers(): void
    {
        $payload = ['name' => 'New User', 'username' => 'New.User', 'email' => 'New@Example.com', 'category' => 'Artist', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'terms' => true];
        $payload['terms_version'] = config('legal.terms.version');
        $payload['privacy_policy_version'] = config('legal.privacy.version');
        $this->postJson('/api/auth/availability', ['field' => 'username', 'value' => $payload['username']])->assertJsonPath('data.available', true);
        User::factory()->create(['username' => 'NEW.USER', 'email' => 'NEW@EXAMPLE.COM']);
        $this->postJson('/api/auth/register', $payload)->assertUnprocessable()->assertJsonValidationErrors(['username', 'email']);
        $payload['username'] = ' Free.User ';
        $payload['email'] = ' Free@Example.com ';
        $this->postJson('/api/auth/register', $payload)->assertCreated()->assertJsonPath('data.username', 'free.user');
        $this->assertDatabaseHas('users', ['username' => 'free.user', 'email' => 'free@example.com']);
        $this->postJson('/api/auth/login', ['login' => 'FREE.USER', 'password' => 'secret123'])->assertOk();
    }

    public function test_availability_checks_are_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/api/auth/availability', ['field' => 'username', 'value' => 'freeuser'])->assertOk();
        }
        $this->postJson('/api/auth/availability', ['field' => 'username', 'value' => 'freeuser'])->assertStatus(429);
    }
}

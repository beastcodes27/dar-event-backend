<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LegalTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_policy_documents_are_public_and_versioned(): void
    {
        $this->getJson('/api/legal')->assertOk()->assertJsonPath('data.terms.version', config('legal.terms.version'))
            ->assertJsonPath('data.privacy.version', config('legal.privacy.version'))->assertJsonMissingPath('data.terms.sections');
        foreach (['terms', 'privacy'] as $document) {
            $this->getJson('/api/legal/'.$document)->assertOk()->assertJsonPath('data.id', $document)
                ->assertJsonPath('data.version', config('legal.'.$document.'.version'))
                ->assertJsonStructure(['data' => ['title', 'summary', 'updated_at', 'sections' => [['title', 'body']]]]);
        }
        $this->getJson('/api/legal/unknown')->assertNotFound();
    }

    public function test_registration_records_current_versions_and_server_time_without_backfilling_existing_accounts(): void
    {
        $existing = User::factory()->create();
        $this->assertNull($existing->fresh()->terms_accepted_at);
        $payload = ['name' => 'New User', 'username' => 'new.user', 'email' => 'new@example.com', 'category' => 'Artist', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'terms' => true, 'terms_version' => config('legal.terms.version'), 'privacy_policy_version' => config('legal.privacy.version'), 'terms_accepted_at' => '1999-01-01T00:00:00Z'];
        $this->postJson('/api/auth/register', $payload)->assertCreated();
        $user = User::where('username', 'new.user')->firstOrFail();
        $this->assertSame(config('legal.terms.version'), $user->terms_version);
        $this->assertSame(config('legal.privacy.version'), $user->privacy_policy_version);
        $this->assertTrue($user->terms_accepted_at->isToday());
        Sanctum::actingAs($user);
        $this->getJson('/api/profile')->assertJsonPath('data.terms_version', $payload['terms_version']);
        $this->patchJson('/api/profile', ['terms_version' => 'fake', 'terms_accepted_at' => '1999-01-01'])->assertOk();
        $this->assertSame($payload['terms_version'], $user->fresh()->terms_version);
        Sanctum::actingAs($existing);
        $this->getJson('/api/users/'.$user->username)->assertJsonMissingPath('data.terms_accepted_at')->assertJsonMissingPath('data.terms_version')->assertJsonMissingPath('data.privacy_policy_version');
        $this->assertNull($existing->fresh()->terms_accepted_at);
    }

    public function test_registration_requires_acceptance_of_the_current_versions(): void
    {
        $payload = ['name' => 'New User', 'username' => 'new.user', 'email' => 'new@example.com', 'category' => 'Artist', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'terms' => true];
        $this->postJson('/api/auth/register', $payload)->assertUnprocessable()->assertJsonValidationErrors(['terms_version', 'privacy_policy_version']);
        $this->postJson('/api/auth/register', $payload + ['terms_version' => 'old', 'privacy_policy_version' => 'old'])
            ->assertUnprocessable()->assertJsonValidationErrors(['terms_version', 'privacy_policy_version']);
        $payload['terms_version'] = config('legal.terms.version');
        $payload['privacy_policy_version'] = config('legal.privacy.version');
        $payload['terms'] = false;
        $this->postJson('/api/auth/register', $payload)->assertUnprocessable()->assertJsonValidationErrors('terms');
        $this->assertDatabaseCount('users', 0);

    }
}

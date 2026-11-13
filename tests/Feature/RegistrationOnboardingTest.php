<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegistrationOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function details(array $extra = []): array
    {
        return array_replace(['username' => 'new_person', 'phone' => '+255 712 345 678', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'terms' => true, 'terms_version' => config('legal.terms.version'), 'privacy_policy_version' => config('legal.privacy.version')], $extra);
    }

    public function test_phone_signup_returns_private_setup_state_and_phone_login_works(): void
    {
        $response = $this->postJson('/api/auth/register', $this->details(['is_official' => true, 'onboarding_step' => 8]));
        $response->assertCreated()->assertJsonPath('data.phone', '+255712345678')->assertJsonPath('data.email', null)->assertJsonPath('data.onboarding_step', 4)->assertJsonPath('data.onboarding_completed_at', null)->assertJsonPath('data.is_official', false);
        $this->assertDatabaseHas('users', ['username' => 'new_person', 'name' => 'new_person', 'category' => 'Personal', 'email' => null, 'phone' => '+255712345678', 'is_official' => false]);
        $this->postJson('/api/auth/login', ['login' => '+255 (712) 345-678', 'password' => 'secret123'])->assertOk()->assertJsonPath('data.onboarding_step', 4);
    }

    public function test_signup_checks_phone_uniqueness_and_requires_an_email_or_phone(): void
    {
        User::factory()->create(['phone' => '+255712345678']);
        $this->postJson('/api/auth/availability', ['field' => 'phone', 'value' => '00255 712 345 678'])->assertOk()->assertJsonPath('data.available', false);
        $this->postJson('/api/auth/register', $this->details())->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->postJson('/api/auth/register', $this->details(['phone' => null]))->assertUnprocessable()->assertJsonValidationErrors(['phone', 'email']);
        $this->postJson('/api/auth/register', $this->details(['phone' => 'not-a-number']))->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_official_username_is_reserved_and_profile_changes_cannot_claim_it(): void
    {
        $this->postJson('/api/auth/availability', ['field' => 'username', 'value' => 'Veylo'])->assertOk()->assertJsonPath('data.available', false);
        $this->postJson('/api/auth/register', $this->details(['username' => 'Veylo']))->assertUnprocessable()->assertJsonValidationErrors('username');
        Sanctum::actingAs(User::factory()->create());
        $this->patchJson('/api/profile', ['username' => 'vEyLo'])->assertUnprocessable()->assertJsonValidationErrors('username');
    }

    public function test_official_account_command_is_idempotent_and_never_takes_over_existing_accounts(): void
    {
        $this->assertSame(0, Artisan::call('app:ensure-veylo-account'));
        $this->assertSame(0, Artisan::call('app:ensure-veylo-account'));
        $official = User::where('username', 'veylo')->firstOrFail();
        $this->assertTrue($official->is_official);
        $this->assertTrue($official->verified);
        $this->assertFalse($official->is_admin);
        $this->assertNotNull($official->onboarding_completed_at);
        $this->assertDatabaseCount('users', 1);
        $official->forceFill(['is_official' => false])->save();
        $this->assertSame(1, Artisan::call('app:ensure-veylo-account'));
        $this->assertFalse($official->fresh()->is_official);
    }

    public function test_suggestions_put_veylo_first_without_exposing_contact_details(): void
    {
        Artisan::call('app:ensure-veylo-account');
        User::factory()->create(['verified' => true, 'phone' => '+447123456789']);
        $me = User::factory()->create();
        Sanctum::actingAs($me);
        $this->getJson('/api/onboarding/suggestions')->assertOk()->assertJsonPath('data.0.username', 'veylo')->assertJsonPath('data.0.is_official', true)->assertJsonPath('meta.minimum_to_follow', 2)->assertJsonMissingPath('data.0.email')->assertJsonMissingPath('data.1.phone');
    }

    public function test_profile_and_welcome_progress_resumes_and_cannot_jump_ahead(): void
    {
        $me = User::factory()->create();
        Sanctum::actingAs($me);
        $this->patchJson('/api/onboarding/progress', ['step' => 7])->assertUnprocessable();
        $this->patchJson('/api/onboarding/progress', ['step' => 5])->assertOk()->assertJsonPath('data.onboarding_step', 5);
        $this->patchJson('/api/onboarding/progress', ['step' => 6])->assertOk();
        $this->patchJson('/api/onboarding/progress', ['step' => 5])->assertOk()->assertJsonPath('data.onboarding_step', 6);
        $this->assertDatabaseHas('users', ['id' => $me->id, 'onboarding_step' => 6]);
    }

    public function test_finishing_requires_two_accounts_and_retries_never_unfollow_them(): void
    {
        $this->freezeTime();
        Artisan::call('app:ensure-veylo-account');
        $official = User::where('username', 'veylo')->firstOrFail();
        $publisher = User::factory()->create();
        $me = User::factory()->create(['onboarding_step' => 7]);
        Sanctum::actingAs($me);
        $this->postJson('/api/onboarding/complete', ['user_ids' => [$official->id]])->assertUnprocessable()->assertJsonValidationErrors('user_ids');
        $payload = ['user_ids' => [$official->id, $publisher->id]];
        $this->postJson('/api/onboarding/complete', $payload)->assertOk()->assertJsonPath('data.onboarding_step', 8);
        $this->postJson('/api/onboarding/complete', $payload)->assertOk();
        $this->assertDatabaseHas('users', ['id' => $me->id, 'onboarding_completed_at' => now()]);
        $this->assertSame(2, $me->following()->count());
    }

    public function test_empty_launch_suggestions_do_not_trap_users_and_blocked_accounts_cannot_be_selected(): void
    {
        $me = User::factory()->create(['onboarding_step' => 7]);
        Sanctum::actingAs($me);
        $this->getJson('/api/onboarding/suggestions')->assertOk()->assertJsonPath('meta.minimum_to_follow', 0);
        $this->postJson('/api/onboarding/complete', ['user_ids' => []])->assertOk();
        $me->forceFill(['onboarding_completed_at' => null])->save();
        $other = User::factory()->create();
        $this->postJson('/api/settings/blocked-accounts/'.$other->username)->assertOk();
        $this->postJson('/api/onboarding/complete', ['user_ids' => [$other->id]])->assertUnprocessable();
        $this->assertSame(0, $me->following()->count());
    }

    public function test_phone_account_can_update_personal_details_without_adding_email(): void
    {
        $me = User::factory()->create(['email' => null, 'phone' => '+255712345678']);
        Sanctum::actingAs($me);
        $this->patchJson('/api/profile', ['name' => 'New display name', 'email' => null])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $me->id, 'email' => null, 'name' => 'New display name']);
    }

    public function test_setup_endpoints_require_authentication_and_duplicate_follow_ids_are_rejected(): void
    {
        $this->getJson('/api/onboarding/suggestions')->assertUnauthorized();
        $this->patchJson('/api/onboarding/progress', ['step' => 5])->assertUnauthorized();
        $this->postJson('/api/onboarding/complete', ['user_ids' => []])->assertUnauthorized();
        $me = User::factory()->create(['onboarding_step' => 7]);
        $other = User::factory()->create();
        Sanctum::actingAs($me);
        $this->postJson('/api/onboarding/complete', ['user_ids' => [$other->id, $other->id]])->assertUnprocessable();
        $this->assertDatabaseCount('follows', 0);
    }
}

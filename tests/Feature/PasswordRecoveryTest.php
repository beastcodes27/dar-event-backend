<?php

namespace Tests\Feature;

use App\Models\AccountRecovery;
use App\Models\User;
use App\Notifications\PasswordRecoveryCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'smtp', 'services.textify.key' => 'test-key', 'services.textify.sender_name' => 'Veylo']);
        Notification::fake();
        Http::preventStrayRequests();
    }

    private function findAccount(User $user, ?string $identifier = null): string
    {
        RateLimiter::clear('password-recovery-send:'.$user->id);

        return $this->postJson('/api/auth/recovery/find', ['identifier' => $identifier ?? $user->email])->assertOk()->json('data.recovery_token');
    }

    private function emailCode(User $user, string $token): string
    {
        $this->postJson('/api/auth/recovery/send', ['recovery_token' => $token, 'channel' => 'email'])->assertOk()->assertJsonPath('data.expires_in', 600);

        return Notification::sent($user, PasswordRecoveryCode::class)->last()->code;
    }

    private function verifyCode(string $token, string $code): string
    {
        return $this->postJson('/api/auth/recovery/verify', ['recovery_token' => $token, 'code' => $code])->assertOk()->json('data.reset_token');
    }

    private function resetBody(string $token, string $reset): array
    {
        return ['recovery_token' => $token, 'reset_token' => $reset, 'password' => 'new-secret123', 'password_confirmation' => 'new-secret123'];
    }

    public function test_email_recovery_requires_a_code_and_revokes_existing_sessions(): void
    {
        $user = User::factory()->create(['password' => 'old-secret', 'remember_token' => 'old-remember']);
        $user->createToken('phone');
        $user->createToken('tablet');
        $token = $this->findAccount($user);
        $this->postJson('/api/auth/recovery/reset', $this->resetBody($token, str_repeat('x', 64)))->assertUnprocessable();
        $code = $this->emailCode($user, $token);
        $challenge = AccountRecovery::first();
        $this->assertNotEquals($code, $challenge->code_hash);
        $this->assertNotEquals($token, $challenge->token_hash);
        $reset = $this->verifyCode($token, $code);
        $this->postJson('/api/auth/recovery/reset', $this->resetBody($token, $reset))->assertOk()->assertJsonMissingPath('token');
        $this->assertTrue(Hash::check('new-secret123', $user->fresh()->password));
        $this->assertEquals(1, $user->fresh()->auth_session_version);
        $this->assertNotEquals('old-remember', $user->fresh()->remember_token);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson('/api/auth/recovery/reset', $this->resetBody($token, $reset))->assertUnprocessable();
        $this->postJson('/api/auth/login', ['login' => $user->email, 'password' => 'new-secret123'])->assertOk();
    }

    public function test_phone_recovery_uses_textify_with_international_receiver_and_no_public_code(): void
    {
        Http::fake(['https://portal.textify.africa/api/v1/messages' => Http::response(['success' => true], 201)]);
        $user = User::factory()->create(['email' => null, 'phone' => '+255712345678']);
        $token = $this->findAccount($user, '+255 712 345 678');
        $this->postJson('/api/auth/recovery/send', ['recovery_token' => $token, 'channel' => 'sms'])->assertOk()->assertJsonMissingPath('data.code');
        $code = null;
        Http::assertSent(function ($request) use (&$code): bool {
            preg_match('/code is ([0-9]{6})/', $request['messages'][0]['content'], $match);
            $code = $match[1] ?? null;

            return $request->hasHeader('Authorization', 'Bearer test-key') && $request['messages'][0]['receiver'] === '255712345678' && $request['channel'] === 'sms' && $request['sender_name'] === 'Veylo' && $code !== null;
        });
        $reset = $this->verifyCode($token, $code);
        $this->postJson('/api/auth/recovery/reset', $this->resetBody($token, $reset))->assertOk();
        Notification::assertNothingSent();
    }

    public function test_lookup_masks_private_contacts_and_does_not_send_before_confirmation(): void
    {
        $user = User::factory()->create(['name' => 'Hidden Person', 'discoverable' => false, 'phone' => '+255712345678']);
        $response = $this->postJson('/api/auth/recovery/find', ['identifier' => strtoupper($user->email)])->assertOk()->assertJsonPath('data.account.username', null)->assertJsonMissingPath('data.account.id');
        $this->assertStringNotContainsString($user->email, $response->getContent());
        $this->assertStringNotContainsString($user->phone, $response->getContent());
        Notification::assertNothingSent();
        Http::assertNothingSent();
        $this->postJson('/api/auth/recovery/find', ['identifier' => 'missing@example.com'])->assertNotFound();
        $this->postJson('/api/auth/recovery/find', ['identifier' => 'username'])->assertUnprocessable()->assertJsonValidationErrors('identifier');
    }

    public function test_wrong_codes_are_persisted_and_five_attempts_lock_the_challenge(): void
    {
        $user = User::factory()->create();
        $token = $this->findAccount($user);
        $this->emailCode($user, $token);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/auth/recovery/verify', ['recovery_token' => $token, 'code' => '000000'])->assertUnprocessable();
            $this->assertEquals($attempt, AccountRecovery::first()->attempts);
        }
        $this->assertNotNull(AccountRecovery::first()->consumed_at);
        $this->assertNull(AccountRecovery::first()->code_hash);
    }

    public function test_codes_expire_and_resending_is_throttled(): void
    {
        $user = User::factory()->create();
        $token = $this->findAccount($user);
        $code = $this->emailCode($user, $token);
        $this->postJson('/api/auth/recovery/send', ['recovery_token' => $token, 'channel' => 'email'])->assertTooManyRequests();
        $this->travel(11)->minutes();
        $this->postJson('/api/auth/recovery/verify', ['recovery_token' => $token, 'code' => $code])->assertUnprocessable();
    }

    public function test_reset_tokens_cannot_be_swapped_between_accounts_and_changed_contacts_invalidate_them(): void
    {
        $one = User::factory()->create();
        $two = User::factory()->create();
        $tokenOne = $this->findAccount($one);
        $codeOne = $this->emailCode($one, $tokenOne);
        $tokenTwo = $this->findAccount($two);
        $codeTwo = $this->emailCode($two, $tokenTwo);
        $resetOne = $this->verifyCode($tokenOne, $codeOne);
        $resetTwo = $this->verifyCode($tokenTwo, $codeTwo);
        $this->postJson('/api/auth/recovery/reset', $this->resetBody($tokenOne, $resetTwo))->assertUnprocessable();
        $one->update(['email' => 'changed@example.com']);
        $this->postJson('/api/auth/recovery/reset', $this->resetBody($tokenOne, $resetOne))->assertUnprocessable();
        $this->postJson('/api/auth/recovery/reset', $this->resetBody($tokenTwo, $resetTwo))->assertOk();
    }

    public function test_a_new_code_invalidates_older_requests_for_the_same_account(): void
    {
        $user = User::factory()->create();
        $first = $this->findAccount($user);
        $code = $this->emailCode($user, $first);
        $second = $this->findAccount($user);
        $this->emailCode($user, $second);
        $this->postJson('/api/auth/recovery/verify', ['recovery_token' => $first, 'code' => $code])->assertUnprocessable();
    }

    public function test_missing_configuration_and_provider_failures_do_not_pretend_delivery(): void
    {
        $user = User::factory()->create(['phone' => '+255712345678']);
        $token = $this->findAccount($user);
        config(['services.textify.key' => null]);
        $this->postJson('/api/auth/recovery/send', ['recovery_token' => $token, 'channel' => 'sms'])->assertStatus(503);
        Http::assertNothingSent();
        config(['services.textify.key' => 'test-key']);
        Http::fake(['*' => Http::response(['success' => false, 'message' => 'private-provider-detail'], 401)]);
        $this->postJson('/api/auth/recovery/send', ['recovery_token' => $token, 'channel' => 'sms'])->assertStatus(503)->assertDontSee('private-provider-detail');
        $this->assertNotNull(AccountRecovery::first()->consumed_at);
        $token = $this->findAccount($user);
        config(['mail.default' => 'log']);
        $this->postJson('/api/auth/recovery/send', ['recovery_token' => $token, 'channel' => 'email'])->assertStatus(503);
        Notification::assertNothingSent();
    }

    public function test_password_reset_does_not_remove_an_account_ban(): void
    {
        $user = User::factory()->create(['ban_status' => 'permanently_banned']);
        $token = $this->findAccount($user);
        $code = $this->emailCode($user, $token);
        $reset = $this->verifyCode($token, $code);
        $this->postJson('/api/auth/recovery/reset', $this->resetBody($token, $reset))->assertOk();
        $this->assertEquals('permanently_banned', $user->fresh()->ban_status);
        $this->postJson('/api/auth/login', ['login' => $user->email, 'password' => 'new-secret123'])->assertForbidden()->assertJsonPath('code', 'account_banned');
    }

    public function test_old_browser_sessions_cannot_access_the_portal_after_a_reset(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $token = $this->findAccount($user);
        $code = $this->emailCode($user, $token);
        $reset = $this->verifyCode($token, $code);
        $this->postJson('/api/auth/recovery/reset', $this->resetBody($token, $reset))->assertOk();
        $this->actingAs($user->fresh(), 'web')->withSession(['account_ban_version' => 0, 'account_auth_version' => 0])->get('/admin')->assertRedirect(route('admin.login'));
        $this->assertGuest('web');
    }
}

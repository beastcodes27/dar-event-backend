<?php

namespace Tests\Feature;

use App\Models\BanAppeal;
use App\Models\User;
use App\Support\AccountBans;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountBanTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function reviewToken(User $user): string
    {
        return $this->postJson('/api/auth/login', ['login' => $user->email, 'password' => 'password'])
            ->assertForbidden()->assertJsonPath('code', 'account_banned')->json('ban.review_token');
    }

    public function test_administrator_ban_revokes_every_token_database_session_and_remember_login(): void
    {
        $this->freezeTime();
        $admin = $this->admin();
        $user = User::factory()->create(['remember_token' => 'previous-cookie']);
        $first = $user->createToken('phone')->plainTextToken;
        $user->createToken('tablet');
        DB::table('sessions')->insert(['id' => 'old-browser', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        $this->actingAs($admin, 'web');

        $this->patch('/admin/users/'.$user->id.'/ban', ['reason' => 'Repeated spam posts.'])->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'ban_status' => 'banned', 'ban_version' => 1, 'banned_by' => $admin->id, 'ban_reason' => 'Repeated spam posts.']);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertNotSame('previous-cookie', $user->fresh()->remember_token);
        Auth::forgetGuards();
        $this->withToken($first)->getJson('/api/profile')->assertUnauthorized();
    }

    public function test_only_an_administrator_can_ban_and_self_banning_is_refused(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $this->patch('/admin/users/'.$user->id.'/ban', ['reason' => 'Spam account.'])->assertRedirect('/admin/login');
        $this->actingAs($user, 'web')->patch('/admin/users/'.$admin->id.'/ban', ['reason' => 'Spam account.'])->assertForbidden();
        $this->actingAs($admin, 'web')->patch('/admin/users/'.$user->id.'/ban', [])->assertSessionHasErrors('reason');
        $this->patch('/admin/users/'.$admin->id.'/ban', ['reason' => 'Spam account.'])->assertSessionHasErrors('reason');
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'ban_status' => 'active']);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'ban_status' => 'active']);
    }

    public function test_ban_information_requires_the_correct_password_and_never_issues_an_access_token(): void
    {
        $user = User::factory()->banned()->create();
        $this->postJson('/api/auth/login', ['login' => $user->email, 'password' => 'incorrect'])->assertUnprocessable()->assertJsonValidationErrors('login');
        $token = $this->reviewToken($user);
        $this->assertNotEmpty($token);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->withToken($token)->getJson('/api/profile')->assertUnauthorized();
    }

    public function test_banned_authenticated_users_are_denied_api_and_web_access(): void
    {
        $user = User::factory()->banned()->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/profile')->assertForbidden()->assertJsonPath('code', 'account_banned');
        Auth::forgetGuards();
        $this->actingAs($user, 'web');
        $this->get('/subscriptions')->assertRedirect('/account/banned');
        $this->assertGuest('web');
    }

    public function test_review_request_uses_limited_token_is_idempotent_and_ignores_privileged_fields(): void
    {
        $user = User::factory()->banned()->create();
        $other = User::factory()->create();
        $token = $this->reviewToken($user);
        $data = ['review_token' => $token, 'message' => 'Please review my account ban.', 'user_id' => $other->id, 'status' => 'accepted'];
        $this->postJson('/api/auth/ban-review', $data)->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->postJson('/api/auth/ban-review', $data)->assertCreated();
        $this->assertDatabaseCount('ban_appeals', 1);
        $this->assertDatabaseHas('ban_appeals', ['user_id' => $user->id, 'ban_version' => 1, 'status' => 'pending', 'message' => $data['message']]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'ban_status' => 'banned']);
        $this->postJson('/api/auth/login', ['login' => $user->email, 'password' => 'password'])->assertForbidden()->assertJsonPath('ban.appeal_status', 'pending')->assertJsonPath('ban.can_request_review', false);
    }

    public function test_invalid_expired_tokens_and_short_review_messages_are_rejected(): void
    {
        $this->freezeTime();
        $user = User::factory()->banned()->create();
        $token = $this->reviewToken($user);
        $this->postJson('/api/auth/ban-review', ['review_token' => 'forged', 'message' => 'Please review this ban.'])->assertUnprocessable()->assertJsonValidationErrors('review_token');
        $this->postJson('/api/auth/ban-review', ['review_token' => $token, 'message' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->travel(31)->minutes();
        $this->postJson('/api/auth/ban-review', ['review_token' => $token, 'message' => 'Please review this ban.'])->assertUnprocessable()->assertJsonValidationErrors('review_token');
        $this->assertDatabaseCount('ban_appeals', 0);
    }

    public function test_accepting_a_review_restores_new_logins_without_reviving_old_access(): void
    {
        $this->freezeTime();
        $admin = $this->admin();
        $user = User::factory()->create();
        $oldToken = $user->createToken('old-phone')->plainTextToken;
        app(AccountBans::class)->ban($user, $admin, 'Community rule violations.');
        $token = $this->reviewToken($user);
        $appeal = app(AccountBans::class)->submit($token, 'Please review my account ban.');
        $this->actingAs($admin, 'web');

        $this->patch('/admin/appeals/'.$appeal->id, ['status' => 'accepted', 'decision_reason' => 'Ban issued in error.'])->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'ban_status' => 'active']);
        $this->assertDatabaseHas('ban_appeals', ['id' => $appeal->id, 'status' => 'accepted', 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
        $this->patch('/admin/appeals/'.$appeal->id, ['status' => 'rejected', 'decision_reason' => 'Second decision.'])->assertConflict();
        Auth::forgetGuards();
        $this->withToken($oldToken)->getJson('/api/profile')->assertUnauthorized();
        $new = $this->postJson('/api/auth/login', ['login' => $user->email, 'password' => 'password'])->assertOk()->json('token');
        $this->withToken($new)->getJson('/api/profile')->assertOk();
    }

    public function test_rejected_review_permanently_bans_and_cannot_be_reopened(): void
    {
        $admin = $this->admin();
        $user = User::factory()->banned()->create();
        $token = $this->reviewToken($user);
        $appeal = app(AccountBans::class)->submit($token, 'Please review my account ban.');
        $this->actingAs($admin, 'web');
        $this->patch('/admin/appeals/'.$appeal->id, ['status' => 'rejected', 'decision_reason' => 'Violations confirmed.'])->assertRedirect();
        $this->patch('/admin/users/'.$user->id.'/ban', ['reason' => 'Reset this ban.'])->assertConflict();
        $this->patch('/admin/appeals/'.$appeal->id, ['status' => 'accepted', 'decision_reason' => 'Reopen account.'])->assertConflict();
        Auth::forgetGuards();
        $this->postJson('/api/auth/login', ['login' => $user->email, 'password' => 'password'])->assertForbidden()->assertJsonPath('ban.status', 'permanently_banned')->assertJsonPath('ban.can_request_review', false)->assertJsonPath('ban.review_note', 'Violations confirmed.');
        $this->postJson('/api/auth/ban-review', ['review_token' => $token, 'message' => 'Another review request.'])->assertForbidden();
        $this->assertDatabaseCount('ban_appeals', 1);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'ban_status' => 'permanently_banned']);
    }

    public function test_old_review_token_cannot_request_a_review_for_a_later_ban(): void
    {
        $admin = $this->admin();
        $user = User::factory()->banned()->create();
        $token = $this->reviewToken($user);
        $appeal = app(AccountBans::class)->submit($token, 'Please review this restriction.');
        app(AccountBans::class)->review($appeal, $admin, 'accepted', 'Appeal accepted.');
        app(AccountBans::class)->ban($user->fresh(), $admin, 'A later violation.');
        $this->postJson('/api/auth/ban-review', ['review_token' => $token, 'message' => 'Please review this restriction.'])->assertForbidden();
        $this->postJson('/api/auth/ban-review', ['review_token' => $this->reviewToken($user), 'message' => 'Please review this later ban.'])->assertCreated();
        $this->assertDatabaseCount('ban_appeals', 2);
    }

    public function test_review_queue_and_decisions_require_administrator_access(): void
    {
        $appeal = BanAppeal::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $this->get('/admin/appeals')->assertForbidden();
        $this->patch('/admin/appeals/'.$appeal->id, ['status' => 'accepted', 'decision_reason' => 'Restore my friend.'])->assertForbidden();
        $this->assertDatabaseHas('ban_appeals', ['id' => $appeal->id, 'status' => 'pending']);
        $this->assertDatabaseHas('users', ['id' => $appeal->user_id, 'ban_status' => 'banned']);
    }

    public function test_web_login_shows_ban_and_allows_a_review_without_a_normal_session(): void
    {
        $user = User::factory()->banned()->create(['ban_reason' => '<script>bad()</script>']);
        $this->post('/account/login', ['login' => $user->email, 'password' => 'password'])->assertRedirect('/account/banned');
        $this->assertGuest('web');
        $this->get('/account/banned')->assertSee('Account banned')->assertSee('&lt;script&gt;bad()&lt;/script&gt;', false)->assertDontSee('<script>bad()</script>', false);
        $token = session('ban.review_token');
        $this->post('/account/ban-review', ['review_token' => $token, 'message' => '<script>Review this ban</script>'])->assertRedirect('/account/banned');
        $this->get('/account/banned')->assertSee('Your review request is pending');
        User::factory()->create();
        $this->actingAs($this->admin(), 'web');
        $this->get('/admin/appeals')->assertSee('&lt;script&gt;Review this ban&lt;/script&gt;', false)->assertDontSee('<script>Review this ban</script>', false);
        $this->get('/admin/users')->assertOk()->assertSee('Ban account');
    }

    public function test_web_sessions_from_before_a_ban_stay_invalid_after_an_accepted_review(): void
    {
        $user = User::factory()->create(['ban_version' => 1]);
        $this->actingAs($user, 'web')->withSession(['account_ban_version' => 0]);
        $this->get('/subscriptions')->assertRedirect('/account/login');
        $this->assertGuest('web');
        $this->post('/account/login', ['login' => $user->email, 'password' => 'password'])->assertRedirect('/subscriptions');
        $this->assertAuthenticatedAs($user, 'web');
        $this->get('/subscriptions')->assertOk();
    }
}

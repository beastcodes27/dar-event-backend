<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_require_authentication(): void
    {
        foreach (['settings/activity', 'settings/archived-posts', 'settings/blocked-accounts', 'settings/support', 'admin/support'] as $path) {
            $this->getJson('/api/'.$path)->assertUnauthorized();
        }
        $this->patchJson('/api/settings/privacy', [])->assertUnauthorized();
        $this->patchJson('/api/settings/theme', ['theme_preference' => 'dark'])->assertUnauthorized();
    }

    public function test_theme_preferences_are_validated_persisted_and_private(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        foreach (['dark', 'light', 'system'] as $theme) {
            $this->patchJson('/api/settings/theme', ['theme_preference' => $theme])->assertOk()->assertJsonPath('data.theme_preference', $theme);
            $this->getJson('/api/profile')->assertJsonPath('data.theme_preference', $theme);
            $this->assertDatabaseHas('users', ['id' => $user->id, 'theme_preference' => $theme]);
        }
        $this->patchJson('/api/settings/theme', ['theme_preference' => 'invalid'])->assertUnprocessable();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/users/'.$user->username)->assertJsonMissingPath('data.theme_preference');
    }

    public function test_archiving_hides_a_post_everywhere_and_only_its_owner_can_restore_it(): void
    {
        $owner = User::factory()->publisher()->create();
        $other = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $owner->id, 'title' => 'Archive Example']);
        $other->following()->attach($owner->id);
        $other->savedEvents()->attach($event->id);
        Sanctum::actingAs($owner);
        $this->patchJson('/api/settings/posts/'.$event->id.'/archive', ['archived' => true])->assertOk();
        $this->getJson('/api/my-events')->assertJsonCount(0, 'data');
        $this->getJson('/api/settings/archived-posts')->assertJsonPath('data.0.id', $event->id);
        Sanctum::actingAs($other);
        foreach (['events', 'feed', 'saved-events'] as $path) {
            $this->getJson('/api/'.$path)->assertOk()->assertJsonCount(0, 'data');
        }
        $this->getJson('/api/search?q=Archive')->assertJsonCount(0, 'data.events');
        $this->getJson('/api/events/'.$event->id)->assertNotFound();
        $this->getJson('/api/events/'.$event->id.'/comments')->assertNotFound();
        $this->postJson('/api/events/'.$event->id.'/like')->assertNotFound();
        $this->patchJson('/api/settings/posts/'.$event->id.'/archive', ['archived' => false])->assertNotFound();
        $this->getJson('/api/settings/archived-posts')->assertJsonCount(0, 'data');
        Sanctum::actingAs($owner);
        $this->patchJson('/api/settings/posts/'.$event->id.'/archive', ['archived' => false])->assertOk();
        $this->getJson('/api/events/'.$event->id)->assertOk();
    }

    public function test_archiving_preserves_private_visibility_and_does_not_reuse_slugs(): void
    {
        $owner = User::factory()->publisher()->create();
        $event = Event::factory()->private()->create(['user_id' => $owner->id, 'title' => 'Same title', 'slug' => 'same-title']);
        Sanctum::actingAs($owner);
        $this->patchJson('/api/settings/posts/'.$event->id.'/archive', ['archived' => true])->assertOk();
        $this->postJson('/api/events', ['title' => 'Same title', 'category' => 'music', 'location' => 'Dar', 'starts_at' => now()->addDay()->toIso8601String()])
            ->assertCreated()->assertJsonPath('data.slug', 'same-title-2');
        $this->patchJson('/api/settings/posts/'.$event->id.'/archive', ['archived' => false])->assertOk();
        $this->assertDatabaseHas('events', ['id' => $event->id, 'is_public' => false, 'archived_at' => null]);
    }

    public function test_privacy_preferences_are_persisted_and_enforced(): void
    {
        $owner = User::factory()->create(['username' => 'privateperson']);
        $other = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->patchJson('/api/settings/privacy', ['discoverable' => false, 'allow_messages' => false])
            ->assertOk()->assertJsonPath('data.discoverable', false)->assertJsonPath('data.allow_messages', false);
        Sanctum::actingAs($other);
        $this->getJson('/api/search?q=privateperson&type=users')->assertJsonCount(0, 'data.users');
        $this->getJson('/api/users/privateperson')->assertOk()->assertJsonMissingPath('data.allow_messages');
        $this->postJson('/api/users/privateperson/messages', ['body' => 'Hi'])->assertForbidden();
        Sanctum::actingAs($owner->fresh());
        $this->patchJson('/api/settings/privacy', ['discoverable' => true, 'allow_messages' => true])->assertOk();
        Sanctum::actingAs($other);
        $this->getJson('/api/search?q=privateperson&type=users')->assertJsonCount(1, 'data.users');
        $this->postJson('/api/users/privateperson/messages', ['body' => 'Hi'])->assertCreated();
    }

    public function test_blocks_are_idempotent_remove_follows_and_apply_in_both_directions(): void
    {
        $owner = User::factory()->create();
        $blocked = User::factory()->create();
        $owner->following()->attach($blocked->id);
        $blocked->following()->attach($owner->id);
        $event = Event::factory()->create(['user_id' => $blocked->id]);
        $ownEvent = Event::factory()->create(['user_id' => $owner->id]);
        $message = Message::factory()->create(['sender_id' => $blocked->id, 'recipient_id' => $owner->id]);
        Sanctum::actingAs($owner);
        $path = '/api/settings/blocked-accounts/'.$blocked->username;
        $this->postJson($path)->assertOk();
        $this->postJson($path)->assertOk();
        $this->assertDatabaseCount('account_blocks', 1);
        $this->assertDatabaseCount('follows', 0);
        $this->getJson('/api/settings/blocked-accounts')->assertJsonPath('data.0.id', $blocked->id);
        $this->getJson('/api/users/'.$blocked->username)->assertNotFound();
        $this->getJson('/api/events/'.$event->id)->assertNotFound();
        $this->getJson('/api/search?q='.$blocked->username)->assertJsonCount(0, 'data.users');
        $this->getJson('/api/messages')->assertJsonCount(0, 'data');
        $this->getJson('/api/messages/unread-count')->assertJsonPath('count', 0);
        $this->getJson('/api/users/'.$blocked->username.'/events')->assertNotFound();
        $this->getJson('/api/feed')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ownEvent->id);
        $this->postJson('/api/users/'.$blocked->username.'/messages', ['body' => 'Blocked both ways'])->assertForbidden();
        $this->postJson('/api/users/'.$blocked->username.'/follow')->assertForbidden();
        Sanctum::actingAs($blocked);
        $this->postJson('/api/users/'.$owner->username.'/messages', ['body' => 'Hi'])->assertForbidden();
        $this->getJson('/api/users/'.$owner->username)->assertNotFound();
        $this->getJson('/api/users/'.$owner->username.'/messages')->assertForbidden();
        $this->getJson('/api/users/'.$owner->username.'/events')->assertNotFound();
        $this->getJson('/api/events/'.$ownEvent->id)->assertNotFound();
        $this->getJson('/api/feed')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $event->id);
        $this->patchJson('/api/messages/'.$message->id, ['body' => 'Cannot edit across a block'])->assertForbidden();
        Sanctum::actingAs($owner);
        $this->deleteJson($path)->assertOk();
        $this->getJson('/api/users/'.$blocked->username)->assertOk();
        $this->assertDatabaseCount('follows', 0);
        $this->postJson('/api/settings/blocked-accounts/'.$owner->username)->assertUnprocessable();
    }

    public function test_support_requests_are_private_and_only_admins_can_reply(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();
        Sanctum::actingAs($owner);
        $id = $this->postJson('/api/settings/support', ['subject' => 'Need help', 'body' => 'My verification is pending.', 'user_id' => $other->id, 'status' => 'resolved'])
            ->assertCreated()->json('data.id');
        $this->assertDatabaseHas('support_requests', ['id' => $id, 'user_id' => $owner->id, 'status' => 'open']);
        $this->getJson('/api/settings/support')->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/support')->assertForbidden();
        $this->patchJson('/api/admin/support/'.$id, ['admin_reply' => 'Fake reply', 'status' => 'resolved'])->assertForbidden();
        Sanctum::actingAs($other);
        $this->getJson('/api/settings/support')->assertJsonCount(0, 'data');
        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/support')->assertJsonPath('data.0.id', $id);
        $this->patchJson('/api/admin/support/'.$id, ['admin_reply' => 'We have reviewed your request.', 'status' => 'resolved'])->assertOk();
        Sanctum::actingAs($owner);
        $this->getJson('/api/settings/support')->assertJsonPath('data.0.status', 'resolved')->assertJsonPath('data.0.admin_reply', 'We have reviewed your request.');
        $this->postJson('/api/settings/support', ['subject' => '', 'body' => ''])->assertUnprocessable();
    }

    public function test_activity_is_scoped_to_current_user_and_other_sessions_can_be_revoked(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $current = $user->createToken('Current device');
        $user->createToken('Old device');
        $other->createToken('Someone else');
        Event::factory()->create(['user_id' => $user->id]);
        $this->withToken($current->plainTextToken)->getJson('/api/settings/activity')->assertOk()
            ->assertJsonPath('data.posts', 1)->assertJsonCount(2, 'data.sessions')->assertJsonMissingPath('data.sessions.0.token');
        $this->withToken($current->plainTextToken)->deleteJson('/api/settings/sessions')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->accessToken->id]);
    }

    public function test_regular_users_cannot_promote_themselves_through_profile_or_privacy(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->patchJson('/api/profile', ['name' => $user->name, 'is_admin' => true])->assertOk();
        $this->patchJson('/api/settings/privacy', ['discoverable' => true, 'allow_messages' => true, 'is_admin' => true])->assertOk();
        $this->assertFalse($user->fresh()->is_admin);
    }

    public function test_guests_cannot_list_private_events_on_another_profile(): void
    {
        $event = Event::factory()->private()->create();
        $this->getJson('/api/users/'.$event->host->username.'/events')->assertOk()->assertJsonCount(0, 'data');
    }
}

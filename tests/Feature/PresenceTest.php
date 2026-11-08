<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PresenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_presence_requires_authentication_and_only_updates_the_current_session(): void
    {
        $this->patchJson('/api/presence', ['active' => true])->assertUnauthorized();
        $user = User::factory()->create();
        $token = $user->createToken('Phone');
        $other = $user->createToken('Tablet');
        $this->withToken($token->plainTextToken)->patchJson('/api/presence', ['active' => true])->assertOk();
        $this->assertNotNull($token->accessToken->fresh()->last_active_at);
        $this->assertNull($other->accessToken->fresh()->last_active_at);
        $this->patchJson('/api/presence', ['active' => 'invalid'])->assertUnprocessable();
        $this->patchJson('/api/presence', ['active' => false])->assertOk();
        $this->assertNull($token->accessToken->fresh()->last_active_at);
    }

    public function test_online_status_expires_and_another_active_device_keeps_the_user_online(): void
    {
        $peer = User::factory()->create();
        $viewer = User::factory()->create();
        $phone = $peer->createToken('Phone')->accessToken;
        $tablet = $peer->createToken('Tablet')->accessToken;
        $phone->forceFill(['last_active_at' => now()])->save();
        $this->assertTrue($peer->onlineStatusFor($viewer));
        $this->travel(61)->seconds();
        $this->assertFalse($peer->onlineStatusFor($viewer));
        $tablet->forceFill(['last_active_at' => now()])->save();
        $this->assertTrue($peer->onlineStatusFor($viewer));
        $phone->forceFill(['last_active_at' => null])->save();
        $this->assertTrue($peer->onlineStatusFor($viewer));
        $tablet->delete();
        $this->assertFalse($peer->onlineStatusFor($viewer));
        $phone->forceFill(['last_active_at' => now(), 'expires_at' => now()->subSecond()])->save();
        $this->assertFalse($peer->onlineStatusFor($viewer));
    }

    public function test_visibility_is_enforced_in_inbox_chat_search_and_profiles(): void
    {
        $peer = User::factory()->create(['username' => 'online_peer']);
        $viewer = User::factory()->create();
        $peer->createToken('Phone')->accessToken->forceFill(['last_active_at' => now()])->save();
        Message::factory()->create(['sender_id' => $peer->id, 'recipient_id' => $viewer->id]);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/messages')->assertOk()->assertJsonPath('data.0.user.is_online', true);
        $this->getJson('/api/users/online_peer/messages')->assertOk()->assertJsonPath('meta.is_online', true);
        $peer->update(['online_status_visibility' => 'following']);
        $this->getJson('/api/messages')->assertJsonPath('data.0.user.is_online', null);
        $peer->following()->attach($viewer->id);
        $this->getJson('/api/users/online_peer/messages')->assertJsonPath('meta.is_online', true);
        $peer->update(['online_status_visibility' => 'nobody']);
        $this->getJson('/api/messages')->assertJsonPath('data.0.user.is_online', null);
        $this->getJson('/api/users/online_peer/messages')->assertJsonPath('meta.is_online', null);
        $this->getJson('/api/search?type=users&q=online_peer')->assertJsonPath('data.users.0.is_online', null);
        $this->getJson('/api/users/online_peer')->assertJsonPath('data.is_online', null)->assertJsonMissingPath('data.online_status_visibility');
        $peer->update(['online_status_visibility' => 'everyone']);
        DB::table('account_blocks')->insert(['user_id' => $viewer->id, 'blocked_user_id' => $peer->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertNull($peer->onlineStatusFor($viewer));
        $this->assertNull($peer->onlineStatusFor(null));
        $this->getJson('/api/users/online_peer/messages')->assertForbidden();
    }

    public function test_online_visibility_preferences_are_validated_and_persisted(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        foreach (['nobody', 'following', 'everyone'] as $visibility) {
            $this->patchJson('/api/settings/privacy', ['discoverable' => true, 'allow_messages' => true, 'online_status_visibility' => $visibility])
                ->assertOk()->assertJsonPath('data.online_status_visibility', $visibility);
            $this->assertDatabaseHas('users', ['id' => $user->id, 'online_status_visibility' => $visibility]);
        }
        $this->patchJson('/api/settings/privacy', ['discoverable' => true, 'allow_messages' => true, 'online_status_visibility' => 'invalid'])
            ->assertUnprocessable();

        $this->patchJson('/api/settings/privacy', ['discoverable' => false, 'allow_messages' => true])->assertOk();
        $this->getJson('/api/profile')->assertJsonPath('data.online_status_visibility', 'everyone');
    }
}

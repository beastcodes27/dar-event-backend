<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessagePrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_preferences_are_validated_persisted_and_private(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        foreach (['followers', 'requests', 'everyone'] as $policy) {
            $this->patchJson('/api/settings/privacy', ['discoverable' => true, 'allow_messages' => true, 'message_policy' => $policy])
                ->assertOk()->assertJsonPath('data.message_policy', $policy);
            $this->assertDatabaseHas('users', ['id' => $owner->id, 'message_policy' => $policy]);
        }
        $this->patchJson('/api/settings/privacy', ['discoverable' => true, 'allow_messages' => true, 'message_policy' => 'invalid'])->assertUnprocessable();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/users/'.$owner->username)->assertJsonMissingPath('data.message_policy');
    }

    public function test_followers_only_checks_the_sender_follows_the_recipient(): void
    {
        $owner = User::factory()->create(['message_policy' => 'followers']);
        $sender = User::factory()->create();
        $owner->following()->attach($sender->id);
        Sanctum::actingAs($sender);
        $path = '/api/users/'.$owner->username.'/messages';
        $this->getJson($path)->assertJsonPath('meta.message_permission', 'followers_only');
        $this->postJson($path, ['body' => 'Blocked'])->assertForbidden();
        $sender->following()->attach($owner->id);
        $this->postJson($path, ['body' => 'Hello'])->assertCreated();
        $sender->following()->detach($owner->id);
        $this->postJson($path, ['body' => 'Again'])->assertForbidden();
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_a_request_is_separate_from_chats_and_unread_messages_until_accepted(): void
    {
        $owner = User::factory()->create(['message_policy' => 'requests']);
        $sender = User::factory()->create();
        Sanctum::actingAs($sender);
        $path = '/api/users/'.$owner->username.'/messages';
        $this->getJson($path)->assertJsonPath('meta.message_permission', 'request_required');
        $this->postJson($path, ['body' => 'Hello'])->assertCreated()->assertJsonPath('meta.message_permission', 'request_pending');
        $this->postJson($path, ['body' => 'Spam'])->assertForbidden();
        $this->assertDatabaseCount('messages', 1);
        $this->getJson('/api/messages')->assertJsonCount(1, 'data');
        Sanctum::actingAs($owner);
        $this->getJson('/api/messages')->assertJsonCount(0, 'data');
        $this->getJson('/api/messages?folder=requests')->assertOk()->assertJsonPath('data.0.user.id', $sender->id)
            ->assertJsonPath('data.0.message_request.status', 'pending');
        $this->getJson('/api/messages/unread-count')->assertJsonPath('count', 0)->assertJsonPath('request_count', 1);
        $this->getJson('/api/users/'.$sender->username.'/messages')->assertJsonPath('meta.incoming_request.status', 'pending');
        $this->assertNull(Message::first()->read_at);
        $this->postJson('/api/users/'.$sender->username.'/messages', ['body' => 'Reply before accepting'])->assertConflict();
        $id = MessageRequest::first()->id;
        $this->patchJson('/api/message-requests/'.$id, ['status' => 'accepted'])->assertOk();
        $this->getJson('/api/messages')->assertJsonCount(1, 'data');
        $this->getJson('/api/messages?folder=requests')->assertJsonCount(0, 'data');
        $this->getJson('/api/messages/unread-count')->assertJsonPath('count', 1)->assertJsonPath('request_count', 0);
        $this->getJson('/api/users/'.$sender->username.'/messages')->assertJsonPath('meta.incoming_request.status', 'accepted');
        $this->assertNotNull(Message::first()->read_at);
        $this->postJson('/api/users/'.$sender->username.'/messages', ['body' => 'Welcome'])->assertCreated();
        Sanctum::actingAs($sender);
        $this->postJson($path, ['body' => 'Thanks'])->assertCreated();
    }

    public function test_only_the_recipient_can_resolve_a_request_and_declines_stop_repeated_requests(): void
    {
        $owner = User::factory()->create(['message_policy' => 'requests']);
        $sender = User::factory()->create();
        Sanctum::actingAs($sender);
        $path = '/api/users/'.$owner->username.'/messages';
        $this->postJson($path, ['body' => 'Hello'])->assertCreated();
        $id = MessageRequest::first()->id;
        $this->patchJson('/api/message-requests/'.$id, ['status' => 'accepted'])->assertNotFound();
        Sanctum::actingAs(User::factory()->create());
        $this->patchJson('/api/message-requests/'.$id, ['status' => 'accepted'])->assertNotFound();
        Sanctum::actingAs($owner);
        $this->patchJson('/api/message-requests/'.$id, ['status' => 'invalid'])->assertUnprocessable();
        $this->patchJson('/api/message-requests/'.$id, ['status' => 'declined'])->assertOk();
        $this->patchJson('/api/message-requests/'.$id, ['status' => 'declined'])->assertOk();
        $this->patchJson('/api/message-requests/'.$id, ['status' => 'accepted'])->assertConflict();
        $this->postJson('/api/users/'.$sender->username.'/messages', ['body' => 'Closed'])->assertConflict();
        $this->getJson('/api/messages?folder=requests')->assertJsonCount(0, 'data');
        $this->getJson('/api/messages')->assertJsonCount(0, 'data');
        $this->getJson('/api/messages/unread-count')->assertJsonPath('request_count', 0)->assertJsonPath('count', 0);
        Sanctum::actingAs($sender);
        $this->getJson($path)->assertJsonPath('meta.message_permission', 'request_declined');
        $this->postJson($path, ['body' => 'Again'])->assertForbidden();
        $this->assertDatabaseCount('message_requests', 1);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_disabled_messages_and_blocks_override_every_policy_and_hide_requests(): void
    {
        $owner = User::factory()->create(['message_policy' => 'requests']);
        $sender = User::factory()->create();
        Sanctum::actingAs($sender);
        $path = '/api/users/'.$owner->username.'/messages';
        $owner->update(['allow_messages' => false]);
        $this->postJson($path, ['body' => 'No'])->assertForbidden();
        $owner->update(['allow_messages' => true]);
        $this->postJson($path, ['body' => 'Hello'])->assertCreated();
        DB::table('account_blocks')->insert(['user_id' => $owner->id, 'blocked_user_id' => $sender->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson($path, ['body' => 'Blocked'])->assertForbidden();
        Sanctum::actingAs($owner);
        $this->getJson('/api/messages?folder=requests')->assertJsonCount(0, 'data');
        $this->getJson('/api/messages/unread-count')->assertJsonPath('request_count', 0);
        $this->patchJson('/api/message-requests/'.MessageRequest::first()->id, ['status' => 'accepted'])->assertForbidden();
    }

    public function test_requests_do_not_interrupt_a_conversation_the_recipient_already_started(): void
    {
        $owner = User::factory()->create(['message_policy' => 'requests']);
        $sender = User::factory()->create();
        Message::factory()->create(['sender_id' => $owner->id, 'recipient_id' => $sender->id]);
        Sanctum::actingAs($sender);
        $this->postJson('/api/users/'.$owner->username.'/messages', ['body' => 'Reply'])->assertCreated();
        $this->assertDatabaseCount('message_requests', 0);
    }

    public function test_request_endpoints_require_authentication(): void
    {
        $this->getJson('/api/messages?folder=requests')->assertUnauthorized();
        $this->patchJson('/api/message-requests/1', ['status' => 'accepted'])->assertUnauthorized();

    }
}

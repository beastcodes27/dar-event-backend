<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_messages_require_authentication(): void
    {
        $user = User::factory()->create();
        $this->getJson('/api/messages')->assertUnauthorized();
        $this->getJson('/api/messages/unread-count')->assertUnauthorized();
        $this->getJson('/api/users/'.$user->username.'/messages')->assertUnauthorized();
        $this->postJson('/api/users/'.$user->username.'/messages', ['body' => 'Hi'])->assertUnauthorized();
    }

    public function test_messages_are_persisted_and_sender_cannot_be_spoofed(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        Sanctum::actingAs($sender);
        $this->postJson('/api/users/'.$recipient->username.'/messages', [
            'body' => ' Hello there ', 'sender_id' => $recipient->id, 'read_at' => now(),
        ])->assertCreated()->assertJsonPath('data.body', 'Hello there')
            ->assertJsonPath('data.sender_id', $sender->id)->assertJsonPath('data.is_mine', true)
            ->assertJsonPath('data.read_at', null);
        $this->assertDatabaseHas('messages', ['sender_id' => $sender->id, 'recipient_id' => $recipient->id, 'body' => 'Hello there']);
    }

    public function test_messages_validate_body_and_reject_self_messaging(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        Sanctum::actingAs($sender);
        foreach (['', '   ', str_repeat('a', 2001), ['invalid']] as $body) {
            $this->postJson('/api/users/'.$recipient->username.'/messages', ['body' => $body])
                ->assertUnprocessable()->assertJsonValidationErrors('body');
        }
        $this->postJson('/api/users/'.$sender->username.'/messages', ['body' => 'Hi'])->assertForbidden();
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_only_the_current_users_conversation_is_visible_and_marked_read(): void
    {
        $me = User::factory()->create();
        $peer = User::factory()->create();
        $other = User::factory()->create();
        $incoming = Message::factory()->create(['sender_id' => $peer->id, 'recipient_id' => $me->id]);
        $outgoing = Message::factory()->create(['sender_id' => $me->id, 'recipient_id' => $peer->id]);
        $private = Message::factory()->create(['sender_id' => $peer->id, 'recipient_id' => $other->id]);
        Sanctum::actingAs($me);
        $this->getJson('/api/messages/unread-count')->assertOk()->assertJsonPath('count', 1);
        $this->getJson('/api/users/'.$peer->username.'/messages')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $outgoing->id)
            ->assertJsonPath('data.1.id', $incoming->id);
        $this->assertNotNull($incoming->fresh()->read_at);
        $this->assertNull($outgoing->fresh()->read_at);
        $this->assertNull($private->fresh()->read_at);
        $this->getJson('/api/messages/unread-count')->assertJsonPath('count', 0);
        $this->getJson('/api/users/'.$other->username.'/messages')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_inbox_returns_unique_peers_latest_preview_and_unread_counts(): void
    {
        $me = User::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();
        Message::factory()->create(['sender_id' => $first->id, 'recipient_id' => $me->id]);
        Message::factory()->create(['sender_id' => $me->id, 'recipient_id' => $first->id]);
        Message::factory()->create(['sender_id' => $second->id, 'recipient_id' => $me->id, 'body' => 'Latest']);
        Message::factory()->create();
        Sanctum::actingAs($me);
        $this->getJson('/api/messages')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.user.id', $second->id)->assertJsonPath('data.0.last_message', 'Latest')
            ->assertJsonPath('data.0.unread_count', 1)->assertJsonPath('data.1.user.id', $first->id)
            ->assertJsonPath('data.1.unread_count', 1)->assertJsonMissingPath('data.0.user.email');
    }

    public function test_message_history_is_paginated_and_only_loaded_messages_are_marked_read(): void
    {
        $me = User::factory()->create();
        $peer = User::factory()->create();
        Message::factory()->count(35)->create(['sender_id' => $peer->id, 'recipient_id' => $me->id]);
        Sanctum::actingAs($me);
        $this->getJson('/api/users/'.$peer->username.'/messages')->assertOk()->assertJsonCount(30, 'data');
        $this->getJson('/api/messages/unread-count')->assertJsonPath('count', 5);
        $this->getJson('/api/users/'.$peer->username.'/messages?page=2')->assertOk()->assertJsonCount(5, 'data');
        $this->getJson('/api/messages/unread-count')->assertJsonPath('count', 0);
    }
}

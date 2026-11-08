<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessageReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_reply_to_received_and_own_messages_with_persisted_context(): void
    {
        $parent = Message::factory()->create(['body' => 'Are you coming?']);
        Sanctum::actingAs($parent->recipient);
        $path = '/api/users/'.$parent->sender->username.'/messages';
        $response = $this->postJson($path, ['body' => 'Yes!', 'reply_to_id' => $parent->id])->assertCreated()
            ->assertJsonPath('data.reply_to.id', $parent->id)->assertJsonPath('data.reply_to.body', 'Are you coming?')
            ->assertJsonPath('data.reply_to.is_mine', false);
        $replyId = $response->json('data.id');
        $this->assertDatabaseHas('messages', ['id' => $replyId, 'reply_to_id' => $parent->id]);
        $this->postJson($path, ['body' => 'At six', 'reply_to_id' => $replyId])->assertCreated()
            ->assertJsonPath('data.reply_to.body', 'Yes!')->assertJsonPath('data.reply_to.is_mine', true);
        Sanctum::actingAs($parent->sender);
        $this->getJson('/api/users/'.$parent->recipient->username.'/messages')->assertOk()
            ->assertJsonPath('data.1.reply_to.id', $parent->id)->assertJsonPath('data.1.reply_to.is_mine', true);
    }

    public function test_replies_reject_messages_outside_the_thread_missing_messages_and_deleted_copies(): void
    {
        $me = User::factory()->create();
        $peer = User::factory()->create();
        $other = User::factory()->create();
        $foreign = Message::factory()->create(['sender_id' => $me->id, 'recipient_id' => $other->id]);
        $deleted = Message::factory()->create(['sender_id' => $peer->id, 'recipient_id' => $me->id]);
        $hidden = Message::factory()->create(['sender_id' => $peer->id, 'recipient_id' => $me->id]);
        $deleted->forceFill(['deleted_for_everyone_at' => now(), 'body' => ''])->save();
        $hidden->forceFill(['recipient_deleted_at' => now()])->save();
        Sanctum::actingAs($me);
        foreach ([$foreign->id, $deleted->id, $hidden->id, 999999, 'invalid'] as $id) {
            $this->postJson('/api/users/'.$peer->username.'/messages', ['body' => 'Reply', 'reply_to_id' => $id])
                ->assertUnprocessable()->assertJsonValidationErrors('reply_to_id');
        }
        $this->assertDatabaseCount('messages', 3);
    }

    public function test_quotes_show_updated_text_and_global_deletions_without_leaking_original_content(): void
    {
        $parent = Message::factory()->create(['body' => 'Original text']);
        $reply = Message::factory()->create(['sender_id' => $parent->recipient_id, 'recipient_id' => $parent->sender_id, 'body' => 'My response', 'reply_to_id' => $parent->id]);
        Sanctum::actingAs($parent->sender);
        $path = '/api/users/'.$parent->recipient->username.'/messages';
        $this->patchJson('/api/messages/'.$parent->id, ['body' => 'Updated text'])->assertOk();
        $this->getJson($path)->assertJsonPath('data.0.reply_to.body', 'Updated text');
        $this->deleteJson('/api/messages/'.$parent->id, ['scope' => 'everyone'])->assertOk();
        $this->getJson($path)->assertJsonPath('data.0.reply_to.body', 'Message deleted')->assertJsonPath('data.0.reply_to.deleted', true)->assertDontSee('Original text')->assertDontSee('Updated text');
        Sanctum::actingAs($parent->recipient);
        $this->patchJson('/api/messages/'.$reply->id, ['body' => 'Edited response', 'reply_to_id' => null])->assertOk()
            ->assertJsonPath('data.reply_to.id', $parent->id)->assertJsonPath('data.reply_to.deleted', true);
        $this->deleteJson('/api/messages/'.$reply->id, ['scope' => 'everyone'])->assertOk()->assertJsonPath('data.reply_to', null);
    }

    public function test_delete_for_me_hides_quoted_content_only_for_that_viewer(): void
    {
        $parent = Message::factory()->create(['body' => 'Quoted text']);
        Message::factory()->create(['sender_id' => $parent->recipient_id, 'recipient_id' => $parent->sender_id, 'reply_to_id' => $parent->id]);
        Sanctum::actingAs($parent->sender);
        $this->deleteJson('/api/messages/'.$parent->id, ['scope' => 'me'])->assertOk();
        $this->getJson('/api/users/'.$parent->recipient->username.'/messages')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reply_to.deleted', true)->assertJsonPath('data.0.reply_to.body', 'Message deleted');
        Sanctum::actingAs($parent->recipient);
        $this->getJson('/api/users/'.$parent->sender->username.'/messages')->assertJsonPath('data.0.reply_to.deleted', false)
            ->assertJsonPath('data.0.reply_to.body', 'Quoted text');

    }
}

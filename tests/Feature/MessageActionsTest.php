<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessageActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_sender_can_edit_and_edits_are_validated_and_labelled(): void
    {
        $message = Message::factory()->create(['body' => 'Original']);
        $path = '/api/messages/'.$message->id;
        $this->patchJson($path, ['body' => 'New'])->assertUnauthorized();
        Sanctum::actingAs($message->recipient);
        $this->patchJson($path, ['body' => 'Tampered'])->assertForbidden();
        Sanctum::actingAs(User::factory()->create());
        $this->patchJson($path, ['body' => 'Tampered'])->assertForbidden();
        Sanctum::actingAs($message->sender);
        foreach (['', '  ', str_repeat('x', 2001), ['invalid']] as $body) {
            $this->patchJson($path, ['body' => $body])->assertUnprocessable();
        }
        $this->patchJson($path, ['body' => ' Updated ', 'sender_id' => $message->recipient_id, 'read_at' => now()])
            ->assertOk()->assertJsonPath('data.body', 'Updated')->assertJsonPath('data.sender_id', $message->sender_id)
            ->assertJsonPath('data.read_at', null)->assertJsonPath('data.edited_at', fn ($value) => is_string($value));
        Sanctum::actingAs($message->recipient);
        $this->getJson('/api/users/'.$message->sender->username.'/messages')->assertJsonPath('data.0.body', 'Updated');
        $this->getJson('/api/messages')->assertJsonPath('data.0.last_message', 'Updated');
    }

    public function test_delete_for_me_only_hides_that_participants_copy_and_updates_previews_and_counts(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $old = Message::factory()->create(['sender_id' => $sender->id, 'recipient_id' => $recipient->id, 'body' => 'Earlier']);
        $latest = Message::factory()->create(['sender_id' => $sender->id, 'recipient_id' => $recipient->id, 'body' => 'Latest']);
        Sanctum::actingAs($recipient);
        $this->deleteJson('/api/messages/'.$latest->id, ['scope' => 'me'])->assertOk();
        $this->getJson('/api/messages')->assertJsonPath('data.0.last_message', 'Earlier')->assertJsonPath('data.0.unread_count', 1);
        $this->getJson('/api/messages/unread-count')->assertJsonPath('count', 1);
        $this->getJson('/api/users/'.$sender->username.'/messages')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->id);
        $this->deleteJson('/api/messages/'.$old->id, ['scope' => 'me'])->assertOk();
        $this->getJson('/api/messages')->assertJsonCount(0, 'data');
        Sanctum::actingAs($sender);
        $this->getJson('/api/users/'.$recipient->username.'/messages')->assertJsonCount(2, 'data')->assertJsonPath('data.0.body', 'Latest');
        $this->deleteJson('/api/messages/'.$latest->id, ['scope' => 'me'])->assertOk();
        $this->getJson('/api/users/'.$recipient->username.'/messages')->assertJsonCount(1, 'data');
        $this->patchJson('/api/messages/'.$latest->id, ['body' => 'Restore'])->assertForbidden();
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_delete_for_everyone_removes_original_text_and_cannot_be_edited_or_done_by_the_recipient(): void
    {
        $message = Message::factory()->create(['body' => 'Private original']);
        $path = '/api/messages/'.$message->id;
        $this->deleteJson($path, ['scope' => 'everyone'])->assertUnauthorized();
        Sanctum::actingAs($message->recipient);
        $this->deleteJson($path, ['scope' => 'everyone'])->assertForbidden();
        Sanctum::actingAs(User::factory()->create());
        $this->deleteJson($path, ['scope' => 'me'])->assertForbidden();
        Sanctum::actingAs($message->sender);
        $this->deleteJson($path, ['scope' => 'invalid'])->assertUnprocessable();
        $this->deleteJson($path, ['scope' => 'everyone'])->assertOk()->assertJsonPath('data.body', 'Message deleted');
        $this->deleteJson($path, ['scope' => 'everyone'])->assertOk();
        $this->assertSame('', $message->fresh()->body);
        $this->patchJson($path, ['body' => 'Resurrect'])->assertForbidden();
        Sanctum::actingAs($message->recipient);
        $this->getJson('/api/messages/unread-count')->assertJsonPath('count', 0);
        $this->getJson('/api/messages')->assertJsonPath('data.0.last_message', 'Message deleted')->assertJsonPath('data.0.last_message_deleted', true);
        $this->getJson('/api/users/'.$message->sender->username.'/messages')->assertJsonPath('data.0.body', 'Message deleted')->assertDontSee('Private original');
    }

    public function test_sender_sees_read_receipts_only_after_the_recipient_opens_the_chat(): void
    {
        $message = Message::factory()->create();
        Sanctum::actingAs($message->sender);
        $path = '/api/users/'.$message->recipient->username.'/messages';
        $this->getJson($path)->assertJsonPath('data.0.read_at', null);
        $this->getJson('/api/messages')->assertJsonPath('data.0.last_message_is_mine', true)->assertJsonPath('data.0.last_message_read_at', null);
        Sanctum::actingAs($message->recipient);
        $this->getJson('/api/messages')->assertOk();
        $this->assertNull($message->fresh()->read_at);
        $this->getJson('/api/users/'.$message->sender->username.'/messages')->assertJsonPath('data.0.read_at', fn ($value) => is_string($value));
        $readAt = $message->fresh()->read_at;
        $this->travel(10)->seconds();
        $this->getJson('/api/users/'.$message->sender->username.'/messages')->assertOk();
        $this->assertTrue($message->fresh()->read_at->equalTo($readAt));
        Sanctum::actingAs($message->sender);
        $this->getJson($path)->assertJsonPath('data.0.read_at', $readAt->toIso8601String());
        $this->getJson('/api/messages')->assertJsonPath('data.0.last_message_read_at', $readAt->toIso8601String());
    }

    public function test_edits_expire_exactly_ten_minutes_after_sending_and_editing_does_not_extend_the_window(): void
    {
        $this->travelTo(now()->startOfSecond());
        $message = Message::factory()->create(['body' => 'Original']);
        $deadline = $message->created_at->copy()->addMinutes(10)->toIso8601String();
        Sanctum::actingAs($message->sender);
        $this->travel(599)->seconds();
        $this->patchJson('/api/messages/'.$message->id, ['body' => 'Last minute edit'])->assertOk()
            ->assertJsonPath('data.can_edit', true)->assertJsonPath('data.edit_expires_at', $deadline);
        $this->travel(1)->seconds();
        $this->patchJson('/api/messages/'.$message->id, ['body' => 'Too late', 'created_at' => now(), 'edit_expires_at' => now()->addHour()])
            ->assertForbidden()->assertJsonPath('message', 'Messages can only be edited within 10 minutes of sending.');
        $this->assertSame('Last minute edit', $message->fresh()->body);
        $this->getJson('/api/users/'.$message->recipient->username.'/messages')->assertJsonPath('data.0.can_edit', false);
        $this->postJson('/api/users/'.$message->recipient->username.'/messages', ['body' => 'Reply to an old message', 'reply_to_id' => $message->id])->assertCreated();
        $this->deleteJson('/api/messages/'.$message->id, ['scope' => 'everyone'])->assertOk();
    }

    public function test_sync_includes_changes_to_older_messages_and_hides_deleted_copies(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $old = Message::factory()->create(['sender_id' => $sender->id, 'recipient_id' => $recipient->id]);
        Message::factory()->count(31)->create(['sender_id' => $sender->id, 'recipient_id' => $recipient->id]);
        Sanctum::actingAs($recipient);
        $path = '/api/users/'.$sender->username.'/messages';
        $cursor = $this->getJson($path)->assertOk()->json('meta.sync_cursor');
        $this->travel(2)->seconds();
        Sanctum::actingAs($sender);
        $this->patchJson('/api/messages/'.$old->id, ['body' => 'Changed older message'])->assertOk();
        Sanctum::actingAs($recipient);
        $result = $this->getJson($path.'?after='.urlencode($cursor))->assertOk();
        $this->assertTrue(collect($result->json('meta.changes'))->contains(fn (array $row) => $row['id'] === $old->id && $row['body'] === 'Changed older message'));
        $this->deleteJson('/api/messages/'.$old->id, ['scope' => 'me'])->assertOk();
        $changes = $this->getJson($path.'?after='.urlencode($cursor))->assertOk()->json('meta.changes');
        $hidden = collect($changes)->firstWhere('id', $old->id);
        $this->assertTrue($hidden['deleted_for_me']);
        $this->assertSame('Message deleted', $hidden['body']);
        $this->getJson($path.'?after=invalid')->assertUnprocessable();

    }
}

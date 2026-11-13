<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessageAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private function photo(string $name = 'photo.png'): File
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/j7sAAAAASUVORK5CYII='));
    }

    private function sendPhoto(User $recipient): array
    {
        return $this->postJson('/api/users/'.$recipient->username.'/messages', ['attachment_type' => 'image', 'attachment' => $this->photo()])->assertCreated()->json('data');
    }

    public function test_photo_messages_are_private_and_only_the_participants_can_download_them(): void
    {
        Storage::fake('local');
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        Sanctum::actingAs($sender);
        $data = $this->sendPhoto($recipient);
        $message = Message::findOrFail($data['id']);
        Storage::disk('local')->assertExists($message->attachment_path);
        $this->assertSame('image', $data['attachment']['type']);
        $this->assertFalse($data['can_edit']);
        $this->assertStringContainsString('/api/messages/'.$message->id.'/attachment', $data['attachment']['url']);
        $this->get('/api/messages/'.$message->id.'/attachment')->assertOk()->assertHeader('Content-Type', 'image/png');
        Sanctum::actingAs($recipient);
        $this->get('/api/messages/'.$message->id.'/attachment')->assertOk();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/messages/'.$message->id.'/attachment')->assertNotFound();
    }

    public function test_voice_notes_can_be_sent_without_text_and_have_an_inbox_preview(): void
    {
        Storage::fake('local');
        $recipient = User::factory()->create();
        Sanctum::actingAs(User::factory()->create());
        $data = $this->postJson('/api/users/'.$recipient->username.'/messages', [
            'attachment_type' => 'audio', 'attachment' => UploadedFile::fake()->create('voice.m4a', 50, 'audio/mp4'), 'duration_ms' => 4500,
        ])->assertCreated()->json('data');
        $this->assertSame('audio', $data['attachment']['type']);
        $this->assertSame(4500, $data['attachment']['duration_ms']);
        $this->getJson('/api/messages')->assertJsonPath('data.0.last_message', 'Voice note');
        $this->patchJson('/api/messages/'.$data['id'], ['body' => 'Changed'])->assertForbidden();
        $this->postJson('/api/users/'.$recipient->username.'/messages', ['body' => 'Reply to voice', 'reply_to_id' => $data['id']])->assertCreated()->assertJsonPath('data.reply_to.body', 'Voice note');
    }

    public function test_deleting_for_me_preserves_the_other_persons_file_and_deleting_for_everyone_removes_it(): void
    {
        Storage::fake('local');
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        Sanctum::actingAs($sender);
        $data = $this->sendPhoto($recipient);
        $path = Message::findOrFail($data['id'])->attachment_path;
        Sanctum::actingAs($recipient);
        $this->deleteJson('/api/messages/'.$data['id'], ['scope' => 'me'])->assertOk();
        $this->getJson('/api/messages/'.$data['id'].'/attachment')->assertNotFound();
        Storage::disk('local')->assertExists($path);
        Sanctum::actingAs($sender);
        $this->get('/api/messages/'.$data['id'].'/attachment')->assertOk();
        $this->deleteJson('/api/messages/'.$data['id'], ['scope' => 'everyone'])->assertOk()->assertJsonPath('data.attachment', null);
        Storage::disk('local')->assertMissing($path);
        $this->getJson('/api/messages/'.$data['id'].'/attachment')->assertNotFound();
    }

    public function test_private_uploads_respect_requests_and_blocks_without_leaking_files(): void
    {
        Storage::fake('local');
        $sender = User::factory()->create();
        $recipient = User::factory()->create(['message_policy' => 'requests']);
        Sanctum::actingAs($sender);
        $data = $this->sendPhoto($recipient);
        $this->postJson('/api/users/'.$recipient->username.'/messages', ['attachment_type' => 'image', 'attachment' => $this->photo()])->assertForbidden();
        $this->assertCount(1, Storage::disk('local')->allFiles('messages'));
        $this->postJson('/api/settings/blocked-accounts/'.$recipient->username)->assertOk();
        $this->getJson('/api/messages/'.$data['id'].'/attachment')->assertForbidden();
        $this->postJson('/api/users/'.$recipient->username.'/messages', ['attachment_type' => 'image', 'attachment' => $this->photo()])->assertForbidden();
        $this->assertDatabaseCount('messages', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('messages'));
    }

    public function test_attachment_validation_rejects_wrong_file_types_missing_metadata_and_oversized_files(): void
    {
        Storage::fake('local');
        $recipient = User::factory()->create();
        Sanctum::actingAs(User::factory()->create());
        $url = '/api/users/'.$recipient->username.'/messages';
        $this->postJson($url, ['attachment_type' => 'image', 'attachment' => UploadedFile::fake()->create('script.svg', 1, 'image/svg+xml')])->assertUnprocessable()->assertJsonValidationErrors('attachment');
        $this->postJson($url, ['attachment_type' => 'image', 'attachment' => $this->photo()->size(10241)])->assertUnprocessable()->assertJsonValidationErrors('attachment');
        $this->postJson($url, ['attachment_type' => 'audio', 'attachment' => UploadedFile::fake()->create('voice.m4a', 20, 'audio/mp4')])->assertUnprocessable()->assertJsonValidationErrors('duration_ms');
        $this->postJson($url, ['attachment_type' => 'audio', 'attachment' => UploadedFile::fake()->create('voice.m4a', 20, 'audio/mp4'), 'duration_ms' => 120001])->assertUnprocessable()->assertJsonValidationErrors('duration_ms');
        $this->postJson($url, ['attachment' => $this->photo()])->assertUnprocessable()->assertJsonValidationErrors('attachment_type');
        $this->assertDatabaseCount('messages', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_guests_cannot_download_attachments_and_blocked_replies_do_not_store_a_file(): void
    {
        Storage::fake('local');
        $this->getJson('/api/messages/1/attachment')->assertUnauthorized();
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $other = Message::factory()->create();
        Sanctum::actingAs($sender);
        $this->postJson('/api/users/'.$recipient->username.'/messages', ['reply_to_id' => $other->id, 'attachment_type' => 'image', 'attachment' => $this->photo()])->assertUnprocessable();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}

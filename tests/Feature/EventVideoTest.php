<?php

namespace Tests\Feature;

use App\Jobs\TranscodeEventVideo;
use App\Models\Event;
use App\Models\User;
use App\Support\VideoTranscoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventVideoTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_verified_owner_can_upload_and_videos_are_queued_privately(): void
    {
        Queue::fake();
        Storage::fake('local');
        $event = Event::factory()->create(['user_id' => User::factory()->publisher()->create()->id]);
        $file = UploadedFile::fake()->create('clip.mp4', 100, 'video/mp4');
        $this->postJson('/api/events/'.$event->id.'/video', ['video' => $file])->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/events/'.$event->id.'/video', ['video' => $file])->assertForbidden();
        Sanctum::actingAs($event->host);
        $this->postJson('/api/events/'.$event->id.'/video', ['video' => $file])->assertAccepted()->assertJsonPath('data.video_status', 'processing');
        $event->refresh();
        Storage::disk('local')->assertExists($event->video_source_path);
        Queue::assertPushed(TranscodeEventVideo::class, fn ($job) => $job->eventId === $event->id && $job->connection === 'video');
        $this->postJson('/api/events/'.$event->id.'/video', ['video' => $file])->assertStatus(409);
    }

    public function test_invalid_uploads_and_replacements_are_rejected(): void
    {
        Queue::fake();
        Storage::fake('local');
        $event = Event::factory()->create(['user_id' => User::factory()->publisher()->create()->id]);
        Sanctum::actingAs($event->host);
        $this->postJson('/api/events/'.$event->id.'/video', ['video' => UploadedFile::fake()->create('bad.txt', 1, 'text/plain')])->assertUnprocessable();
        $this->postJson('/api/events/'.$event->id.'/video', ['video' => UploadedFile::fake()->create('large.mp4', 102401, 'video/mp4')])->assertUnprocessable();
        $event->video_stream_path = 'videos/streams/'.Str::uuid();
        $event->save();
        $this->postJson('/api/events/'.$event->id.'/video', ['video' => UploadedFile::fake()->create('clip.mp4', 10, 'video/mp4')])->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_streams_obey_visibility_and_cannot_read_arbitrary_files(): void
    {
        Storage::fake('local');
        $event = Event::factory()->private()->create();
        $version = (string) Str::uuid();
        $event->video_stream_path = 'videos/streams/'.$version;
        $event->video_status = 'ready';
        $event->save();
        Storage::disk('local')->put($event->video_stream_path.'/master.m3u8', "#EXTM3U\n");
        $url = '/api/events/'.$event->id.'/video/'.$version.'/master.m3u8';
        $this->get($url)->assertNotFound();
        Sanctum::actingAs(User::factory()->create());
        $this->get($url)->assertNotFound();
        Sanctum::actingAs($event->host);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/vnd.apple.mpegurl');
        $this->get('/api/events/'.$event->id.'/video/'.$version.'/private.txt')->assertNotFound();
        $this->get('/api/events/'.$event->id.'/video/'.Str::uuid().'/master.m3u8')->assertNotFound();
        $this->patchJson('/api/settings/posts/'.$event->id.'/archive', ['archived' => true])->assertOk();
        $this->get($url)->assertNotFound();
    }

    public function test_removal_cleans_files_and_a_stale_job_cannot_restore_the_video(): void
    {
        Storage::fake('local');
        $event = Event::factory()->create(['has_video' => true]);
        $event->video_source_path = 'videos/sources/old.mp4';
        $event->video_stream_path = 'videos/streams/'.Str::uuid();
        $event->video_status = 'processing';
        $event->save();
        $source = $event->video_source_path;
        $stream = $event->video_stream_path;
        Storage::disk('local')->put($source, 'source');
        Storage::disk('local')->put($stream.'/master.m3u8', '#EXTM3U');
        Sanctum::actingAs($event->host);
        $this->deleteJson('/api/events/'.$event->id.'/video')->assertOk();
        Storage::disk('local')->assertMissing($source);
        Storage::disk('local')->assertMissing($stream.'/master.m3u8');
        (new TranscodeEventVideo($event->id, $source, $stream))->handle(new VideoTranscoder);
        $this->assertFalse($event->fresh()->has_video);
        $this->assertNull($event->fresh()->video_stream_path);
    }

    public function test_real_transcoding_creates_compressed_renditions_and_removes_the_original(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $source = 'videos/sources/sample.mp4';
        $stream = 'videos/streams/'.Str::uuid();
        $disk->makeDirectory('videos/sources');
        Process::timeout(30)->run([config('video.ffmpeg'), '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=1280x720:rate=30', '-t', '2', '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '15', '-threads', '2', $disk->path($source)])->throw();
        $originalBytes = $disk->size($source);
        $event = Event::factory()->create();
        $event->video_source_path = $source;
        $event->video_status = 'processing';
        $event->save();
        (new TranscodeEventVideo($event->id, $source, $stream))->handle(new VideoTranscoder);
        $event->refresh();
        $this->assertSame('ready', $event->video_status);
        $this->assertSame([240, 480, 720], array_column($event->video_variants, 'height'));
        $this->assertStringContainsString('v0/index.m3u8', $disk->get($stream.'/master.m3u8'));
        $saverBytes = collect($disk->files($stream.'/v0'))->sum(fn (string $file) => $disk->size($file));
        $this->assertLessThan($originalBytes, $saverBytes);
        $disk->assertMissing($source);
        $disk->assertExists($stream.'/v2/index.m3u8');
        $this->getJson('/api/events/'.$event->id)->assertJsonPath('data.has_video', true)->assertJsonStructure(['data' => ['video_url', 'video_saver_url', 'video_variants']])->assertJsonMissingPath('data.video_source_path');
    }
}

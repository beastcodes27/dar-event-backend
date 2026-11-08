<?php

namespace App\Jobs;

use App\Models\Event;
use App\Support\VideoTranscoder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class TranscodeEventVideo implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3300;

    public int $tries = 1;

    public function __construct(public int $eventId, public string $sourcePath, public string $streamPath)
    {
        $this->onConnection('video')->onQueue('videos');
    }

    public function handle(VideoTranscoder $transcoder): void
    {
        $event = Event::withoutGlobalScopes()->find($this->eventId);
        if (! $event || $event->video_source_path !== $this->sourcePath) {
            Storage::disk('local')->delete($this->sourcePath);

            return;
        }
        $variants = $transcoder->transcode($this->sourcePath, $this->streamPath);
        $updated = Event::withoutGlobalScopes()->whereKey($this->eventId)->where('video_source_path', $this->sourcePath)->update([
            'video_stream_path' => $this->streamPath, 'video_source_path' => null, 'video_status' => 'ready',
            'video_variants' => json_encode($variants), 'has_video' => true, 'video_url' => null,
        ]);
        if (! $updated) {
            Storage::disk('local')->deleteDirectory($this->streamPath);
        }
        Storage::disk('local')->delete($this->sourcePath);
    }

    public function failed(?Throwable $exception): void
    {
        Event::withoutGlobalScopes()->whereKey($this->eventId)->where('video_source_path', $this->sourcePath)->update(['video_status' => 'failed']);
        Storage::disk('local')->deleteDirectory($this->streamPath);
    }
}

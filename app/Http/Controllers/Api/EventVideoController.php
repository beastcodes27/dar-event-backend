<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\TranscodeEventVideo;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EventVideoController extends Controller
{
    public function store(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);
        $request->validate(['video' => ['required', 'file', 'mimetypes:video/mp4,video/quicktime,video/webm', 'max:'.config('video.max_upload_kb')]]);
        DB::transaction(function () use ($request, $event): void {
            $event = Event::withoutGlobalScopes()->lockForUpdate()->findOrFail($event->id);
            abort_if($event->video_status === 'processing', 409, 'This video is still processing.');
            abort_if($event->video_stream_path, 422, 'Remove the current video before uploading a replacement.');
            $previousSource = $event->video_source_path;
            $source = $request->file('video')->store('videos/sources', 'local');
            $stream = 'videos/streams/'.Str::uuid();
            $event->video_source_path = $source;
            $event->video_status = 'processing';
            $event->save();
            TranscodeEventVideo::dispatch($event->id, $source, $stream)->afterCommit();
            if ($previousSource) {
                Storage::disk('local')->delete($previousSource);
            }
        });

        return response()->json(['message' => 'Video uploaded. Preparing compressed qualities.', 'data' => ['video_status' => 'processing']], 202);
    }

    public function destroy(Request $request, Event $event): JsonResponse
    {
        $this->authorize('delete', $event);
        [$source, $stream] = DB::transaction(function () use ($event): array {
            $event = Event::withoutGlobalScopes()->lockForUpdate()->findOrFail($event->id);
            $paths = [$event->video_source_path, $event->video_stream_path];
            $event->video_source_path = null;
            $event->video_stream_path = null;
            $event->video_status = null;
            $event->video_variants = null;
            $event->video_url = null;
            $event->has_video = false;
            $event->save();

            return $paths;
        });
        if ($source) {
            Storage::disk('local')->delete($source);
        }
        if ($stream) {
            Storage::disk('local')->deleteDirectory($stream);
        }

        return response()->json(['message' => 'Video removed.']);
    }

    public function stream(Request $request, Event $event, string $streamId, string $asset): BinaryFileResponse
    {
        abort_unless($event->is_public || $event->isHostedBy($request->user('sanctum')), 404);
        abort_unless($event->video_stream_path === 'videos/streams/'.$streamId, 404);
        abort_unless(preg_match('/\A(?:master\.m3u8|v[0-2]\/(?:index\.m3u8|segment\d{5}\.ts))\z/', $asset), 404);
        $disk = Storage::disk('local');
        $path = $event->video_stream_path.'/'.$asset;
        abort_unless($disk->exists($path), 404);

        return response()->file($disk->path($path), [
            'Content-Type' => str_ends_with($asset, '.m3u8') ? 'application/vnd.apple.mpegurl' : 'video/mp2t',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}

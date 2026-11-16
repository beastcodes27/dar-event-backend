<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\ContentReport;
use App\Models\Event;
use App\Support\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContentReportController extends Controller
{
    public function event(Request $request, Event $event): JsonResponse
    {
        abort_unless($event->is_public || $event->isHostedBy($request->user()), 404);

        return $this->store($request, $event);
    }

    public function comment(Request $request, Comment $comment): JsonResponse
    {
        $event = $comment->event;
        abort_unless($event && ($event->is_public || $event->isHostedBy($request->user())) && ! $request->user()->blockedAccountIds()->contains($comment->user_id), 404);

        return $this->store($request, $event, $comment);
    }

    private function store(Request $request, Event $event, ?Comment $comment = null): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', Rule::in(array_keys(ContentReport::REASONS))], 'details' => ['nullable', 'string', 'max:2000']]);
        $author = $comment ? $comment->user : $event->host;
        abort_if($author->is($request->user()), 422, 'You cannot report your own content.');
        $report = ContentReport::firstOrCreate(['reporter_id' => $request->user()->id, 'target_type' => $comment ? 'comment' : 'event', 'target_id' => $comment?->id ?? $event->id], $data + ['reported_user_id' => $author->id, 'event_id' => $event->id, 'comment_id' => $comment?->id, 'snapshot' => ['title' => $event->title, 'body' => $comment?->body ?? $event->description, 'author' => $author->username, 'author_name' => $author->name, 'image' => Media::url($event->image_path), 'video' => $event->video_stream_path ? url('/api/events/'.$event->id.'/video/'.basename($event->video_stream_path).'/master.m3u8') : $event->video_url, 'parent_id' => $comment?->parent_id], 'status' => 'pending']);

        return response()->json(['message' => $report->wasRecentlyCreated ? 'Report submitted. An administrator will review it.' : 'You have already reported this content.', 'data' => ['id' => $report->id, 'status' => $report->status]], $report->wasRecentlyCreated ? 201 : 200)->header('Cache-Control', 'no-store');
    }
}

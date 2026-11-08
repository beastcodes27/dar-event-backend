<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\Event;
use App\Notifications\AppNotification;
use App\Support\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CommentController extends Controller
{
    public function index(Request $request, Event $event): AnonymousResourceCollection
    {
        abort_if(! $event->is_public && ! $event->isHostedBy($request->user('sanctum')), 404);

        $comments = $event->rootComments()
            ->with(['user', 'replies.user'])
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return CommentResource::collection($comments)->additional(['meta' => ['comments_count' => (int) $event->comments_count]]);
    }

    public function store(StoreCommentRequest $request, Event $event): JsonResponse
    {
        $auth = $request->user();

        $comment = $event->comments()->create([
            'user_id' => $auth->getKey(),
            'parent_id' => $request->validated('parent_id'),
            'body' => $request->validated('body'),
        ]);

        $event->update(['comments_count' => $event->comments()->count()]);

        $comment->load('user');

        $notifiable = $comment->parent_id
            ? $comment->parent?->user
            : $event->host;

        if ($notifiable && ! $notifiable->is($auth)) {
            $notifiable->notify(new AppNotification($comment->parent_id ? 'comment_reply' : 'comment', [
                'message' => $comment->parent_id
                    ? "{$auth->username} replied to your comment."
                    : "{$auth->username} commented on your event.",
                'actor' => [
                    'id' => $auth->id,
                    'name' => $auth->name,
                    'username' => $auth->username,
                    'avatar' => Media::url($auth->avatar_path),
                ],
                'event' => [
                    'id' => $event->id,
                    'title' => $event->title,
                    'slug' => $event->slug,
                ],
                'comment' => [
                    'id' => $comment->id,
                    'body' => $comment->body,
                ],
            ]));
        }

        return response()->json([
            'message' => 'Comment posted successfully.',
            'data' => new CommentResource($comment),
        ], 201);
    }

    public function destroy(Comment $comment): JsonResponse
    {
        $this->authorize('delete', $comment);

        $event = $comment->event;
        $comment->delete();

        if ($event) {
            $event->update(['comments_count' => $event->comments()->count()]);
        }

        return response()->json(['message' => 'Comment deleted successfully.']);
    }
}

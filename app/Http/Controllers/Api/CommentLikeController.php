<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Notifications\AppNotification;
use App\Support\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommentLikeController extends Controller
{
    public function toggle(Request $request, Comment $comment): JsonResponse
    {
        $auth = $request->user();

        $liked = $comment->likes()->where('users.id', $auth->getKey())->exists();

        if ($liked) {
            $comment->likes()->detach($auth->getKey());
        } else {
            $comment->likes()->attach($auth->getKey());

            if (! $comment->user->is($auth)) {
                $comment->user->notify(new AppNotification('comment_like', [
                    'message' => "{$auth->username} liked your comment.",
                    'actor' => [
                        'id' => $auth->id,
                        'name' => $auth->name,
                        'username' => $auth->username,
                        'avatar' => Media::url($auth->avatar_path),
                    ],
                    'event' => $comment->event ? [
                        'id' => $comment->event->id,
                        'title' => $comment->event->title,
                        'slug' => $comment->event->slug,
                    ] : null,
                    'comment' => [
                        'id' => $comment->id,
                        'body' => $comment->body,
                    ],
                ]));
            }
        }

        $comment->update(['likes_count' => $comment->likes()->count()]);

        return response()->json([
            'message' => $liked ? 'Comment unliked.' : 'Comment liked.',
            'liked' => ! $liked,
            'likes_count' => $comment->likes_count,
        ]);
    }
}

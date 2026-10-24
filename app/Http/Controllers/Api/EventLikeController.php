<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Notifications\AppNotification;
use App\Support\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventLikeController extends Controller
{
    public function toggle(Request $request, Event $event): JsonResponse
    {
        $auth = $request->user();

        $liked = $event->likes()->where('users.id', $auth->getKey())->exists();

        if ($liked) {
            $event->likes()->detach($auth->getKey());
        } else {
            $event->likes()->attach($auth->getKey());

            if (! $event->isHostedBy($auth)) {
                $event->host->notify(new AppNotification('event_like', [
                    'message' => "{$auth->username} liked your event.",
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
                ]));
            }
        }

        $event->update(['likes_count' => $event->likes()->count()]);

        return response()->json([
            'message' => $liked ? 'Event unliked.' : 'Event liked.',
            'liked' => ! $liked,
            'likes_count' => $event->likes_count,
        ]);
    }
}

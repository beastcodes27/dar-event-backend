<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SavedEventController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $events = $request->user()->savedEvents()
            ->with('host')
            ->latest('saved_events.created_at')
            ->paginate($request->integer('per_page', 15));

        return EventResource::collection($events);
    }

    public function toggle(Request $request, Event $event): JsonResponse
    {
        $auth = $request->user();

        $saved = $event->savedBy()->where('users.id', $auth->getKey())->exists();

        if ($saved) {
            $event->savedBy()->detach($auth->getKey());
        } else {
            $event->savedBy()->attach($auth->getKey());
        }

        $event->update(['saves_count' => $event->savedBy()->count()]);

        return response()->json([
            'message' => $saved ? 'Event removed from saved.' : 'Event saved.',
            'saved' => ! $saved,
            'saves_count' => $event->saves_count,
        ]);
    }
}

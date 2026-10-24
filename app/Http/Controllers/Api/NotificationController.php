<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $notifications = $request->user()->notifications()->latest()->paginate(
            $request->integer('per_page', 20)
        );

        return NotificationResource::collection($notifications)->additional([
            'meta' => [
                'unread_count' => $request->user()->unreadNotifications()->count(),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markAsRead(Request $request, string $notification): JsonResponse
    {
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $item->markAsRead();

        return response()->json([
            'message' => 'Notification marked as read.',
            'data' => new NotificationResource($item->fresh()),
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    public function destroy(Request $request, string $notification): JsonResponse
    {
        $request->user()->notifications()->whereKey($notification)->firstOrFail()->delete();

        return response()->json(['message' => 'Notification deleted.']);
    }
}

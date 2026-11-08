<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventShareController extends Controller
{
    public function store(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);
        $event->increment('shares_count');

        return response()->json(['shares_count' => (int) $event->fresh()->shares_count]);
    }
}

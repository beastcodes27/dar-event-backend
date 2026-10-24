<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Resources\TicketResource;
use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class TicketController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $tickets = $request->user()->tickets()
            ->with('event.host')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return TicketResource::collection($tickets);
    }

    public function store(StoreTicketRequest $request, Event $event): JsonResponse
    {
        abort_unless($event->is_public, 404);

        $quantity = $request->integer('quantity', 1);
        $unitPrice = (float) $event->price;

        $ticket = $event->tickets()->create([
            'user_id' => $request->user()->getKey(),
            'reference' => 'DAR-'.Str::upper(Str::random(10)),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total' => $unitPrice * $quantity,
            'status' => 'confirmed',
        ]);

        return response()->json([
            'message' => 'Ticket booked successfully.',
            'data' => new TicketResource($ticket->load('event.host')),
        ], 201);
    }

    public function show(Request $request, Ticket $ticket): TicketResource
    {
        abort_unless($ticket->user_id === $request->user()->getKey(), 404);

        return new TicketResource($ticket->load('event.host'));
    }

    public function cancel(Request $request, Ticket $ticket): JsonResponse
    {
        abort_unless($ticket->user_id === $request->user()->getKey(), 404);

        $ticket->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'Ticket cancelled successfully.',
            'data' => new TicketResource($ticket->fresh()->load('event.host')),
        ]);
    }
}

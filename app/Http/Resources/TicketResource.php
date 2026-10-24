<?php

namespace App\Http\Resources;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Ticket */
class TicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'quantity' => (int) $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'total' => (float) $this->total,
            'status' => $this->status,
            'event' => new EventResource($this->whenLoaded('event')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

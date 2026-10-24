<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/** @mixin DatabaseNotification */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->data;

        return [
            'id' => $this->id,
            'type' => $data['type'] ?? class_basename($this->type),
            'message' => $data['message'] ?? null,
            'actor' => $data['actor'] ?? null,
            'event' => $data['event'] ?? null,
            'comment' => $data['comment'] ?? null,
            'is_read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601String(),
            'time' => $this->created_at?->diffForHumans(short: true),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

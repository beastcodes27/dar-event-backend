<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class InboxResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user' => new UserResource($this->resource),
            'last_message' => $this->last_message,
            'last_message_at' => $this->last_message_at,
            'unread_count' => (int) $this->unread_count,
        ];
    }
}

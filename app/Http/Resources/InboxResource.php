<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin User */
class InboxResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user' => new UserResource($this->resource),
            'last_message' => $this->last_message_deleted_at ? 'Message deleted' : ($this->last_message ?: ($this->last_message_attachment_type === 'audio' ? 'Voice note' : ($this->last_message_attachment_type === 'image' ? 'Photo' : ''))),
            'last_message_is_mine' => (int) $this->last_message_sender_id === $request->user()->id,
            'last_message_read_at' => $this->last_message_read_at ? Carbon::parse($this->last_message_read_at)->toIso8601String() : null,
            'last_message_deleted' => $this->last_message_deleted_at !== null,
            'last_message_at' => $this->last_message_at,
            'unread_count' => (int) $this->unread_count,
            'message_request' => $this->request_id ? ['id' => (int) $this->request_id, 'status' => $this->request_status] : null,
        ];
    }
}

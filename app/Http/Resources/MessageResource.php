<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $parent = $this->resource->relationLoaded('replyTo') ? $this->replyTo : null;
        $parentDeleted = $parent && ($parent->deleted_for_everyone_at || $parent->isDeletedFor($request->user()));

        return [
            'id' => $this->id,
            'sender_id' => $this->sender_id,
            'recipient_id' => $this->recipient_id,
            'body' => $this->deleted_for_everyone_at || $this->resource->isDeletedFor($request->user()) ? 'Message deleted' : $this->body,
            'attachment' => $this->attachment_path && ! $this->deleted_for_everyone_at && ! $this->resource->isDeletedFor($request->user()) ? [
                'type' => $this->attachment_type,
                'url' => route('messages.attachment', ['message' => $this->id]),
                'mime' => $this->attachment_mime, 'size' => $this->attachment_size, 'duration_ms' => $this->attachment_duration_ms,
            ] : null,
            'is_mine' => $this->sender_id === $request->user()->getKey(),
            'reply_to' => $parent && ! $this->deleted_for_everyone_at && ! $this->resource->isDeletedFor($request->user()) ? [
                'id' => $parent->id,
                'sender_id' => $parent->sender_id,
                'attachment_type' => $parentDeleted ? null : $parent->attachment_type,
                'is_mine' => $parent->sender_id === $request->user()->id,
                'body' => $parentDeleted ? 'Message deleted' : ($parent->body ?: ($parent->attachment_type === 'audio' ? 'Voice note' : ($parent->attachment_type === 'image' ? 'Photo' : ''))),
                'deleted' => (bool) $parentDeleted,
            ] : null,
            'read_at' => $this->read_at?->toIso8601String(),
            'edited_at' => $this->edited_at?->toIso8601String(),
            'can_edit' => $this->resource->canBeEditedBy($request->user()),
            'edit_expires_at' => $this->sender_id === $request->user()->id ? $this->created_at?->copy()->addMinutes(Message::EDIT_WINDOW_MINUTES)->toIso8601String() : null,
            'deleted_for_everyone_at' => $this->deleted_for_everyone_at?->toIso8601String(),
            'deleted_for_me' => $this->resource->isDeletedFor($request->user()),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

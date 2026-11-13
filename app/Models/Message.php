<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['sender_id', 'recipient_id', 'body', 'reply_to_id', 'attachment_type', 'attachment_path', 'attachment_mime', 'attachment_size', 'attachment_duration_ms'])]
class Message extends Model
{
    public const EDIT_WINDOW_MINUTES = 10;

    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['attachment_size' => 'integer', 'attachment_duration_ms' => 'integer', 'read_at' => 'datetime', 'edited_at' => 'datetime', 'deleted_for_everyone_at' => 'datetime', 'sender_deleted_at' => 'datetime', 'recipient_deleted_at' => 'datetime'];
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $query) use ($user): void {
            $query->where(function (Builder $query) use ($user): void {
                $query->where('sender_id', $user->id)->whereNull('sender_deleted_at');
            })->orWhere(function (Builder $query) use ($user): void {
                $query->where('recipient_id', $user->id)->whereNull('recipient_deleted_at');
            });
        });
    }

    public function isDeletedFor(User $user): bool
    {
        return ($this->sender_id === $user->id && $this->sender_deleted_at !== null)
            || ($this->recipient_id === $user->id && $this->recipient_deleted_at !== null);
    }

    public function canBeEditedBy(User $user): bool
    {
        return ! $this->attachment_path && $this->sender_id === $user->id && ! $this->isDeletedFor($user) && ! $this->deleted_for_everyone_at
            && $this->created_at?->greaterThan(now()->subMinutes(self::EDIT_WINDOW_MINUTES));
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}

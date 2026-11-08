<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'user_id',
    'title',
    'slug',
    'description',
    'category',
    'location',
    'venue',
    'starts_at',
    'ends_at',
    'price',
    'currency',
    'image_path',
    'video_url',
    'has_video',
    'is_public',
    'likes_count',
    'comments_count',
    'saves_count',
])]
class Event extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::deleted(function (Event $event): void {
            if ($event->video_source_path) {
                Storage::disk('local')->delete($event->video_source_path);
            }
            if ($event->video_stream_path) {
                Storage::disk('local')->deleteDirectory($event->video_stream_path);
            }
        });
        static::addGlobalScope('active', fn (Builder $query) => $query->whereNull('events.archived_at'));
        static::addGlobalScope('unblocked', function (Builder $query): void {
            $auth = request()->user('sanctum');
            if ($auth) {
                $query->whereNotIn('events.user_id', $auth->blockedAccountIds());
            }
        });
    }

    public const CATEGORIES = [
        'music',
        'university',
        'birthday',
        'weddings',
        'education',
        'business',
        'other',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'price' => 'decimal:2',
            'has_video' => 'boolean',
            'is_public' => 'boolean',
            'archived_at' => 'datetime',
            'video_variants' => 'array',
            'likes_count' => 'integer',
            'comments_count' => 'integer',
            'saves_count' => 'integer',
            'shares_count' => 'integer',
        ];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function likes(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_likes')->withTimestamps();
    }

    public function savedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'saved_events')->withTimestamps();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function rootComments(): HasMany
    {
        return $this->comments()->whereNull('parent_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function isHostedBy(?User $user): bool
    {
        return $user !== null && $this->user_id === $user->getKey();
    }
}

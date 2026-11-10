<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'user_id',
    'story_highlight_id',
    'media_path',
    'caption',
    'is_live',
    'expires_at',
])]
class Story extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_live' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function highlight(): BelongsTo
    {
        return $this->belongsTo(StoryHighlight::class, 'story_highlight_id');
    }

    public function likes(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'story_likes')->withTimestamps();
    }

    public function viewers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'story_views')->withTimestamps();
    }

    public function scopeWithInteractions(Builder $query, ?User $viewer): Builder
    {
        $query->withCount(['likes', 'viewers']);
        if ($viewer) {
            $query->withExists([
                'likes as is_liked' => fn ($likes) => $likes->where('users.id', $viewer->id),
                'viewers as is_viewed' => fn ($views) => $views->where('users.id', $viewer->id),
            ]);
        }

        return $query;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->where('is_live', true)
                ->orWhere('expires_at', '>', now())
                ->orWhereNull('expires_at');
        });
    }
}

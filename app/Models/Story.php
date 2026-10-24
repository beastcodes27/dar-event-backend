<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->where('is_live', true)
                ->orWhere('expires_at', '>', now())
                ->orWhereNull('expires_at');
        });
    }
}

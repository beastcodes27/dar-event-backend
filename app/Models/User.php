<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name',
    'username',
    'email',
    'password',
    'bio',
    'category',
    'gender',
    'country',
    'avatar_path',
    'account_type',
    'verified',
    'verified_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'verified_at' => 'datetime',
            'username_changed_at' => 'datetime',
            'verified' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function likedEvents(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_likes')->withTimestamps();
    }

    public function savedEvents(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'saved_events')->withTimestamps();
    }

    public function following(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'follows', 'follower_id', 'following_id')
            ->withTimestamps();
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'follows', 'following_id', 'follower_id')
            ->withTimestamps();
    }

    public function verificationRequests(): HasMany
    {
        return $this->hasMany(VerificationRequest::class);
    }

    public function storyHighlights(): HasMany
    {
        return $this->hasMany(StoryHighlight::class);
    }

    public function stories(): HasMany
    {
        return $this->hasMany(Story::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function recentSearches(): HasMany
    {
        return $this->hasMany(RecentSearch::class);
    }

    public function isFollowing(User $user): bool
    {
        return $this->following()->where('users.id', $user->getKey())->exists();
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part) => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');
    }
}

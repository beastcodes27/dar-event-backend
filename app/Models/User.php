<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
    'discoverable',
    'allow_messages',
    'theme_preference',
    'online_status_visibility',
    'message_policy',
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
            'terms_accepted_at' => 'datetime',
            'verified_at' => 'datetime',
            'username_changed_at' => 'datetime',
            'verified' => 'boolean',
            'discoverable' => 'boolean',
            'allow_messages' => 'boolean',
            'is_admin' => 'boolean',
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

    public function blockedAccountIds(): Collection
    {
        return DB::table('account_blocks')->where('user_id', $this->id)->pluck('blocked_user_id')
            ->merge(DB::table('account_blocks')->where('blocked_user_id', $this->id)->pluck('user_id'))->unique();
    }

    public function hasBlockWith(User $user): bool
    {
        return $this->blockedAccountIds()->contains($user->id);
    }

    public function onlineStatusFor(?User $viewer): ?bool
    {
        if (! $viewer || (! $viewer->is($this) && (
            $this->online_status_visibility === 'nobody'
            || $this->hasBlockWith($viewer)
            || ($this->online_status_visibility === 'following' && ! $this->isFollowing($viewer))
        ))) {
            return null;
        }

        return $this->tokens()->where('last_active_at', '>', now()->subSeconds(60))
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })->when(config('sanctum.expiration'), fn (Builder $query) => $query->where('created_at', '>', now()->subMinutes((int) config('sanctum.expiration'))))
            ->exists();
    }

    public function messagePermissionFor(User $sender): string
    {
        if ($sender->is($this) || ! $this->allow_messages || $this->hasBlockWith($sender)) {
            return 'unavailable';
        }

        $request = MessageRequest::where('sender_id', $sender->id)->where('recipient_id', $this->id)->first();
        if ($request && in_array($request->status, ['pending', 'declined'], true)) {
            return 'request_'.$request->status;
        }
        if ($this->message_policy === 'followers' && ! $sender->isFollowing($this)) {
            return 'followers_only';
        }
        if ($this->message_policy === 'requests' && $request?->status !== 'accepted'
            && ! $this->sentMessages()->where('recipient_id', $sender->id)->exists()) {
            return 'request_required';
        }

        return 'allowed';
    }
}

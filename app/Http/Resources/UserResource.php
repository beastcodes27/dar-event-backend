<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $auth = $request->user('sanctum');
        $isMe = $auth?->is($this->resource) ?? false;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'username_change_available_at' => $this->when($isMe, fn () => $this->username_changed_at?->copy()->addDays(7)->toIso8601String()),
            'email' => $this->when($isMe, $this->email),
            'bio' => $this->bio,
            'category' => $this->category,
            'gender' => $this->when($isMe, $this->gender),
            'country' => $this->country,
            'avatar' => Media::url($this->avatar_path),
            'initials' => $this->initials(),
            'verified' => $this->verified,
            'account_type' => $this->account_type,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'followers_count' => $this->when(isset($this->followers_count), fn () => (int) $this->followers_count),
            'following_count' => $this->when(isset($this->following_count), fn () => (int) $this->following_count),
            'posts_count' => $this->when(isset($this->events_count), fn () => (int) $this->events_count),
            'is_following' => $this->when($auth !== null && ! $isMe, fn () => $auth->isFollowing($this->resource)),
            'is_me' => $isMe,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

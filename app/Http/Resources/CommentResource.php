<?php

namespace App\Http\Resources;

use App\Models\Comment;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Comment */
class CommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $auth = $request->user('sanctum');
        $isOwner = $auth?->is($this->user) ?? false;

        return [
            'id' => $this->id,
            'body' => $this->body,
            'text' => $this->body,
            'user' => $this->user?->username,
            'username' => $this->user?->username,
            'name' => $this->user?->name,
            'avatar' => Media::url($this->user?->avatar_path),
            'initials' => $this->user?->initials(),
            'time' => $this->created_at?->diffForHumans(short: true),
            'likes_count' => (int) $this->likes_count,
            'liked' => $this->when($auth !== null, fn () => $this->likes()->whereKey($auth->getKey())->exists()),
            'is_owner' => $isOwner,
            'replies' => CommentResource::collection($this->whenLoaded('replies')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

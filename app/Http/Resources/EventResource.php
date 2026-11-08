<?php

namespace App\Http\Resources;

use App\Models\Event;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Event */
class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $auth = $request->user('sanctum');

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'category' => $this->category,
            'location' => $this->location,
            'venue' => $this->venue,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'date' => $this->starts_at?->format('D, M j · g:i A'),
            'price' => (float) $this->price,
            'currency' => $this->currency,
            'image' => Media::url($this->image_path),
            'video_url' => $this->video_stream_path ? url('/api/events/'.$this->id.'/video/'.basename($this->video_stream_path).'/master.m3u8') : $this->video_url,
            'video_saver_url' => $this->video_stream_path ? url('/api/events/'.$this->id.'/video/'.basename($this->video_stream_path).'/v0/index.m3u8') : null,
            'video_status' => $this->video_status,
            'video_variants' => $this->video_variants,
            'has_video' => $this->has_video,
            'is_public' => $this->is_public,
            'likes_count' => (int) $this->likes_count,
            'comments_count' => (int) $this->comments_count,
            'saves_count' => (int) $this->saves_count,
            'shares_count' => (int) $this->shares_count,
            'host' => new UserResource($this->whenLoaded('host')),
            'avatar' => $this->host?->initials(),
            'liked' => $this->when($auth !== null, fn () => isset($this->is_liked)
                ? (bool) $this->is_liked
                : $this->likes()->whereKey($auth->getKey())->exists()),
            'saved' => $this->when($auth !== null, fn () => isset($this->is_saved)
                ? (bool) $this->is_saved
                : $this->savedBy()->whereKey($auth->getKey())->exists()),
            'is_owner' => $auth ? $this->isHostedBy($auth) : false,
            'archived_at' => $this->when($auth && $this->isHostedBy($auth), $this->archived_at?->toIso8601String()),
            'comments_list' => CommentResource::collection($this->whenLoaded('rootComments')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

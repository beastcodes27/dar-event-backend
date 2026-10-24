<?php

namespace App\Http\Resources;

use App\Models\Story;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Story */
class StoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'media' => Media::url($this->media_path),
            'caption' => $this->caption,
            'is_live' => $this->is_live,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'user' => new UserResource($this->whenLoaded('user')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\StoryHighlight;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StoryHighlight */
class StoryHighlightResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'icon' => $this->icon,
            'cover' => Media::url($this->cover_path),
            'position' => (int) $this->position,
            'stories_count' => $this->when(isset($this->stories_count), fn () => (int) $this->stories_count),
            'stories' => StoryResource::collection($this->whenLoaded('stories')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

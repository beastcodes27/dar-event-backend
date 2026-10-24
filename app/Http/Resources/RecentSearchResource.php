<?php

namespace App\Http\Resources;

use App\Models\RecentSearch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RecentSearch */
class RecentSearchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'query' => $this->query,
            'text' => $this->query,
            'type' => $this->type,
            'subtitle' => $this->subtitle,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

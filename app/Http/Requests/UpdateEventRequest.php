<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('event')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['sometimes', 'required', 'string', Rule::in(Event::CATEGORIES)],
            'location' => ['sometimes', 'required', 'string', 'max:150'],
            'venue' => ['nullable', 'string', 'max:150'],
            'starts_at' => ['sometimes', 'required', 'date'],
            'ends_at' => ['nullable', 'date'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'image' => ['nullable', 'image', 'max:5120'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'video_url' => ['nullable', 'url', 'max:2048'],
            'has_video' => ['nullable', 'boolean'],
            'is_public' => ['nullable', 'boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'media' => ['nullable', 'image', 'max:10240'],
            'caption' => ['nullable', 'string', 'max:500'],
            'is_live' => ['sometimes', 'boolean'],
            'story_highlight_id' => ['nullable', 'integer', 'exists:story_highlights,id'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}

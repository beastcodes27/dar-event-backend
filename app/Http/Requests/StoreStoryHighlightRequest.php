<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStoryHighlightRequest extends FormRequest
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
            'label' => ['required', 'string', 'max:30'],
            'icon' => ['nullable', 'string', 'max:50'],
            'cover' => ['nullable', 'image', 'max:5120'],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('body') && $this->has('text')) {
            $this->merge(['body' => $this->input('text')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:1000'],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('comments', 'id')->where('event_id', $this->route('event')->getKey()),
            ],
        ];
    }
}

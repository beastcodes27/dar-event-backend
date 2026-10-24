<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRecentSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('query') && $this->has('text')) {
            $this->merge(['query' => $this->input('text')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'max:150'],
            'type' => ['nullable', 'string', 'max:30'],
            'subtitle' => ['nullable', 'string', 'max:150'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('fullName', $this->input('displayName'));

        if (! $this->has('name') && $name !== null) {
            $this->merge(['name' => $name]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->user()->getKey();

        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'username' => [
                'sometimes', 'required', 'string', 'min:3', 'max:30',
                'regex:/^[A-Za-z0-9_.]+$/',
                Rule::unique('users', 'username')->ignore($userId),
            ],
            'email' => [
                'sometimes', 'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'bio' => ['nullable', 'string', 'max:150'],
            'category' => ['sometimes', 'required', 'string', Rule::in([
                'Digital Creator', "NGO's", 'Education', 'Event Organizer', 'Artist', 'Business', 'Personal',
            ])],
            'gender' => ['nullable', 'string', Rule::in(['Male', 'Female', 'Other', 'Prefer not to say'])],
            'country' => ['nullable', 'string', 'max:100'],
        ];
    }
}

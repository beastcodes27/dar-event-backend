<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->input('name', $this->input('fullName')),
            'password_confirmation' => $this->input('password_confirmation', $this->input('confirmPassword')),
            'terms' => $this->input('terms', $this->input('termsAccepted')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[A-Za-z0-9_.]+$/', 'unique:users,username'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'category' => ['required', 'string', Rule::in([
                'Digital Creator', "NGO's", 'Education', 'Event Organizer', 'Artist', 'Business', 'Personal',
            ])],
            'bio' => ['nullable', 'string', 'max:150'],
            'gender' => ['nullable', 'string', Rule::in(['Male', 'Female', 'Other', 'Prefer not to say'])],
            'country' => ['nullable', 'string', 'max:100'],
            'terms' => ['required', 'accepted'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'terms.accepted' => 'You must accept the terms and conditions.',
        ];
    }
}

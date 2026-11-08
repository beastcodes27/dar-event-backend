<?php

namespace App\Http\Requests;

use App\Rules\AvailableRegistrationIdentifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
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
            'username' => is_string($this->input('username')) ? Str::lower(trim($this->input('username'))) : $this->input('username'),
            'email' => is_string($this->input('email')) ? Str::lower(trim($this->input('email'))) : $this->input('email'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'username' => [...self::identifierRules('username'), new AvailableRegistrationIdentifier],
            'email' => [...self::identifierRules('email'), new AvailableRegistrationIdentifier],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'category' => ['required', 'string', Rule::in([
                'Digital Creator', "NGO's", 'Education', 'Event Organizer', 'Artist', 'Business', 'Personal',
            ])],
            'bio' => ['nullable', 'string', 'max:150'],
            'gender' => ['nullable', 'string', Rule::in(['Male', 'Female', 'Other', 'Prefer not to say'])],
            'country' => ['nullable', 'string', 'max:100'],
            'terms' => ['required', 'accepted'],
            'terms_version' => ['required', Rule::in([config('legal.terms.version')])],
            'privacy_policy_version' => ['required', Rule::in([config('legal.privacy.version')])],
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
            'terms_version.required' => 'Review the current Terms and Conditions before creating your account.',
            'terms_version.in' => 'The Terms and Conditions have changed. Review the current version and try again.',
            'privacy_policy_version.required' => 'Review the current Privacy Policy before creating your account.',
            'privacy_policy_version.in' => 'The Privacy Policy has changed. Review the current version and try again.',
        ];
    }

    /** @return list<string> */
    public static function identifierRules(string $field): array
    {
        return $field === 'username'
            ? ['bail', 'required', 'string', 'min:3', 'max:30', 'regex:/^[A-Za-z0-9_.]+$/']
            : ['bail', 'required', 'string', 'email', 'max:255'];
    }
}

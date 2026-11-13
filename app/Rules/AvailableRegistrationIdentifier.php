<?php

namespace App\Rules;

use App\Models\User;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

class AvailableRegistrationIdentifier implements ValidationRule
{
    public static function isTaken(string $field, string $value): bool
    {
        if ($field === 'username' && Str::lower(trim($value)) === 'veylo') {
            return true;
        }
        $condition = match ($field) {
            'username' => 'LOWER(username) = ?',
            'email' => 'LOWER(email) = ?',
            'phone' => 'phone = ?',
        };

        return User::query()->whereRaw($condition, [$field === 'phone' ? PhoneNumber::normalize($value) : Str::lower(trim($value))])->exists();
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::isTaken($attribute, $value)) {
            $fail($attribute === 'username' ? 'This username is already taken.' : ($attribute === 'phone' ? 'This phone number is already registered.' : 'This email is already registered.'));
        }
    }
}

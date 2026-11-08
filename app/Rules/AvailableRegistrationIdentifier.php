<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

class AvailableRegistrationIdentifier implements ValidationRule
{
    public static function isTaken(string $field, string $value): bool
    {
        $condition = match ($field) {
            'username' => 'LOWER(username) = ?',
            'email' => 'LOWER(email) = ?',
        };

        return User::query()->whereRaw($condition, [Str::lower(trim($value))])->exists();
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::isTaken($attribute, $value)) {
            $fail($attribute === 'username' ? 'This username is already taken.' : 'This email is already registered.');
        }
    }
}

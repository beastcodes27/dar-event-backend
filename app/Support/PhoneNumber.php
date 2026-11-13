<?php

namespace App\Support;

class PhoneNumber
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $number = preg_replace('/[\s().-]/', '', trim($value));
        if (str_starts_with($number, '00')) {
            $number = substr($number, 2);
        }

        return str_starts_with($number, '+') ? $number : '+'.$number;
    }
}

<?php

namespace App\Support;

final class PhoneNumber
{
    public static function valid(mixed $value): bool
    {
        if (! (is_string($value)
            && preg_match('/^\+?[0-9().\s-]+$/D', $value) === 1
            && preg_match('/[0-9]/', $value) === 1)) return false;
        $digits = preg_replace('/\D/', '', $value);
        return str_starts_with(trim($value), '+')
            ? strlen($digits) >= 8 && strlen($digits) <= 15
            : str_starts_with($digits, '0') && in_array(strlen($digits), [10, 11], true);
    }

    public static function canonical(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value);
        return str_starts_with($digits, '84') ? '0'.substr($digits, 2) : $digits;
    }
}

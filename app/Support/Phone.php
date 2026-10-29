<?php

namespace App\Support;

final class Phone
{
    /**
     * Normalizes a Brazilian mobile/landline number to digits with country code (5511999998888).
     * Returns null when it does not look like a valid number.
     */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $value) ?? '';

        if (in_array(strlen($digits), [10, 11], true)) {
            $digits = '55'.$digits;
        }

        return preg_match('/^55\d{10,11}$/', $digits) === 1 ? $digits : null;
    }
}

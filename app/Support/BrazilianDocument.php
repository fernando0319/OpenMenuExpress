<?php

namespace App\Support;

/**
 * CPF and CNPJ: digits only, with the check digits verified.
 */
final class BrazilianDocument
{
    /**
     * Returns the digits of a valid CPF (11) or CNPJ (14), or null.
     */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value) ?? '';

        return match (strlen($digits)) {
            11 => self::validCpf($digits) ? $digits : null,
            14 => self::validCnpj($digits) ? $digits : null,
            default => null,
        };
    }

    private static function validCpf(string $cpf): bool
    {
        if (preg_match('/^(\d)\1{10}$/', $cpf) === 1) {
            return false;
        }

        foreach ([9, 10] as $length) {
            $sum = 0;

            for ($i = 0; $i < $length; $i++) {
                $sum += (int) $cpf[$i] * ($length + 1 - $i);
            }

            $digit = (10 * $sum) % 11 % 10;

            if ($digit !== (int) $cpf[$length]) {
                return false;
            }
        }

        return true;
    }

    private static function validCnpj(string $cnpj): bool
    {
        if (preg_match('/^(\d)\1{13}$/', $cnpj) === 1) {
            return false;
        }

        foreach ([12, 13] as $length) {
            $sum = 0;
            $weight = $length - 7;

            for ($i = 0; $i < $length; $i++) {
                $sum += (int) $cnpj[$i] * $weight;
                $weight = $weight === 2 ? 9 : $weight - 1;
            }

            $digit = $sum % 11 < 2 ? 0 : 11 - $sum % 11;

            if ($digit !== (int) $cnpj[$length]) {
                return false;
            }
        }

        return true;
    }
}

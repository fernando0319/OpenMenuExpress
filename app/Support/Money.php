<?php

namespace App\Support;

final class Money
{
    /**
     * Parses a price typed by a person ("12,50", "1.234,56", "R$ 9", "12.5") into cents.
     * Returns null when the text is not a valid non-negative amount.
     */
    public static function parseToCents(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value * 100 : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $text = str_replace(['R$', ' ', "\u{00A0}"], '', trim($value));

        if (str_contains($text, ',')) {
            // Brazilian format: dots are thousand separators, comma is the decimal mark.
            $text = str_replace(['.', ','], ['', '.'], $text);
        }

        if (preg_match('/^\d{1,9}(\.\d{1,2})?$/', $text) !== 1) {
            return null;
        }

        [$reais, $centavos] = array_pad(explode('.', $text), 2, '0');

        return (int) $reais * 100 + (int) str_pad($centavos, 2, '0');
    }

    /**
     * Formats cents as a plain decimal string for form inputs ("1234,56").
     */
    public static function toInput(?int $cents): string
    {
        if ($cents === null) {
            return '';
        }

        return intdiv($cents, 100).','.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}

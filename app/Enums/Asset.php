<?php

namespace App\Enums;

/**
 * What a store can be paid in. Amounts are kept as integers in the smallest unit of each.
 */
enum Asset: string
{
    case Brl = 'BRL';
    case Btc = 'BTC';

    public function label(): string
    {
        return match ($this) {
            self::Brl => 'Reais',
            self::Btc => 'Bitcoin',
        };
    }

    /**
     * Decimal places of one whole unit: 100 centavos in a real, 100 million satoshis in a bitcoin.
     */
    public function decimals(): int
    {
        return match ($this) {
            self::Brl => 2,
            self::Btc => 8,
        };
    }

    /**
     * "0.00012345" → 12345, without going through floats. Null when it is not a plain decimal.
     */
    public function toUnits(string $decimal): ?int
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', trim($decimal), $parts) !== 1) {
            return null;
        }

        $fraction = substr(str_pad($parts[2] ?? '', $this->decimals(), '0'), 0, $this->decimals());

        return (int) ($parts[1].$fraction);
    }

    /**
     * 12345 → "0.00012345".
     */
    public function format(int $units): string
    {
        $sign = $units < 0 ? '-' : '';
        $digits = str_pad((string) abs($units), $this->decimals() + 1, '0', STR_PAD_LEFT);

        return $sign.substr($digits, 0, -$this->decimals()).'.'.substr($digits, -$this->decimals());
    }
}

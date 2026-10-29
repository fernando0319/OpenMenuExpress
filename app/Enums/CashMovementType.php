<?php

namespace App\Enums;

enum CashMovementType: string
{
    case Sale = 'sale';
    case Refund = 'refund';
    case Supply = 'supply';
    case Withdrawal = 'withdrawal';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Venda',
            self::Refund => 'Estorno',
            self::Supply => 'Suprimento',
            self::Withdrawal => 'Sangria',
        };
    }

    /**
     * +1 when money comes in, -1 when it goes out.
     */
    public function sign(): int
    {
        return match ($this) {
            self::Sale, self::Supply => 1,
            self::Refund, self::Withdrawal => -1,
        };
    }
}

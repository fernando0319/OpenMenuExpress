<?php

namespace App\Enums;

enum TabStatus: string
{
    case Open = 'open';
    case Closing = 'closing';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Ocupada',
            self::Closing => 'Conta pedida',
            self::Closed => 'Fechada',
            self::Cancelled => 'Cancelada',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Open || $this === self::Closing;
    }

    /**
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Open, self::Closing];
    }
}

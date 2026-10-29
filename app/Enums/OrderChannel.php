<?php

namespace App\Enums;

enum OrderChannel: string
{
    case Online = 'online';
    case Pos = 'pos';
    case Waiter = 'waiter';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Cardápio online',
            self::Pos => 'PDV',
            self::Waiter => 'Garçom',
        };
    }
}

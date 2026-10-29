<?php

namespace App\Enums;

enum StoreRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Attendant = 'attendant';
    case Waiter = 'waiter';
    case Courier = 'courier';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Dono',
            self::Manager => 'Gerente',
            self::Attendant => 'Atendente',
            self::Waiter => 'Garçom',
            self::Courier => 'Entregador',
        };
    }
}

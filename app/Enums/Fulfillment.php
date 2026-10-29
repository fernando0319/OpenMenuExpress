<?php

namespace App\Enums;

enum Fulfillment: string
{
    case Delivery = 'delivery';
    case Pickup = 'pickup';
    case DineIn = 'dine_in';

    public function label(): string
    {
        return match ($this) {
            self::Delivery => 'Entrega',
            self::Pickup => 'Retirada',
            self::DineIn => 'Mesa',
        };
    }
}

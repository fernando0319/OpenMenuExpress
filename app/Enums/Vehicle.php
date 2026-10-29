<?php

namespace App\Enums;

enum Vehicle: string
{
    case Motorcycle = 'motorcycle';
    case Bicycle = 'bicycle';
    case Car = 'car';

    public function label(): string
    {
        return match ($this) {
            self::Motorcycle => 'Moto',
            self::Bicycle => 'Bicicleta',
            self::Car => 'Carro',
        };
    }

    /**
     * Motor vehicles carry a plate.
     */
    public function hasPlate(): bool
    {
        return $this !== self::Bicycle;
    }
}

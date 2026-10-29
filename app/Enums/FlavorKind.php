<?php

namespace App\Enums;

enum FlavorKind: string
{
    case Savory = 'savory';
    case Sweet = 'sweet';

    public function label(): string
    {
        return match ($this) {
            self::Savory => 'Salgada',
            self::Sweet => 'Doce',
        };
    }
}

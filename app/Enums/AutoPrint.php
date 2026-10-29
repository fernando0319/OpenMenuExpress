<?php

namespace App\Enums;

enum AutoPrint: string
{
    case Off = 'off';
    case WhenPlaced = 'placed';
    case WhenAccepted = 'accepted';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Só quando eu clicar em Imprimir',
            self::WhenPlaced => 'Assim que o pedido chega',
            self::WhenAccepted => 'Quando eu aceito o pedido',
        };
    }
}

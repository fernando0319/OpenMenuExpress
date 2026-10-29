<?php

namespace App\Enums;

enum DeliveryFeeMode: string
{
    /** Fixed fee per neighborhood / region (delivery_zones). */
    case Zone = 'zone';

    /** Fee per km from the store, with an optional free radius. */
    case Distance = 'distance';

    public function label(): string
    {
        return match ($this) {
            self::Zone => 'Por bairro ou região',
            self::Distance => 'Por distância (km)',
        };
    }
}

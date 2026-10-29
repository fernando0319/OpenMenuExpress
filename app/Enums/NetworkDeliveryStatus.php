<?php

namespace App\Enums;

/**
 * A run of the courier network: offered to nearby couriers until one accepts, then picked up
 * at the store and delivered. Only delivered runs are charged to the store and paid to the courier.
 */
enum NetworkDeliveryStatus: string
{
    case Searching = 'searching';
    case Accepted = 'accepted';
    case PickedUp = 'picked_up';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Searching => 'Procurando entregador',
            self::Accepted => 'Entregador a caminho da loja',
            self::PickedUp => 'Em rota de entrega',
            self::Delivered => 'Entregue',
            self::Cancelled => 'Cancelada',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    /**
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Searching, self::Accepted, self::PickedUp];
    }
}

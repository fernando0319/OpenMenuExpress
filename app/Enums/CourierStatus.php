<?php

namespace App\Enums;

/**
 * Where a network courier's registration stands. Only approved couriers get runs.
 */
enum CourierStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Em análise',
            self::Approved => 'Aprovado',
            self::Rejected => 'Recusado',
            self::Blocked => 'Bloqueado',
        };
    }
}

<?php

namespace App\Enums;

enum InstallmentStatus: string
{
    case Open = 'open';
    case Overdue = 'overdue';
    case Paid = 'paid';
    case Canceled = 'canceled';

    public function label(FinanceType $type): string
    {
        return match ($this) {
            self::Open => 'Em aberto',
            self::Overdue => 'Vencida',
            self::Paid => $type === FinanceType::Payable ? 'Paga' : 'Recebida',
            self::Canceled => 'Cancelada',
        };
    }
}

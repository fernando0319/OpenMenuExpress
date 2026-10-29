<?php

namespace App\Enums;

/**
 * Payment methods settled outside the system (on delivery or at the counter).
 * Online gateways will plug in through the PaymentGateway contract.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Pix = 'pix';
    case DebitCard = 'debit_card';
    case CreditCard = 'credit_card';
    case MealVoucher = 'meal_voucher';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Dinheiro',
            self::Pix => 'PIX',
            self::DebitCard => 'Cartão de débito',
            self::CreditCard => 'Cartão de crédito',
            self::MealVoucher => 'Vale-refeição',
        };
    }
}

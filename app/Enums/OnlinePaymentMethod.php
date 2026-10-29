<?php

namespace App\Enums;

/**
 * Paid in the app, before the order reaches the store. Kept apart from PaymentMethod
 * (settled on delivery or at the counter).
 */
enum OnlinePaymentMethod: string
{
    case Pix = 'online_pix';
    case Card = 'online_card';
    case Bitcoin = 'online_bitcoin';
    case Lightning = 'online_lightning';

    public function label(): string
    {
        return match ($this) {
            self::Pix => 'Pix (pague agora)',
            self::Card => 'Cartão de crédito online',
            self::Bitcoin => 'Bitcoin',
            self::Lightning => 'Bitcoin Lightning (pague agora)',
        };
    }

    /**
     * How it shows on the order once paid ("Pix online (pago)").
     */
    public function orderLabel(): string
    {
        return match ($this) {
            self::Pix => 'Pix online',
            self::Card => 'Cartão online',
            self::Bitcoin => 'Bitcoin',
            self::Lightning => 'Lightning',
        };
    }
}

<?php

namespace App\Enums;

enum PaymentProvider: string
{
    // The settings screen lists them in this order: the one that needs no contract first.
    case PixDirect = 'pix_direct';
    case MercadoPago = 'mercadopago';
    case Sicoob = 'sicoob';
    case BtcPay = 'btcpay';

    public function label(): string
    {
        return match ($this) {
            self::MercadoPago => 'Mercado Pago',
            self::Sicoob => 'Sicoob (Pix)',
            self::BtcPay => 'BTCPay Server',
            self::PixDirect => 'Pix direto',
        };
    }

    /**
     * @return list<OnlinePaymentMethod>
     */
    public function methods(): array
    {
        return match ($this) {
            self::MercadoPago => [OnlinePaymentMethod::Pix, OnlinePaymentMethod::Card],
            self::Sicoob, self::PixDirect => [OnlinePaymentMethod::Pix],
            self::BtcPay => [OnlinePaymentMethod::Bitcoin],
        };
    }

    /**
     * Whether a paid order can be refunded through the API when the store cancels it.
     */
    public function refundsAutomatically(): bool
    {
        return ! in_array($this, [self::BtcPay, self::PixDirect], true);
    }

    /**
     * No provider tells the system the money arrived: the store checks its bank app and confirms.
     */
    public function confirmsManually(): bool
    {
        return $this === self::PixDirect;
    }
}

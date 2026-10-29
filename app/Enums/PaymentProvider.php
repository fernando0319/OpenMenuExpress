<?php

namespace App\Enums;

enum PaymentProvider: string
{
    case MercadoPago = 'mercadopago';
    case Sicoob = 'sicoob';
    case BtcPay = 'btcpay';

    public function label(): string
    {
        return match ($this) {
            self::MercadoPago => 'Mercado Pago',
            self::Sicoob => 'Sicoob (Pix)',
            self::BtcPay => 'BTCPay Server',
        };
    }

    /**
     * @return list<OnlinePaymentMethod>
     */
    public function methods(): array
    {
        return match ($this) {
            self::MercadoPago => [OnlinePaymentMethod::Pix, OnlinePaymentMethod::Card],
            self::Sicoob => [OnlinePaymentMethod::Pix],
            self::BtcPay => [OnlinePaymentMethod::Bitcoin],
        };
    }

    /**
     * Whether a paid order can be refunded through the API when the store cancels it.
     */
    public function refundsAutomatically(): bool
    {
        return $this !== self::BtcPay;
    }
}

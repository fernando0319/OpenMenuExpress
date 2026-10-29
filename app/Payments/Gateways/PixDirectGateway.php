<?php

namespace App\Payments\Gateways;

use App\Enums\OnlinePaymentMethod;
use App\Models\OnlinePayment;
use App\Models\PaymentAccount;
use App\Payments\Charge;
use App\Payments\ChargeContext;
use App\Payments\PaymentGateway;
use App\Payments\PixBrCode;
use App\Payments\WebhookReference;
use Illuminate\Http\Request;

/**
 * Pix straight to the store's key, with no bank API: the system writes the static Pix code with
 * the amount and an identifier, and the store confirms in the panel once the money shows up in
 * its bank app. Nothing to contract, homologate or keep secret.
 */
final class PixDirectGateway implements PaymentGateway
{
    public function createCharge(PaymentAccount $account, OnlinePayment $payment, OnlinePaymentMethod $method, ChargeContext $context): Charge
    {
        $txid = self::txid($payment);

        return new Charge(
            providerRef: $txid,
            pixCode: PixBrCode::payload(
                (string) $account->credential('pix_key'),
                (string) $account->credential('merchant_name'),
                (string) $account->credential('merchant_city'),
                $context->amountCents,
                $txid,
            ),
            expiresAt: $context->expiresAt,
        );
    }

    /**
     * Only the store knows: whatever it confirmed is the status.
     */
    public function status(PaymentAccount $account, OnlinePayment $payment): string
    {
        return $payment->status;
    }

    /**
     * The money is in the store's account: it gives it back from its own bank app.
     */
    public function refund(PaymentAccount $account, OnlinePayment $payment): bool
    {
        return false;
    }

    public function webhookReference(PaymentAccount $account, Request $request): ?WebhookReference
    {
        return null;
    }

    /**
     * "MX" + the charge id: short, unique, and easy to find in the statement.
     */
    public static function txid(OnlinePayment $payment): string
    {
        return PixBrCode::txid('MX'.$payment->id);
    }
}

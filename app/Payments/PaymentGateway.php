<?php

namespace App\Payments;

use App\Enums\OnlinePaymentMethod;
use App\Models\OnlinePayment;
use App\Models\PaymentAccount;
use Illuminate\Http\Request;

/**
 * A payment provider the store connects with its own account. The money goes straight to it.
 *
 * Statuses always come from the provider's API (status()), never from a notification body:
 * webhooks only say "look at this one".
 */
interface PaymentGateway
{
    /**
     * Creates the charge for an order (Pix code, card checkout or Bitcoin invoice).
     *
     * @throws PaymentException
     */
    public function createCharge(PaymentAccount $account, OnlinePayment $payment, OnlinePaymentMethod $method, ChargeContext $context): Charge;

    /**
     * Current status at the provider: one of the OnlinePayment status constants.
     *
     * @throws PaymentException
     */
    public function status(PaymentAccount $account, OnlinePayment $payment): string;

    /**
     * Gives the money back. False when the provider does not refund through its API.
     *
     * @throws PaymentException
     */
    public function refund(PaymentAccount $account, OnlinePayment $payment): bool;

    /**
     * Which charge a notification is about: our online payment id or the provider reference.
     * Null when the notification is not for a charge (or fails its signature).
     */
    public function webhookReference(PaymentAccount $account, Request $request): ?WebhookReference;
}

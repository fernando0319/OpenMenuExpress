<?php

namespace App\Http\Controllers;

use App\Models\OnlinePayment;
use App\Models\PaymentAccount;
use App\Payments\OnlinePayments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifications from the providers. The URL carries the account's secret token; the body only
 * tells which charge to look at, and its status is always read again from the provider's API.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, OnlinePayments $payments, string $token): JsonResponse
    {
        $account = PaymentAccount::query()->where('webhook_token', $token)->first();

        if (! $account instanceof PaymentAccount) {
            return response()->json(['ok' => false], 404);
        }

        $reference = OnlinePayments::gateway($account->provider)->webhookReference($account, $request);

        if ($reference !== null) {
            $payment = OnlinePayment::query()
                ->where('payment_account_id', $account->id)
                ->when($reference->onlinePaymentId !== null, fn ($query) => $query->whereKey($reference->onlinePaymentId))
                ->when($reference->onlinePaymentId === null, fn ($query) => $query->where('provider_ref', $reference->providerRef))
                ->first();

            if ($payment instanceof OnlinePayment) {
                $payments->sync($payment);
            }
        }

        // Always 200 for a known account: providers retry on errors, and there is nothing to retry.
        return response()->json(['ok' => true]);
    }
}

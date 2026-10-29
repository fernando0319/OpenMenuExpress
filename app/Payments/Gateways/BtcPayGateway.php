<?php

namespace App\Payments\Gateways;

use App\Enums\Asset;
use App\Enums\OnlinePaymentMethod;
use App\Models\OnlinePayment;
use App\Models\PaymentAccount;
use App\Payments\Charge;
use App\Payments\ChargeContext;
use App\Payments\PaymentException;
use App\Payments\PaymentGateway;
use App\Payments\ReceivedAsset;
use App\Payments\ReportsReceivedAsset;
use App\Payments\WebhookReference;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Bitcoin (on-chain and Lightning) through the store's own BTCPay Server (Greenfield API).
 * The invoice is priced in reais; BTCPay converts and the coins go to the store's wallet.
 * Refunds are made by the merchant in BTCPay itself.
 */
final class BtcPayGateway implements PaymentGateway, ReportsReceivedAsset
{
    public function createCharge(PaymentAccount $account, OnlinePayment $payment, OnlinePaymentMethod $method, ChargeContext $context): Charge
    {
        $data = $this->json($this->api($account)->post($this->storePath($account).'/invoices', [
            'amount' => $context->amount(),
            'currency' => 'BRL',
            'metadata' => [
                'orderId' => $context->orderToken,
                'onlinePaymentId' => $payment->id,
                'itemDesc' => $context->description,
            ],
            'checkout' => [
                'redirectURL' => $context->returnUrl,
                'redirectAutomatically' => true,
                'expirationMinutes' => max(1, (int) now()->diffInMinutes($context->expiresAt)),
            ],
        ]));

        if (! isset($data['id'], $data['checkoutLink'])) {
            throw new PaymentException('O BTCPay não devolveu a fatura de pagamento.');
        }

        $expires = isset($data['expirationTime']) && is_numeric($data['expirationTime'])
            ? CarbonImmutable::createFromTimestamp((int) $data['expirationTime'])
            : $context->expiresAt;

        return new Charge((string) $data['id'], checkoutUrl: (string) $data['checkoutLink'], expiresAt: $expires);
    }

    public function status(PaymentAccount $account, OnlinePayment $payment): string
    {
        $data = $this->json($this->api($account)->get($this->storePath($account).'/invoices/'.rawurlencode((string) $payment->provider_ref)));

        return match ($data['status'] ?? null) {
            'Settled' => OnlinePayment::PAID,
            'Expired' => OnlinePayment::EXPIRED,
            'Invalid' => OnlinePayment::FAILED,
            default => OnlinePayment::PENDING, // New, Processing (waiting confirmations)
        };
    }

    public function refund(PaymentAccount $account, OnlinePayment $payment): bool
    {
        return false;
    }

    /**
     * The bitcoin paid on the invoice, on-chain and Lightning together (BTCPay states both in BTC).
     */
    public function receivedAsset(PaymentAccount $account, OnlinePayment $payment): ?ReceivedAsset
    {
        try {
            $response = $this->api($account)->get(
                $this->storePath($account).'/invoices/'.rawurlencode((string) $payment->provider_ref).'/payment-methods',
            );
        } catch (ConnectionException $exception) {
            throw new PaymentException('O BTCPay não respondeu: '.$exception->getMessage());
        }

        $methods = $this->json($response);

        $sats = 0;

        foreach ($methods as $method) {
            if (! is_array($method)) {
                continue;
            }

            // "BTC-CHAIN", "BTC-LN", "BTC-LNURL" (BTCPay 2) or "BTC", "BTC-LightningNetwork" (BTCPay 1).
            $id = (string) ($method['paymentMethodId'] ?? $method['paymentMethod'] ?? '');
            $paid = $method['paymentMethodPaid'] ?? $method['totalPaid'] ?? null;

            if (str_starts_with(strtoupper($id), 'BTC') && is_scalar($paid)) {
                $sats += Asset::Btc->toUnits((string) $paid) ?? 0;
            }
        }

        return $sats > 0 ? new ReceivedAsset(Asset::Btc, $sats) : null;
    }

    /**
     * Signed with the secret we gave BTCPay when registering the webhook (BTCPay-Sig: sha256=...).
     */
    public function webhookReference(PaymentAccount $account, Request $request): ?WebhookReference
    {
        $secret = (string) $account->credential('webhook_secret');
        $signature = (string) $request->header('BTCPay-Sig');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if ($secret === '' || ! hash_equals($expected, $signature)) {
            return null;
        }

        $invoiceId = $request->input('invoiceId');

        return is_string($invoiceId) && $invoiceId !== '' ? new WebhookReference(providerRef: $invoiceId) : null;
    }

    /**
     * Checks the server, key and store; returns the store name.
     */
    public function verify(PaymentAccount $account): string
    {
        $data = $this->json($this->api($account)->get($this->storePath($account)));

        return (string) ($data['name'] ?? 'Loja BTCPay');
    }

    /**
     * Asks BTCPay to notify us of paid, expired and invalid invoices; returns the signing secret.
     */
    public function registerWebhook(PaymentAccount $account, string $url): string
    {
        $secret = Str::random(40);

        $this->json($this->api($account)->post($this->storePath($account).'/webhooks', [
            'url' => $url,
            'enabled' => true,
            'automaticRedelivery' => true,
            'secret' => $secret,
            'authorizedEvents' => [
                'everything' => false,
                'specificEvents' => ['InvoiceSettled', 'InvoiceExpired', 'InvoiceInvalid', 'InvoiceProcessing'],
            ],
        ]));

        return $secret;
    }

    private function api(PaymentAccount $account): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $account->credential('server_url'), '/'))
            ->withHeaders(['Authorization' => 'token '.$account->credential('api_key')])
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('payments.btcpay.timeout'));
    }

    private function storePath(PaymentAccount $account): string
    {
        return '/api/v1/stores/'.rawurlencode((string) $account->credential('store_id'));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        if (! $response->successful()) {
            throw PaymentException::fromResponse('BTCPay', $response);
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }
}

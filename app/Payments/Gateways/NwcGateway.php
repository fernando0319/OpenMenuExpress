<?php

namespace App\Payments\Gateways;

use App\Enums\Asset;
use App\Enums\OnlinePaymentMethod;
use App\Models\OnlinePayment;
use App\Models\PaymentAccount;
use App\Payments\Charge;
use App\Payments\ChargeContext;
use App\Payments\Nwc\NwcClient;
use App\Payments\Nwc\NwcCodec;
use App\Payments\Nwc\NwcConnection;
use App\Payments\PaymentException;
use App\Payments\PaymentGateway;
use App\Payments\ReceivedAsset;
use App\Payments\ReportsReceivedAsset;
use App\Payments\WebhookReference;
use App\Services\Quotes\BtcQuote;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Lightning straight into the store's own wallet, through Nostr Wallet Connect (NIP-47).
 *
 * The order total in reais is converted at the bitcoin price of the moment, and the wallet itself
 * creates the invoice: the satoshis go from the customer's wallet to the store's, and this server
 * only asks the wallet whether the invoice was paid. It cannot spend: the connection the store
 * gives should only allow creating and looking up invoices.
 */
final class NwcGateway implements PaymentGateway, ReportsReceivedAsset
{
    public function __construct(
        private readonly NwcClient $client,
        private readonly BtcQuote $quote,
    ) {}

    public function createCharge(PaymentAccount $account, OnlinePayment $payment, OnlinePaymentMethod $method, ChargeContext $context): Charge
    {
        $centsPerBtc = $this->quote->centsPerBtc();
        $sats = BtcQuote::satsFor($context->amountCents, $centsPerBtc);

        $result = $this->client->request($this->connection($account), 'make_invoice', [
            'amount' => $sats * 1000, // millisatoshis
            'description' => $context->description,
            'expiry' => max(60, (int) CarbonImmutable::now()->diffInSeconds($context->expiresAt)),
        ], $this->encryption($account));

        $invoice = $result['invoice'] ?? null;
        $hash = $result['payment_hash'] ?? null;

        if (! is_string($invoice) || ! str_starts_with(strtolower($invoice), 'ln') || ! is_string($hash) || $hash === '') {
            throw new PaymentException('A carteira não devolveu a fatura Lightning.');
        }

        return new Charge(
            providerRef: $hash,
            expiresAt: $context->expiresAt,
            paymentRequest: $invoice,
            asset: Asset::Btc,
            assetUnits: $sats,
            rate: intdiv($centsPerBtc, 100).'.'.str_pad((string) ($centsPerBtc % 100), 2, '0', STR_PAD_LEFT),
        );
    }

    public function status(PaymentAccount $account, OnlinePayment $payment): string
    {
        try {
            $result = $this->client->request($this->connection($account), 'lookup_invoice', [
                'payment_hash' => (string) $payment->provider_ref,
            ], $this->encryption($account));
        } catch (PaymentException $exception) {
            // Some wallets only know an invoice a moment after creating it.
            if ($exception->getCode() === 404) {
                return OnlinePayment::PENDING;
            }

            throw $exception;
        }

        $state = strtolower((string) ($result['state'] ?? ''));

        return match (true) {
            ($result['settled_at'] ?? null) !== null || $state === 'settled' => OnlinePayment::PAID,
            in_array($state, ['expired', 'failed'], true) => OnlinePayment::EXPIRED,
            default => OnlinePayment::PENDING,
        };
    }

    /**
     * The satoshis are in the store's wallet: it sends them back from there.
     */
    public function refund(PaymentAccount $account, OnlinePayment $payment): bool
    {
        return false;
    }

    /**
     * Wallets do not call back: the menu and the scheduler ask instead.
     */
    public function webhookReference(PaymentAccount $account, Request $request): ?WebhookReference
    {
        return null;
    }

    /**
     * Exactly the satoshis of the invoice, at the rate locked when it was created.
     */
    public function receivedAsset(PaymentAccount $account, OnlinePayment $payment): ?ReceivedAsset
    {
        return $payment->asset_units === null ? null : new ReceivedAsset(Asset::Btc, $payment->asset_units);
    }

    private function connection(PaymentAccount $account): NwcConnection
    {
        try {
            return NwcConnection::parse((string) $account->credential('connection'));
        } catch (InvalidArgumentException $exception) {
            throw new PaymentException('A conexão com a carteira Lightning está inválida. Conecte de novo.', previous: $exception);
        }
    }

    private function encryption(PaymentAccount $account): string
    {
        return $account->credential('encryption') === NwcCodec::NIP44 ? NwcCodec::NIP44 : NwcCodec::NIP04;
    }
}

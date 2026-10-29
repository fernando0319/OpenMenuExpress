<?php

namespace App\Payments\Gateways;

use App\Enums\OnlinePaymentMethod;
use App\Models\OnlinePayment;
use App\Models\PaymentAccount;
use App\Payments\Charge;
use App\Payments\ChargeContext;
use App\Payments\PaymentException;
use App\Payments\PaymentGateway;
use App\Payments\WebhookReference;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Mercado Pago with the store's own account: Pix through the Payments API (QR code on our page)
 * and card through Checkout Pro (Mercado Pago's page, so card data never touches our server).
 *
 * The store connects by OAuth (platform app configured) or pastes its production Access Token.
 */
final class MercadoPagoGateway implements PaymentGateway
{
    public function createCharge(PaymentAccount $account, OnlinePayment $payment, OnlinePaymentMethod $method, ChargeContext $context): Charge
    {
        $fee = $this->platformFee($account, $context->amountCents);

        if ($method === OnlinePaymentMethod::Pix) {
            $response = $this->api($account)
                ->withHeaders(['X-Idempotency-Key' => $payment->externalReference()])
                ->post('/v1/payments', array_filter([
                    'transaction_amount' => $context->amountCents / 100,
                    'description' => $context->description,
                    'payment_method_id' => 'pix',
                    'payer' => ['email' => "pedido-{$context->orderToken}@".config('payments.mercadopago.payer_email_domain')],
                    'external_reference' => $payment->externalReference(),
                    'notification_url' => $context->notificationUrl,
                    'date_of_expiration' => $context->expiresAt->format('Y-m-d\TH:i:s.vP'),
                    'application_fee' => $fee,
                ], fn ($value) => $value !== null));

            $data = $this->json($response);
            $pix = $data['point_of_interaction']['transaction_data']['qr_code'] ?? null;

            if (! is_string($pix) || ! isset($data['id'])) {
                throw new PaymentException('O Mercado Pago não devolveu o código Pix. Verifique se a conta tem uma chave Pix cadastrada.');
            }

            return new Charge((string) $data['id'], pixCode: $pix, expiresAt: $context->expiresAt);
        }

        $response = $this->api($account)->post('/checkout/preferences', array_filter([
            'items' => [[
                'id' => $context->orderToken,
                'title' => $context->description,
                'quantity' => 1,
                'currency_id' => 'BRL',
                'unit_price' => $context->amountCents / 100,
            ]],
            'external_reference' => $payment->externalReference(),
            'notification_url' => $context->notificationUrl,
            'back_urls' => ['success' => $context->returnUrl, 'pending' => $context->returnUrl, 'failure' => $context->returnUrl],
            'auto_return' => 'approved',
            'expires' => true,
            'expiration_date_to' => $context->expiresAt->format('Y-m-d\TH:i:s.vP'),
            // Boleto takes days; the order would expire long before.
            'payment_methods' => ['excluded_payment_types' => [['id' => 'ticket'], ['id' => 'atm']]],
            'marketplace_fee' => $fee,
        ], fn ($value) => $value !== null));

        $data = $this->json($response);

        if (! isset($data['id'], $data['init_point'])) {
            throw new PaymentException('O Mercado Pago não devolveu o link de pagamento.');
        }

        return new Charge((string) $data['id'], checkoutUrl: (string) $data['init_point'], expiresAt: $context->expiresAt);
    }

    public function status(PaymentAccount $account, OnlinePayment $payment): string
    {
        $statuses = array_column($this->payments($account, $payment), 'status');

        return match (true) {
            in_array('approved', $statuses, true) => OnlinePayment::PAID,
            in_array('refunded', $statuses, true), in_array('charged_back', $statuses, true) => OnlinePayment::REFUNDED,
            default => OnlinePayment::PENDING,
        };
    }

    public function refund(PaymentAccount $account, OnlinePayment $payment): bool
    {
        foreach ($this->payments($account, $payment) as $found) {
            if (($found['status'] ?? null) === 'approved' && isset($found['id'])) {
                $this->json($this->api($account)
                    ->withHeaders(['X-Idempotency-Key' => "refund-{$payment->externalReference()}"])
                    ->post("/v1/payments/{$found['id']}/refunds"));

                return true;
            }
        }

        return false;
    }

    /**
     * Notifications carry only the payment id; the payment itself (fetched with the store's own
     * token) says which of our charges it belongs to.
     */
    public function webhookReference(PaymentAccount $account, Request $request): ?WebhookReference
    {
        $type = $request->input('type') ?? $request->input('topic');
        $id = $request->input('data.id') ?? $request->query('data.id') ?? $request->input('id');

        if ($type !== 'payment' || ! is_scalar($id) || ! preg_match('/^\d+$/', (string) $id)) {
            return null;
        }

        $response = $this->api($account)->get("/v1/payments/{$id}");

        if (! $response->successful()) {
            return null;
        }

        $reference = (string) ($response->json('external_reference') ?? '');

        return preg_match('/^mx-(\d+)$/', $reference, $match) === 1 ? new WebhookReference(onlinePaymentId: (int) $match[1]) : null;
    }

    /**
     * Who the token belongs to, to check a pasted token and label the account.
     *
     * @return array{id: string, label: string}
     */
    public function whoAmI(string $accessToken): array
    {
        $data = $this->json(Http::baseUrl((string) config('payments.mercadopago.api_url'))->withToken($accessToken)->acceptJson()->timeout(15)->get('/users/me'));

        return [
            'id' => (string) ($data['id'] ?? ''),
            'label' => (string) ($data['email'] ?? $data['nickname'] ?? 'Conta Mercado Pago'),
        ];
    }

    /**
     * OAuth: page where the merchant authorizes the platform app on their account.
     */
    public static function authorizeUrl(string $state, string $redirectUri): string
    {
        return config('payments.mercadopago.auth_url').'?'.http_build_query([
            'client_id' => config('payments.mercadopago.client_id'),
            'response_type' => 'code',
            'platform_id' => 'mp',
            'state' => $state,
            'redirect_uri' => $redirectUri,
        ]);
    }

    /**
     * OAuth: trades the authorization code (or a refresh token) for the store's tokens.
     *
     * @return array<string, mixed> credentials to store
     */
    public function exchange(string $grant, string $value, string $redirectUri = ''): array
    {
        $data = $this->json(Http::baseUrl((string) config('payments.mercadopago.api_url'))->acceptJson()->timeout(15)->asForm()->post('/oauth/token', array_filter([
            'client_id' => config('payments.mercadopago.client_id'),
            'client_secret' => config('payments.mercadopago.client_secret'),
            'grant_type' => $grant,
            $grant === 'refresh_token' ? 'refresh_token' : 'code' => $value,
            'redirect_uri' => $redirectUri ?: null,
        ])));

        if (! isset($data['access_token'])) {
            throw new PaymentException('O Mercado Pago não devolveu o acesso à conta.');
        }

        return [
            'mode' => 'oauth',
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'user_id' => isset($data['user_id']) ? (string) $data['user_id'] : null,
            'expires_at' => CarbonImmutable::now()->addSeconds((int) ($data['expires_in'] ?? 15552000))->toIso8601String(),
        ];
    }

    /**
     * The store's payments made for this charge (one Pix, or the card attempts of a checkout).
     *
     * @return list<array<string, mixed>>
     */
    private function payments(PaymentAccount $account, OnlinePayment $payment): array
    {
        $data = $this->json($this->api($account)->get('/v1/payments/search', [
            'external_reference' => $payment->externalReference(),
            'sort' => 'date_created',
            'criteria' => 'desc',
        ]));

        return array_values(array_filter($data['results'] ?? [], 'is_array'));
    }

    private function api(PaymentAccount $account): PendingRequest
    {
        return Http::baseUrl((string) config('payments.mercadopago.api_url'))
            ->withToken($this->accessToken($account))
            ->acceptJson()
            ->asJson()
            ->timeout(20);
    }

    /**
     * OAuth tokens last 180 days: renewed a week before they expire.
     */
    private function accessToken(PaymentAccount $account): string
    {
        $expiresAt = $account->credential('expires_at');
        $refresh = $account->credential('refresh_token');

        if ($account->credential('mode') === 'oauth' && $refresh !== null && $expiresAt !== null
            && CarbonImmutable::parse($expiresAt)->lessThan(CarbonImmutable::now()->addDays(7))) {
            $account->update(['credentials' => [...$account->credentials, ...$this->exchange('refresh_token', $refresh)]]);
        }

        return (string) $account->credential('access_token');
    }

    /**
     * Platform fee in reais, only when the store connected through the platform app (marketplace).
     */
    private function platformFee(PaymentAccount $account, int $amountCents): ?float
    {
        $percent = (float) config('payments.mercadopago.platform_fee_percent');

        if ($percent <= 0 || $account->credential('mode') !== 'oauth') {
            return null;
        }

        return round($amountCents * $percent / 10000, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        if (! $response->successful()) {
            throw PaymentException::fromResponse('Mercado Pago', $response);
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }
}

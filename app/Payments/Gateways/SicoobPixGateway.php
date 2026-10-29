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
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Pix straight to the store's Sicoob account, through the Pix API (Bacen standard): an immediate
 * charge (cob) with a "copia e cola" code. The bank requires the store's own credentials and its
 * digital certificate (mutual TLS) on every call.
 */
final class SicoobPixGateway implements PaymentGateway
{
    public function createCharge(PaymentAccount $account, OnlinePayment $payment, OnlinePaymentMethod $method, ChargeContext $context): Charge
    {
        // 26 to 35 letters and digits, unique per charge.
        $txid = 'MX'.str_pad((string) $payment->id, 10, '0', STR_PAD_LEFT).Str::random(20);
        $seconds = max(60, (int) now()->diffInSeconds($context->expiresAt));

        $data = $this->call($account, fn (PendingRequest $http) => $http->put("/cob/{$txid}", [
            'calendario' => ['expiracao' => $seconds],
            'valor' => ['original' => $context->amount()],
            'chave' => $account->credential('pix_key'),
            'solicitacaoPagador' => mb_substr($context->description, 0, 140),
        ]));

        $code = $data['pixCopiaECola'] ?? $data['brcode'] ?? null;

        if (! is_string($code) || $code === '') {
            throw new PaymentException('O Sicoob não devolveu o código Pix da cobrança.');
        }

        return new Charge($txid, pixCode: $code, expiresAt: $context->expiresAt);
    }

    public function status(PaymentAccount $account, OnlinePayment $payment): string
    {
        $data = $this->charge($account, (string) $payment->provider_ref);

        foreach ($data['pix'] ?? [] as $pix) {
            foreach (is_array($pix) ? ($pix['devolucoes'] ?? []) : [] as $refund) {
                if (is_array($refund) && ($refund['status'] ?? null) === 'DEVOLVIDO') {
                    return OnlinePayment::REFUNDED;
                }
            }
        }

        return match ($data['status'] ?? null) {
            'CONCLUIDA' => OnlinePayment::PAID,
            'REMOVIDA_PELO_USUARIO_RECEBEDOR', 'REMOVIDA_PELO_PSP' => OnlinePayment::EXPIRED,
            default => OnlinePayment::PENDING,
        };
    }

    public function refund(PaymentAccount $account, OnlinePayment $payment): bool
    {
        $data = $this->charge($account, (string) $payment->provider_ref);
        $endToEnd = $data['pix'][0]['endToEndId'] ?? null;

        if (! is_string($endToEnd)) {
            return false;
        }

        $refundId = 'D'.$payment->id.Str::random(12);

        $this->call($account, fn (PendingRequest $http) => $http->put("/pix/{$endToEnd}/devolucao/{$refundId}", [
            'valor' => number_format($payment->amount_cents / 100, 2, '.', ''),
        ]));

        return true;
    }

    /**
     * The bank posts {"pix": [{"txid": ...}]} to the registered URL (+ "/pix").
     */
    public function webhookReference(PaymentAccount $account, Request $request): ?WebhookReference
    {
        $txid = $request->input('pix.0.txid');

        return is_string($txid) && preg_match('/^[A-Za-z0-9]{26,35}$/', $txid) === 1 ? new WebhookReference(providerRef: $txid) : null;
    }

    /**
     * Tells the bank where to notify payments to this Pix key.
     */
    public function registerWebhook(PaymentAccount $account, string $url): void
    {
        $key = rawurlencode((string) $account->credential('pix_key'));

        $this->call($account, fn (PendingRequest $http) => $http->put("/webhook/{$key}", ['webhookUrl' => $url]));
    }

    /**
     * Checks credentials and certificate by asking for an access token.
     */
    public function verify(PaymentAccount $account): void
    {
        Cache::forget($this->tokenKey($account));
        $this->token($account);
    }

    /**
     * @return array<string, mixed>
     */
    private function charge(PaymentAccount $account, string $txid): array
    {
        return $this->call($account, fn (PendingRequest $http) => $http->get("/cob/{$txid}"));
    }

    /**
     * Runs a request with the store's certificate and a bearer token.
     *
     * @param  Closure(PendingRequest): Response  $request
     * @return array<string, mixed>
     */
    private function call(PaymentAccount $account, Closure $request): array
    {
        $token = $this->token($account);

        return $this->withCertificate($account, function (array $options) use ($account, $token, $request): array {
            $response = $request(Http::baseUrl((string) config('payments.sicoob.api_url'))
                ->withOptions($options)
                ->withToken($token)
                ->withHeaders(['client_id' => (string) $account->credential('client_id')])
                ->acceptJson()
                ->asJson()
                ->timeout(20));

            if (! $response->successful()) {
                throw PaymentException::fromResponse('Sicoob', $response);
            }

            $data = $response->json();

            return is_array($data) ? $data : [];
        });
    }

    private function token(PaymentAccount $account): string
    {
        $cached = Cache::get($this->tokenKey($account));

        if (is_string($cached)) {
            return $cached;
        }

        $data = $this->withCertificate($account, function (array $options) use ($account): array {
            $response = Http::withOptions($options)->asForm()->acceptJson()->timeout(20)->post((string) config('payments.sicoob.token_url'), [
                'grant_type' => 'client_credentials',
                'client_id' => $account->credential('client_id'),
                'scope' => config('payments.sicoob.scopes'),
            ]);

            if (! $response->successful()) {
                throw PaymentException::fromResponse('Sicoob', $response);
            }

            $data = $response->json();

            return is_array($data) ? $data : [];
        });

        $token = $data['access_token'] ?? null;

        if (! is_string($token)) {
            throw new PaymentException('O Sicoob não devolveu o token de acesso.');
        }

        Cache::put($this->tokenKey($account), $token, now()->addSeconds(max(60, (int) ($data['expires_in'] ?? 300) - 60)));

        return $token;
    }

    /**
     * The certificate is kept encrypted in the database; it exists on disk only during the call.
     *
     * @template T
     *
     * @param  Closure(array<string, mixed>): T  $callback
     * @return T
     */
    private function withCertificate(PaymentAccount $account, Closure $callback): mixed
    {
        $contents = base64_decode((string) $account->credential('certificate'), true);

        if ($contents === false || $contents === '') {
            throw new PaymentException('Certificado do Sicoob não encontrado. Envie o certificado de novo.');
        }

        $path = tempnam(sys_get_temp_dir(), 'pix');

        if ($path === false) {
            throw new PaymentException('Não foi possível preparar o certificado.');
        }

        try {
            file_put_contents($path, $contents);
            chmod($path, 0600);

            return $callback([
                'cert' => [$path, (string) $account->credential('certificate_password')],
                // Guzzle 8 refuses CURLOPT_SSLCERTTYPE among raw curl options.
                'cert_type' => 'P12',
            ]);
        } finally {
            @unlink($path);
        }
    }

    private function tokenKey(PaymentAccount $account): string
    {
        return "payments:sicoob:token:{$account->id}";
    }
}

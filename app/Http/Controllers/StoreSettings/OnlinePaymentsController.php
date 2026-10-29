<?php

namespace App\Http\Controllers\StoreSettings;

use App\Enums\OnlinePaymentMethod;
use App\Enums\PaymentProvider;
use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\PaymentAccount;
use App\Models\Store;
use App\Payments\ClientCertificate;
use App\Payments\Gateways\BtcPayGateway;
use App\Payments\Gateways\MercadoPagoGateway;
use App\Payments\Gateways\SicoobPixGateway;
use App\Payments\PaymentException;
use App\Support\BrazilianDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Where the store connects its own payment accounts. Each sale's money goes straight to them.
 */
class OnlinePaymentsController extends Controller
{
    use InteractsWithCurrentStore;

    public function edit(): Response
    {
        $accounts = $this->currentStore()->paymentAccounts()->get()->keyBy(fn (PaymentAccount $account) => $account->provider->value);

        return Inertia::render('store-settings/OnlinePayments', [
            'providers' => array_map(function (PaymentProvider $provider) use ($accounts): array {
                $account = $accounts->get($provider->value);

                return [
                    'provider' => $provider->value,
                    'label' => $provider->label(),
                    'methods' => array_map(fn (OnlinePaymentMethod $method) => ['value' => $method->value, 'label' => $method->orderLabel()], $provider->methods()),
                    'refundsAutomatically' => $provider->refundsAutomatically(),
                    'account' => $account === null ? null : [
                        'id' => $account->id,
                        'label' => $account->account_label,
                        'methods' => $account->methods,
                        'isActive' => $account->is_active,
                        'lastError' => $account->last_error,
                        'mode' => $account->credential('mode'),
                        'pixKey' => $account->credential('pix_key'),
                        'clientId' => $account->credential('client_id'),
                        'serverUrl' => $account->credential('server_url'),
                        'storeId' => $account->credential('store_id'),
                        'pixKeyType' => $account->credential('pix_key_type'),
                        'merchantName' => $account->credential('merchant_name'),
                        'merchantCity' => $account->credential('merchant_city'),
                    ],
                ];
            }, PaymentProvider::cases()),
            'storeDefaults' => ['name' => $this->currentStore()->name, 'city' => $this->currentStore()->address_city],
            'mercadoPagoOAuth' => filled(config('payments.mercadopago.client_id')) && filled(config('payments.mercadopago.client_secret')),
            'expirationMinutes' => (int) config('payments.expiration_minutes'),
        ]);
    }

    /**
     * OAuth: sends the merchant to Mercado Pago to authorize the platform app on their account.
     */
    public function connectMercadoPago(Request $request): SymfonyResponse
    {
        abort_unless(filled(config('payments.mercadopago.client_id')), 404);

        $state = Str::random(40);
        $request->session()->put('mercadopago_oauth', ['state' => $state, 'store' => $this->currentStore()->id]);

        return Inertia::location(MercadoPagoGateway::authorizeUrl($state, route('store-settings.online-payments.mercadopago.callback')));
    }

    public function mercadoPagoCallback(Request $request, MercadoPagoGateway $gateway): RedirectResponse
    {
        $expected = $request->session()->pull('mercadopago_oauth');
        $store = $this->currentStore();

        if (! is_array($expected) || ($expected['state'] ?? null) !== $request->query('state') || ($expected['store'] ?? null) !== $store->id || ! is_string($request->query('code'))) {
            return $this->failed('A conexão com o Mercado Pago não foi concluída. Tente de novo.');
        }

        try {
            $credentials = $gateway->exchange('authorization_code', $request->query('code'), route('store-settings.online-payments.mercadopago.callback'));
            $who = $gateway->whoAmI((string) $credentials['access_token']);
        } catch (PaymentException $exception) {
            return $this->failed($exception->getMessage());
        }

        $this->save($store, PaymentProvider::MercadoPago, $credentials, $who['label']);

        return $this->connected('Conta do Mercado Pago conectada.');
    }

    /**
     * Without the platform app: the merchant pastes the production Access Token of their account.
     */
    public function mercadoPagoToken(Request $request, MercadoPagoGateway $gateway): RedirectResponse
    {
        $data = $request->validate(['access_token' => ['required', 'string', 'max:200', 'regex:/^APP_USR-[A-Za-z0-9-]+$/']], [
            'access_token.regex' => 'Use o Access Token de produção (começa com APP_USR-).',
        ]);

        try {
            $who = $gateway->whoAmI($data['access_token']);
        } catch (PaymentException $exception) {
            throw ValidationException::withMessages(['access_token' => 'O Mercado Pago recusou este token. Confira e tente de novo.']);
        }

        $this->save($this->currentStore(), PaymentProvider::MercadoPago, ['mode' => 'token', 'access_token' => $data['access_token'], 'user_id' => $who['id']], $who['label']);

        return $this->connected('Conta do Mercado Pago conectada.');
    }

    public function sicoob(Request $request, SicoobPixGateway $gateway): RedirectResponse
    {
        $store = $this->currentStore();
        $existing = $store->paymentAccounts()->where('provider', PaymentProvider::Sicoob)->first();

        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:120'],
            'pix_key' => ['required', 'string', 'max:77'],
            // .pfx/.p12, or the certificate (.pem/.crt/.cer) with its private key (.key/.pem).
            'certificate' => [$existing === null ? 'required' : 'nullable', 'file', 'max:64'],
            'private_key' => ['nullable', 'file', 'max:64'],
            'certificate_password' => ['nullable', 'string', 'max:200'],
        ], [
            'certificate.required' => 'Envie o certificado digital da sua conta Sicoob (.pfx, ou .pem/.crt com a chave .key).',
            'client_id.required' => 'Informe o Client ID gerado no portal do Sicoob.',
            'pix_key.required' => 'Informe a chave Pix que vai receber os pagamentos.',
        ]);

        $file = $request->file('certificate');
        $keyFile = $request->file('private_key');
        $password = (string) ($data['certificate_password'] ?? ($file instanceof UploadedFile ? '' : (string) $existing?->credential('certificate_password')));

        if ($file instanceof UploadedFile) {
            try {
                $certificate = ClientCertificate::toPkcs12(
                    (string) file_get_contents($file->getRealPath()),
                    $keyFile instanceof UploadedFile ? (string) file_get_contents($keyFile->getRealPath()) : null,
                    $password,
                );
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['certificate' => $exception->getMessage()]);
            }
        } else {
            $certificate = base64_decode((string) $existing?->credential('certificate'), true);
        }

        if (! is_string($certificate) || $certificate === '') {
            throw ValidationException::withMessages(['certificate' => 'Envie o certificado de novo.']);
        }

        $credentials = [
            'client_id' => trim($data['client_id']),
            'pix_key' => trim($data['pix_key']),
            'certificate' => base64_encode($certificate),
            'certificate_password' => (string) $password,
        ];

        $account = $this->save($store, PaymentProvider::Sicoob, $credentials, 'Chave Pix '.$credentials['pix_key']);

        try {
            $gateway->verify($account);
        } catch (PaymentException $exception) {
            $account->update(['is_active' => false, 'last_error' => mb_substr($exception->getMessage(), 0, 255)]);

            return $this->failed('O Sicoob recusou as credenciais: '.$exception->getMessage());
        }

        try {
            $gateway->registerWebhook($account, route('payments.webhook', $account->webhook_token));
        } catch (PaymentException $exception) {
            // Payments still work: the system checks open charges every minute.
            $account->update(['last_error' => mb_substr('Aviso de pagamento não registrado: '.$exception->getMessage(), 0, 255)]);
        }

        return $this->connected('Conta Sicoob conectada.');
    }

    /**
     * Pix straight to the store's key: no bank API, the store confirms each payment in the panel.
     */
    public function pixDirect(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pix_key_type' => ['required', Rule::in(['cpf', 'cnpj', 'phone', 'email', 'random'])],
            'pix_key' => ['required', 'string', 'max:77'],
            'merchant_name' => ['required', 'string', 'max:60'],
            'merchant_city' => ['required', 'string', 'max:60'],
        ], [
            'pix_key.required' => 'Informe a chave Pix que vai receber os pagamentos.',
            'merchant_name.required' => 'Informe o nome do recebedor, como aparece no banco.',
            'merchant_city.required' => 'Informe a cidade da loja.',
        ]);

        $key = self::pixKey($data['pix_key_type'], $data['pix_key']);

        if ($key === null) {
            throw ValidationException::withMessages(['pix_key' => 'Esta chave não confere com o tipo escolhido.']);
        }

        $credentials = [
            'pix_key_type' => $data['pix_key_type'],
            'pix_key' => $key,
            'merchant_name' => trim($data['merchant_name']),
            'merchant_city' => trim($data['merchant_city']),
        ];

        $this->save($this->currentStore(), PaymentProvider::PixDirect, $credentials, 'Chave Pix '.$key);

        return $this->connected('Pix direto ativado.');
    }

    /**
     * The key as the Pix network expects it, or null when it does not match its type.
     */
    private static function pixKey(string $type, string $input): ?string
    {
        $input = trim($input);
        $digits = preg_replace('/\D/', '', $input) ?? '';

        $key = match ($type) {
            'cpf' => strlen($digits) === 11 ? $digits : null,
            'cnpj' => strlen($digits) === 14 ? $digits : null,
            'phone' => match (true) {
                in_array(strlen($digits), [10, 11], true) => '+55'.$digits,
                str_starts_with($digits, '55') && in_array(strlen($digits), [12, 13], true) => '+'.$digits,
                default => null,
            },
            'email' => filter_var($input, FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($input) : null,
            'random' => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $input) === 1 ? strtolower($input) : null,
            default => null,
        };

        if (in_array($type, ['cpf', 'cnpj'], true) && $key !== null && BrazilianDocument::normalize($key) === null) {
            return null;
        }

        return $key;
    }

    public function btcpay(Request $request, BtcPayGateway $gateway): RedirectResponse
    {
        $store = $this->currentStore();
        $existing = $store->paymentAccounts()->where('provider', PaymentProvider::BtcPay)->first();

        $data = $request->validate([
            'server_url' => ['required', 'url:https', 'max:200'],
            'api_key' => [$existing === null ? 'required' : 'nullable', 'string', 'max:200'],
            'store_id' => ['required', 'string', 'max:120'],
        ], [
            'server_url.url' => 'Use o endereço completo do servidor, com https://.',
            'api_key.required' => 'Informe a chave de API do BTCPay.',
        ]);

        // Left blank on an update: keep the key already saved.
        $apiKey = ($data['api_key'] ?? '') !== '' ? $data['api_key'] : (string) $existing?->credential('api_key');
        $credentials = ['server_url' => rtrim($data['server_url'], '/'), 'api_key' => $apiKey, 'store_id' => $data['store_id']];
        $account = $this->save($store, PaymentProvider::BtcPay, $credentials, null);

        try {
            $name = $gateway->verify($account);
            $secret = $gateway->registerWebhook($account, route('payments.webhook', $account->webhook_token));
        } catch (PaymentException $exception) {
            $account->update(['is_active' => false, 'last_error' => mb_substr($exception->getMessage(), 0, 255)]);

            return $this->failed('O BTCPay recusou a conexão: '.$exception->getMessage());
        }

        $account->update(['account_label' => $name, 'credentials' => [...$credentials, 'webhook_secret' => $secret]]);

        return $this->connected('BTCPay Server conectado.');
    }

    public function update(Request $request, PaymentAccount $account): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($account);

        $data = $request->validate([
            'methods' => ['array'],
            'methods.*' => [Rule::in(array_map(fn (OnlinePaymentMethod $method) => $method->value, $account->provider->methods()))],
            'is_active' => ['boolean'],
        ]);

        $account->update([
            'methods' => array_values(array_unique($data['methods'] ?? [])),
            'is_active' => (bool) ($data['is_active'] ?? $account->is_active),
        ]);
        $this->takeOver($account);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pagamento online atualizado.']);

        return back();
    }

    public function destroy(PaymentAccount $account): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($account);
        $account->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => $account->provider->label().' desconectado.']);

        return back();
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function save(Store $store, PaymentProvider $provider, array $credentials, ?string $label): PaymentAccount
    {
        $account = $store->paymentAccounts()->where('provider', $provider)->first();

        if ($account instanceof PaymentAccount) {
            $account->update(['credentials' => $credentials, 'account_label' => $label, 'is_active' => true, 'last_error' => null]);
        } else {
            $account = $store->paymentAccounts()->create([
                'provider' => $provider,
                'methods' => array_map(fn (OnlinePaymentMethod $method) => $method->value, $provider->methods()),
                'credentials' => $credentials,
                'account_label' => $label,
                'webhook_token' => Str::random(48),
                'is_active' => true,
            ]);
        }

        $this->takeOver($account);

        return $account;
    }

    /**
     * Each method is taken by one account: the one just connected or changed wins.
     */
    private function takeOver(PaymentAccount $account): void
    {
        foreach ($account->store->paymentAccounts()->whereKeyNot($account->id)->get() as $other) {
            $remaining = array_values(array_diff($other->methods, $account->methods));

            if ($remaining !== $other->methods) {
                $other->update(['methods' => $remaining]);
            }
        }
    }

    private function connected(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('store-settings.online-payments.edit');
    }

    private function failed(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => mb_substr($message, 0, 250)]);

        return to_route('store-settings.online-payments.edit');
    }
}

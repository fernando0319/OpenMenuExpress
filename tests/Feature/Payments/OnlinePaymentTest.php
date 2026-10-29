<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\StoreRole;
use App\Events\OrderPlaced;
use App\Models\Category;
use App\Models\OnlinePayment;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Payments\OnlinePayments;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class OnlinePaymentTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create(['slug' => 'pizzaria-ze', 'name' => 'Pizzaria do Zé']);
        $this->product = Product::factory()->for(Category::factory()->for($this->store))->create([
            'price_cents' => 5000, 'track_stock' => true, 'stock_quantity' => 10,
        ]);
        $this->owner = User::factory()->create();
        $this->store->users()->attach($this->owner, ['role' => StoreRole::Owner->value]);
    }

    public function test_pix_by_mercado_pago_waits_for_the_payment_before_the_store_sees_the_order(): void
    {
        Event::fake([OrderPlaced::class]);
        $account = $this->account(PaymentProvider::MercadoPago, ['mode' => 'token', 'access_token' => 'APP_USR-123']);

        Http::fake([
            'https://api.mercadopago.com/v1/payments' => Http::response([
                'id' => 9001,
                'status' => 'pending',
                'point_of_interaction' => ['transaction_data' => ['qr_code' => '00020126580014br.gov.bcb.pix0136pix-code']],
            ], 201),
            'https://api.mercadopago.com/v1/payments/9001' => fn () => Http::response(['id' => 9001, 'external_reference' => 'mx-'.OnlinePayment::query()->value('id')]),
            'https://api.mercadopago.com/v1/payments/search*' => Http::sequence()
                ->push(['results' => [['id' => 9001, 'status' => 'pending']]])
                ->push(['results' => [['id' => 9001, 'status' => 'approved']]]),
        ]);

        $this->order('online_pix')->assertSessionHasNoErrors();

        $order = Order::query()->sole();
        $payment = OnlinePayment::query()->sole();
        $this->assertSame(OrderStatus::PendingPayment, $order->status);
        $this->assertSame('online_pix', $order->payment_method);
        $this->assertSame('9001', $payment->provider_ref);
        $this->assertSame(9, $this->product->fresh()?->stock_quantity);
        Event::assertNotDispatched(OrderPlaced::class);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://api.mercadopago.com/v1/payments'
            && $request['payment_method_id'] === 'pix'
            && $request['transaction_amount'] === 50
            && $request['external_reference'] === "mx-{$payment->id}"
            && str_contains((string) $request['notification_url'], "/api/webhooks/payments/{$account->webhook_token}")
            && $request->hasHeader('Authorization', 'Bearer APP_USR-123'));

        // The customer sees the QR code; the store does not see the order yet.
        $this->get("/pizzaria-ze/orders/{$order->public_token}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('payment.status', 'pending')
                ->where('payment.pixCode', '00020126580014br.gov.bcb.pix0136pix-code')
                ->where('payment.pixQrSvg', fn (string $svg) => str_contains($svg, '<svg')));
        $this->actingAs($this->owner)->get(route('orders.index'))->assertInertia(fn (Assert $page) => $page->has('orders.data', 0));

        // Mercado Pago notifies; the status is read again from its API.
        $this->postJson("/api/webhooks/payments/{$account->webhook_token}", ['type' => 'payment', 'data' => ['id' => '9001']])->assertOk();
        $this->postJson("/api/webhooks/payments/{$account->webhook_token}", ['type' => 'payment', 'data' => ['id' => '9001']])->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(OnlinePayment::PAID, $payment->fresh()?->status);
        $this->assertSame('Pix online (pago)', $order->paymentLabel());
        Event::assertDispatchedTimes(OrderPlaced::class, 1);
        $this->actingAs($this->owner)->get(route('orders.index'))->assertInertia(fn (Assert $page) => $page->has('orders.data', 1));
    }

    public function test_card_goes_to_the_mercado_pago_checkout(): void
    {
        $this->account(PaymentProvider::MercadoPago, ['mode' => 'token', 'access_token' => 'APP_USR-123']);
        Http::fake(['https://api.mercadopago.com/checkout/preferences' => Http::response(['id' => 'pref-1', 'init_point' => 'https://www.mercadopago.com.br/checkout/v1/redirect?pref_id=pref-1'], 201)]);

        $this->order('online_card')->assertSessionHasNoErrors();

        $order = Order::query()->sole();
        $this->get("/pizzaria-ze/orders/{$order->public_token}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('payment.checkoutUrl', 'https://www.mercadopago.com.br/checkout/v1/redirect?pref_id=pref-1')
                ->where('payment.pixCode', null));

        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/checkout/preferences')
            && $request['external_reference'] === 'mx-'.OnlinePayment::query()->value('id')
            && $request['back_urls']['success'] === route('menu.orders.show', ['pizzaria-ze', $order->public_token]));
    }

    public function test_an_unpaid_order_is_cancelled_when_the_time_runs_out(): void
    {
        $this->account(PaymentProvider::MercadoPago, ['mode' => 'token', 'access_token' => 'APP_USR-123']);
        Http::fake([
            'https://api.mercadopago.com/v1/payments' => Http::response(['id' => 1, 'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix']]], 201),
            'https://api.mercadopago.com/v1/payments/search*' => Http::response(['results' => [['id' => 1, 'status' => 'pending']]]),
        ]);

        $this->order('online_pix')->assertSessionHasNoErrors();

        $this->travelTo(CarbonImmutable::now()->addMinutes(40));
        $this->artisan('app:sync-online-payments')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, Order::query()->sole()->status);
        $this->assertSame(OnlinePayment::EXPIRED, OnlinePayment::query()->sole()->status);
        $this->assertSame(10, $this->product->fresh()?->stock_quantity);
    }

    public function test_cancelling_a_paid_order_refunds_it(): void
    {
        $this->account(PaymentProvider::MercadoPago, ['mode' => 'token', 'access_token' => 'APP_USR-123']);
        Http::fake([
            'https://api.mercadopago.com/v1/payments' => Http::response(['id' => 1, 'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix']]], 201),
            'https://api.mercadopago.com/v1/payments/search*' => Http::response(['results' => [['id' => 1, 'status' => 'approved']]]),
            'https://api.mercadopago.com/v1/payments/1/refunds' => Http::response(['id' => 77, 'status' => 'approved'], 201),
        ]);

        $this->order('online_pix')->assertSessionHasNoErrors();
        app(OnlinePayments::class)->sync(OnlinePayment::query()->sole());
        $order = Order::query()->sole();

        $this->actingAs($this->owner)->patch(route('orders.status.update', $order), ['status' => 'cancelled'])->assertSessionHasNoErrors();

        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/v1/payments/1/refunds'));
        $this->assertSame(OnlinePayment::REFUNDED, OnlinePayment::query()->sole()->status);
    }

    public function test_pix_by_sicoob_uses_the_bacen_api_and_its_notification(): void
    {
        $account = $this->account(PaymentProvider::Sicoob, [
            'client_id' => 'client-1', 'pix_key' => 'pix@pizzaria.com', 'certificate' => base64_encode('pfx-bytes'), 'certificate_password' => 'x',
        ]);

        Http::fake([
            'https://auth.sicoob.com.br/*' => Http::response(['access_token' => 'tok', 'expires_in' => 300]),
            'https://api.sicoob.com.br/pix/api/v2/cob/*' => function (HttpRequest $request) {
                return $request->method() === 'PUT'
                    ? Http::response(['txid' => 'x', 'status' => 'ATIVA', 'brcode' => '00020126-sicoob'], 201)
                    : Http::response(['status' => 'CONCLUIDA', 'pix' => [['endToEndId' => 'E123']]]);
            },
        ]);

        $this->order('online_pix')->assertSessionHasNoErrors();

        $payment = OnlinePayment::query()->sole();
        $this->assertSame('00020126-sicoob', $payment->pix_code);
        $this->assertMatchesRegularExpression('/^MX'.str_pad((string) $payment->id, 10, '0', STR_PAD_LEFT).'[A-Za-z0-9]{20}$/', (string) $payment->provider_ref);
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'PUT'
            && $request['valor']['original'] === '50.00'
            && $request['chave'] === 'pix@pizzaria.com'
            && $request->hasHeader('Authorization', 'Bearer tok'));

        // The bank posts to the registered URL + "/pix".
        $this->postJson("/api/webhooks/payments/{$account->webhook_token}/pix", ['pix' => [['txid' => $payment->provider_ref, 'endToEndId' => 'E123']]])->assertOk();

        $this->assertSame(OnlinePayment::PAID, $payment->fresh()?->status);
        $this->assertSame(OrderStatus::Pending, Order::query()->sole()->status);
    }

    public function test_bitcoin_by_btcpay_trusts_only_signed_notifications(): void
    {
        $account = $this->account(PaymentProvider::BtcPay, [
            'server_url' => 'https://btcpay.example.com', 'api_key' => 'key', 'store_id' => 'store-1', 'webhook_secret' => 'segredo',
        ]);

        Http::fake([
            'https://btcpay.example.com/api/v1/stores/store-1/invoices' => Http::response(['id' => 'inv-1', 'checkoutLink' => 'https://btcpay.example.com/i/inv-1', 'status' => 'New']),
            'https://btcpay.example.com/api/v1/stores/store-1/invoices/inv-1' => Http::response(['id' => 'inv-1', 'status' => 'Settled']),
        ]);

        $this->order('online_bitcoin')->assertSessionHasNoErrors();
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/invoices') && $request['currency'] === 'BRL' && $request['amount'] === '50.00');

        $body = (string) json_encode(['type' => 'InvoiceSettled', 'invoiceId' => 'inv-1']);

        $this->call('POST', "/api/webhooks/payments/{$account->webhook_token}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_BTCPAY_SIG' => 'sha256=errado'], $body)->assertOk();
        $this->assertSame(OnlinePayment::PENDING, OnlinePayment::query()->sole()->status);

        $signature = 'sha256='.hash_hmac('sha256', $body, 'segredo');
        $this->call('POST', "/api/webhooks/payments/{$account->webhook_token}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_BTCPAY_SIG' => $signature], $body)->assertOk();
        $this->assertSame(OnlinePayment::PAID, OnlinePayment::query()->sole()->status);
    }

    public function test_only_connected_methods_are_offered_and_a_provider_failure_lets_the_customer_choose_again(): void
    {
        Event::fake([OrderPlaced::class]);
        $this->order('online_pix')->assertSessionHasErrors('payment_method');

        $this->account(PaymentProvider::MercadoPago, ['mode' => 'token', 'access_token' => 'APP_USR-123']);
        $this->get('/pizzaria-ze')->assertInertia(fn (Assert $page) => $page
            ->where('onlinePaymentMethods.0.value', 'online_pix')
            ->where('onlinePaymentMethods.1.value', 'online_card'));

        Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response(['message' => 'internal'], 500)]);

        $this->order('online_pix')->assertSessionHasErrors(['payment_method' => 'Não foi possível gerar o pagamento online agora. Escolha outra forma de pagamento.']);
        $this->assertSame(OrderStatus::Cancelled, Order::query()->sole()->status);
        $this->assertSame(10, $this->product->fresh()?->stock_quantity);
        Event::assertNotDispatched(OrderPlaced::class);
    }

    public function test_the_merchant_connects_accounts_and_each_method_has_one_owner(): void
    {
        Http::fake([
            'https://api.mercadopago.com/users/me' => Http::response(['id' => 55, 'email' => 'loja@exemplo.com']),
            'https://btcpay.example.com/api/v1/stores/store-1' => Http::response(['id' => 'store-1', 'name' => 'Pizzaria BTC']),
            'https://btcpay.example.com/api/v1/stores/store-1/webhooks' => Http::response(['id' => 'wh-1']),
        ]);

        $this->actingAs($this->owner)->post(route('store-settings.online-payments.mercadopago.token'), ['access_token' => 'TEST-123'])
            ->assertSessionHasErrors('access_token');
        $this->actingAs($this->owner)->post(route('store-settings.online-payments.mercadopago.token'), ['access_token' => 'APP_USR-123-abc'])
            ->assertSessionHasNoErrors();

        $mp = PaymentAccount::query()->sole();
        $this->assertSame('loja@exemplo.com', $mp->account_label);
        $this->assertSame(['online_pix', 'online_card'], $mp->methods);
        $this->assertSame('APP_USR-123-abc', $mp->credential('access_token'));

        $this->actingAs($this->owner)->post(route('store-settings.online-payments.btcpay'), [
            'server_url' => 'https://btcpay.example.com/', 'api_key' => 'key', 'store_id' => 'store-1',
        ])->assertSessionHasNoErrors();

        $btc = PaymentAccount::query()->where('provider', 'btcpay')->sole();
        $this->assertSame('Pizzaria BTC', $btc->account_label);
        $this->assertNotEmpty($btc->credential('webhook_secret'));

        // Pix only through Mercado Pago, card turned off.
        $this->actingAs($this->owner)->put(route('store-settings.online-payments.update', $mp), ['methods' => ['online_pix'], 'is_active' => true])->assertSessionHasNoErrors();
        $this->assertSame(['online_pix'], $mp->fresh()?->methods);

        $this->actingAs($this->owner)->get(route('store-settings.online-payments.edit'))
            ->assertInertia(fn (Assert $page) => $page->component('store-settings/OnlinePayments')->where('providers.0.account.label', 'loja@exemplo.com'));

        $other = Store::factory()->create();
        $foreign = $other->paymentAccounts()->create(['provider' => 'btcpay', 'methods' => [], 'credentials' => [], 'webhook_token' => str_repeat('a', 48)]);
        $this->actingAs($this->owner)->delete(route('store-settings.online-payments.destroy', $foreign))->assertNotFound();
    }

    public function test_sicoob_takes_the_certificate_as_pfx_or_as_pem_with_its_key(): void
    {
        Http::fake(['https://auth.sicoob.com.br/*' => Http::response(['access_token' => 'tok', 'expires_in' => 300])]);
        $fixture = fn (string $name) => UploadedFile::fake()->createWithContent($name, (string) file_get_contents(base_path("tests/fixtures/certificates/{$name}")));

        $this->actingAs($this->owner)->post(route('store-settings.online-payments.sicoob'), [
            'client_id' => 'client-1', 'pix_key' => '12345678000190', 'certificate' => $fixture('cert.pem'),
        ])->assertSessionHasErrors(['certificate' => 'Envie também a chave privada (.key) do certificado.']);

        $this->actingAs($this->owner)->post(route('store-settings.online-payments.sicoob'), [
            'client_id' => 'client-1', 'pix_key' => '12345678000190',
            'certificate' => $fixture('cert.pem'), 'private_key' => $fixture('key-encrypted.pem'), 'certificate_password' => 'Senha123',
        ])->assertSessionHasNoErrors();

        $account = PaymentAccount::query()->where('provider', 'sicoob')->sole();
        $parsed = [];
        $this->assertTrue(openssl_pkcs12_read((string) base64_decode((string) $account->credential('certificate')), $parsed, (string) $account->credential('certificate_password')));

        $this->actingAs($this->owner)->post(route('store-settings.online-payments.sicoob'), [
            'client_id' => 'client-1', 'pix_key' => '12345678000190', 'certificate' => $fixture('modern.pfx'), 'certificate_password' => 'errada',
        ])->assertSessionHasErrors('certificate');
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function account(PaymentProvider $provider, array $credentials): PaymentAccount
    {
        return $this->store->paymentAccounts()->create([
            'provider' => $provider,
            'methods' => array_map(fn ($method) => $method->value, $provider->methods()),
            'credentials' => $credentials,
            'webhook_token' => str_repeat('t', 40).$provider->value,
            'is_active' => true,
        ]);
    }

    /**
     * @return TestResponse<Response>
     */
    private function order(string $method): TestResponse
    {
        return $this->post('/pizzaria-ze/orders', [
            'fulfillment' => 'pickup',
            'customer' => ['name' => 'Maria', 'phone' => '(11) 99999-8888'],
            'payment_method' => $method,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ]);
    }
}

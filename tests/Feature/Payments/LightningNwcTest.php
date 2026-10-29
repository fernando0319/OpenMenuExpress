<?php

namespace Tests\Feature\Payments;

use App\Enums\Asset;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\StoreRole;
use App\Events\OrderPlaced;
use App\Models\Category;
use App\Models\LedgerEntry;
use App\Models\OnlinePayment;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Payments\Nwc\NwcClient;
use App\Payments\Nwc\NwcCodec;
use App\Payments\Nwc\NwcConnection;
use App\Payments\PaymentException;
use App\Services\Quotes\BtcQuote;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LightningNwcTest extends TestCase
{
    use RefreshDatabase;

    private const CONNECTION = 'nostr+walletconnect://'
        .'b889ff5b1513b641e2a139f661a661364979c5beee91842f8f0ef42ab558e9d4'
        .'?relay=wss://relay.carteira.test&secret=71a8c14c1407c113601079c4302dab36460f0ccd0ad506f1f2dc73b5100e4f3c&lud16=loja@carteira.test';

    private Store $store;

    private Product $product;

    private User $owner;

    private FakeWallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        // R$ 500.000 per bitcoin: two sources agree, one is broken and ignored, one is off.
        Http::fake([
            'api.coinbase.com/*' => Http::response(['data' => ['amount' => '499000.00']]),
            'api.binance.com/*' => Http::response(['price' => '500000.00']),
            'api.mercadobitcoin.net/*' => Http::response([['last' => '0.01']]),
            'api.coingecko.com/*' => Http::response(['bitcoin' => ['brl' => 501000]]),
        ]);

        $this->wallet = new FakeWallet;
        $this->app->instance(NwcClient::class, $this->wallet);

        $this->store = Store::factory()->create(['slug' => 'pizzaria-ze', 'name' => 'Pizzaria do Zé']);
        $this->product = Product::factory()->for(Category::factory()->for($this->store))->create(['price_cents' => 5000, 'track_stock' => true, 'stock_quantity' => 10]);
        $this->owner = User::factory()->create();
        $this->store->users()->attach($this->owner, ['role' => StoreRole::Owner->value]);
    }

    public function test_the_merchant_connects_a_wallet_that_can_receive(): void
    {
        $this->wallet->methods = ['make_invoice', 'lookup_invoice', 'get_info'];

        $this->actingAs($this->owner)
            ->post(route('store-settings.online-payments.nwc'), ['connection' => self::CONNECTION])
            ->assertSessionHasNoErrors();

        $account = PaymentAccount::query()->sole();
        $this->assertSame(PaymentProvider::Nwc, $account->provider);
        $this->assertSame(['online_lightning'], $account->methods);
        $this->assertSame('loja@carteira.test', $account->account_label);
        $this->assertSame(NwcCodec::NIP44, $account->credential('encryption'));
        $this->assertNull($account->last_error);

        // The secret is never sent back to the screen.
        $this->actingAs($this->owner)->get(route('store-settings.online-payments.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('providers.3.provider', 'nwc')->where('providers.3.account.label', 'loja@carteira.test'))
            ->assertDontSee('71a8c14c1407c113');
    }

    public function test_a_wallet_that_cannot_receive_is_refused_and_one_that_can_spend_is_flagged(): void
    {
        $this->wallet->methods = ['pay_invoice', 'get_balance'];
        $this->actingAs($this->owner)->post(route('store-settings.online-payments.nwc'), ['connection' => self::CONNECTION])->assertSessionHasErrors('connection');

        $this->actingAs($this->owner)->post(route('store-settings.online-payments.nwc'), ['connection' => 'lnurl1abc'])->assertSessionHasErrors('connection');
        $this->assertSame(0, PaymentAccount::query()->count());

        $this->wallet->methods = ['pay_invoice', 'make_invoice', 'lookup_invoice'];
        $this->actingAs($this->owner)->post(route('store-settings.online-payments.nwc'), ['connection' => self::CONNECTION])->assertSessionHasNoErrors();
        $this->assertStringContainsString('também permite pagar', (string) PaymentAccount::query()->sole()->last_error);
    }

    public function test_the_customer_pays_the_sats_of_the_locked_rate_straight_to_the_store_wallet(): void
    {
        Event::fake([OrderPlaced::class]);
        $this->account();

        $this->post('/pizzaria-ze/orders', [
            'fulfillment' => 'pickup',
            'customer' => ['name' => 'Maria', 'phone' => '(11) 99999-8888'],
            'payment_method' => 'online_lightning',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $order = Order::query()->sole();
        $payment = OnlinePayment::query()->sole();

        // Median of 499.000, 500.000 and 501.000: R$ 50,00 is 10.000 sats.
        $this->assertSame(['make_invoice', ['amount' => 10_000_000, 'description' => 'Pedido nº '.$order->number.' - Pizzaria do Zé']], [$this->wallet->calls[0][0], array_intersect_key($this->wallet->calls[0][1], ['amount' => 1, 'description' => 1])]);
        $this->assertSame(NwcCodec::NIP44, $this->wallet->calls[0][2]);
        $this->assertSame(Asset::Btc, $payment->asset);
        $this->assertSame(10_000, $payment->asset_units);
        $this->assertSame('500000.00', $payment->rate);
        $this->assertSame('lnbc100u1fake', $payment->payment_request);
        $this->assertSame(OrderStatus::PendingPayment, $order->status);

        $this->get("/pizzaria-ze/orders/{$order->public_token}")->assertInertia(fn (Assert $page) => $page
            ->where('payment.paymentRequest', 'lnbc100u1fake')
            ->where('payment.assetAmount', 10_000)
            ->where('payment.rate', '500000.00')
            ->where('payment.paymentRequestQrSvg', fn (string $svg) => str_contains($svg, '<svg')));

        // The page asks; the wallet says it was paid.
        $this->wallet->settled = true;
        $this->travel(10)->seconds();
        $this->getJson("/pizzaria-ze/orders/{$order->public_token}/payment")->assertJsonPath('payment.status', 'paid');

        $this->assertSame(OrderStatus::Pending, $order->fresh()?->status);
        Event::assertDispatchedTimes(OrderPlaced::class, 1);

        // The ledger keeps the satoshis of the invoice and its rate.
        $entry = LedgerEntry::query()->sole();
        $this->assertSame(Asset::Btc, $entry->asset);
        $this->assertSame(10_000, $entry->units);
        $this->assertSame(5000, $entry->brl_cents);
        $this->assertSame('online_lightning', $entry->method);
    }

    public function test_an_unpaid_invoice_expires_and_a_quote_or_wallet_failure_lets_the_customer_choose_again(): void
    {
        $this->account();

        $this->post('/pizzaria-ze/orders', [
            'fulfillment' => 'pickup',
            'customer' => ['name' => 'Maria', 'phone' => '(11) 99999-8888'],
            'payment_method' => 'online_lightning',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $this->travelTo(CarbonImmutable::now()->addMinutes(40));
        $this->artisan('app:sync-online-payments')->assertSuccessful();
        $this->assertSame(OnlinePayment::EXPIRED, OnlinePayment::query()->sole()->status);
        $this->assertSame(OrderStatus::Cancelled, Order::query()->sole()->status);

        $this->wallet->fail = true;
        $this->post('/pizzaria-ze/orders', [
            'fulfillment' => 'pickup',
            'customer' => ['name' => 'Maria', 'phone' => '(11) 99999-8888'],
            'payment_method' => 'online_lightning',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('payment_method');
    }

    public function test_the_price_is_the_median_of_the_sources_and_satoshis_are_rounded_up(): void
    {
        $this->assertSame(50_000_000, app(BtcQuote::class)->centsPerBtc());
        $this->assertSame(10_000, BtcQuote::satsFor(5000, 50_000_000));
        $this->assertSame(5, BtcQuote::satsFor(3, 70_000_000)); // 4,28 sats → 5: the store never gets less
    }

    private function account(): PaymentAccount
    {
        return $this->store->paymentAccounts()->create([
            'provider' => PaymentProvider::Nwc,
            'methods' => ['online_lightning'],
            'credentials' => ['connection' => self::CONNECTION, 'encryption' => NwcCodec::NIP44],
            'webhook_token' => str_repeat('t', 48),
            'is_active' => true,
        ]);
    }
}

/**
 * A wallet behind Nostr Wallet Connect, answering as Alby Hub would.
 */
class FakeWallet implements NwcClient
{
    /** @var list<string> */
    public array $methods = ['make_invoice', 'lookup_invoice'];

    public bool $settled = false;

    public bool $fail = false;

    /** @var list<array{0: string, 1: array<string, mixed>, 2: string}> */
    public array $calls = [];

    public function info(NwcConnection $connection): array
    {
        return ['methods' => $this->methods, 'encryptions' => ['nip44_v2', 'nip04']];
    }

    public function request(NwcConnection $connection, string $method, array $params, string $encryption): array
    {
        $this->calls[] = [$method, $params, $encryption];

        if ($this->fail) {
            throw new PaymentException('A carteira não respondeu a tempo.');
        }

        return match ($method) {
            'make_invoice' => ['type' => 'incoming', 'invoice' => 'lnbc100u1fake', 'payment_hash' => str_repeat('ab', 32), 'amount' => $params['amount']],
            'lookup_invoice' => ['type' => 'incoming', 'payment_hash' => $params['payment_hash'], 'settled_at' => $this->settled ? time() : null],
            default => [],
        };
    }
}

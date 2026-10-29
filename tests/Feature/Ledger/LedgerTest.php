<?php

namespace Tests\Feature\Ledger;

use App\Enums\Asset;
use App\Enums\PaymentProvider;
use App\Enums\StoreRole;
use App\Models\Category;
use App\Models\LedgerEntry;
use App\Models\OnlinePayment;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Payments\OnlinePayments;
use App\Services\Cash\CashRegister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->store = Store::factory()->create(['slug' => 'pizzaria-ze', 'name' => 'Pizzaria do Zé']);
        $this->product = Product::factory()->for(Category::factory()->for($this->store))->create(['price_cents' => 5000]);
        $this->owner = User::factory()->create();
        $this->store->users()->attach($this->owner, ['role' => StoreRole::Owner->value]);
    }

    public function test_cash_register_sales_and_refunds_are_recorded_but_not_moves_inside_the_store(): void
    {
        $this->actingAs($this->owner)->post(route('cash.open'), ['opening' => '100'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('pos.orders.store'), [
            'fulfillment' => 'pickup',
            'complete_now' => true,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => '20'], ['method' => 'pix', 'amount' => '30']],
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('cash.movements.store'), ['type' => 'supply', 'amount' => '20'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('cash.movements.store'), ['type' => 'withdrawal', 'amount' => '50'])->assertSessionHasNoErrors();

        $entries = LedgerEntry::query()->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame(['cash', 'pix'], $entries->pluck('method')->all());
        $this->assertSame([2000, 3000], $entries->pluck('brl_cents')->all());
        $this->assertTrue($entries->every(fn (LedgerEntry $entry) => $entry->asset === Asset::Brl && $entry->kind === LedgerEntry::IN));
        $this->assertSame($entries->first()?->order_id, Order::query()->sole()->id);

        app(CashRegister::class)->refund(Order::query()->sole(), $this->owner);

        $this->assertSame(0, (int) LedgerEntry::query()->sum('brl_cents'));
        $this->assertSame(2, LedgerEntry::query()->where('kind', LedgerEntry::OUT)->count());
    }

    public function test_online_payments_come_in_when_paid_and_go_out_when_refunded(): void
    {
        $this->account(PaymentProvider::PixDirect, ['pix_key' => '11222333000181', 'merchant_name' => 'Zé', 'merchant_city' => 'Lapa']);
        $this->order('online_pix');
        $order = Order::query()->sole();

        $this->assertSame(0, LedgerEntry::query()->count());

        $this->actingAs($this->owner)->post(route('orders.payment.confirm', $order))->assertSessionHasNoErrors();

        $entry = LedgerEntry::query()->sole();
        $this->assertSame(Asset::Brl, $entry->asset);
        $this->assertSame(5000, $entry->units);
        $this->assertSame(5000, $entry->brl_cents);
        $this->assertSame('online_pix', $entry->method);
        $this->assertSame('Pix direto', $entry->description);

        // A refund through the provider (as Mercado Pago and Sicoob do) takes it out again.
        app(OnlinePayments::class)->apply(OnlinePayment::query()->sole(), OnlinePayment::REFUNDED);

        $this->assertSame(0, (int) LedgerEntry::query()->sum('brl_cents'));
        $this->assertSame(-5000, LedgerEntry::query()->where('kind', LedgerEntry::OUT)->sole()->units);
    }

    public function test_bitcoin_keeps_the_satoshis_received_and_the_rate_of_the_moment(): void
    {
        $this->account(PaymentProvider::BtcPay, ['server_url' => 'https://btcpay.test', 'api_key' => 'key', 'store_id' => 'S1']);
        Http::fake([
            'https://btcpay.test/api/v1/stores/S1/invoices' => Http::response(['id' => 'INV1', 'checkoutLink' => 'https://btcpay.test/i/INV1']),
            'https://btcpay.test/api/v1/stores/S1/invoices/INV1/payment-methods' => Http::sequence()
                ->push(['message' => 'down'], 503)
                ->push([
                    ['paymentMethodId' => 'BTC-CHAIN', 'paymentMethodPaid' => '0.00004000'],
                    ['paymentMethodId' => 'BTC-LN', 'paymentMethodPaid' => '0.00006'],
                ]),
        ]);

        $this->order('online_bitcoin');
        app(OnlinePayments::class)->apply(OnlinePayment::query()->sole(), OnlinePayment::PAID);

        // BTCPay did not answer: the value in reais is kept, the quantity waits.
        $entry = LedgerEntry::query()->sole();
        $this->assertSame(Asset::Btc, $entry->asset);
        $this->assertNull($entry->units);
        $this->assertSame(5000, $entry->brl_cents);

        $this->artisan('app:rebuild-ledger')->assertSuccessful();

        $entry->refresh();
        $this->assertSame(10000, $entry->units);
        $this->assertSame('500000.00', $entry->rate); // R$ 50,00 for 10.000 sats
        $this->assertSame(1, LedgerEntry::query()->count());
    }

    public function test_the_history_can_be_rebuilt_without_duplicates(): void
    {
        $this->actingAs($this->owner)->post(route('cash.open'), ['opening' => '0']);
        $this->actingAs($this->owner)->post(route('pos.orders.store'), [
            'fulfillment' => 'pickup',
            'complete_now' => true,
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
            'payments' => [['method' => 'debit_card', 'amount' => '100']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, LedgerEntry::query()->count());

        // An install updated from before the ledger existed.
        LedgerEntry::query()->delete();
        $this->artisan('app:rebuild-ledger')->expectsOutputToContain('1 lançamento(s) novo(s)')->assertSuccessful();
        $this->artisan('app:rebuild-ledger')->expectsOutputToContain('0 lançamento(s) novo(s)')->assertSuccessful();

        $this->assertSame(10000, LedgerEntry::query()->sole()->brl_cents);
    }

    public function test_the_owner_sees_and_exports_the_month_and_the_team_does_not(): void
    {
        $this->actingAs($this->owner)->post(route('cash.open'), ['opening' => '0']);
        $this->actingAs($this->owner)->post(route('pos.orders.store'), [
            'fulfillment' => 'pickup',
            'complete_now' => true,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => '50']],
        ])->assertSessionHasNoErrors();
        app(CashRegister::class)->refund(Order::query()->sole(), $this->owner);

        $this->actingAs($this->owner)->get(route('ledger.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('ledger/Index')
                ->where('totalsByAsset.0.asset', 'BRL')
                ->where('totalsByAsset.0.brlCents', 0)
                ->where('totalsByMethod.0.method', 'Dinheiro')
                ->where('totalsByMethod.0.receivedCents', 5000)
                ->where('totalsByMethod.0.returnedCents', 5000)
                ->has('entries.data', 2));

        // Another month is empty.
        $this->actingAs($this->owner)->get(route('ledger.index', ['month' => '2020-01']))
            ->assertInertia(fn (Assert $page) => $page->where('month', '2020-01')->has('entries.data', 0));

        $csv = $this->actingAs($this->owner)->get(route('ledger.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Entrada;Dinheiro;BRL;50,00;;50,00', $csv);
        $this->assertStringContainsString('Saída;Dinheiro;BRL;-50,00;;-50,00', $csv);

        $attendant = User::factory()->create();
        $this->store->users()->attach($attendant, ['role' => StoreRole::Attendant->value]);
        $this->actingAs($attendant)->get(route('ledger.index'))->assertForbidden();
        $this->actingAs($attendant)->get(route('ledger.export'))->assertForbidden();
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

    private function order(string $method): void
    {
        $this->post('/pizzaria-ze/orders', [
            'fulfillment' => 'pickup',
            'customer' => ['name' => 'Maria', 'phone' => '(11) 99999-8888'],
            'payment_method' => $method,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();
    }
}

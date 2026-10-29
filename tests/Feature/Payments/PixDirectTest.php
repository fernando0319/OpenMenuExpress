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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class PixDirectTest extends TestCase
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
        $this->product = Product::factory()->for(Category::factory()->for($this->store))->create([
            'price_cents' => 5000, 'track_stock' => true, 'stock_quantity' => 10,
        ]);
        $this->owner = User::factory()->create();
        $this->store->users()->attach($this->owner, ['role' => StoreRole::Owner->value]);
    }

    public function test_the_merchant_turns_on_pix_with_just_the_key(): void
    {
        $this->actingAs($this->owner)
            ->post(route('store-settings.online-payments.pix-direct'), [
                'pix_key_type' => 'cnpj',
                'pix_key' => '11.222.333/0001-81',
                'merchant_name' => 'Pizzaria do Zé',
                'merchant_city' => 'Palmeira',
            ])
            ->assertSessionHasNoErrors();

        $account = PaymentAccount::query()->sole();
        $this->assertSame(PaymentProvider::PixDirect, $account->provider);
        $this->assertSame('11222333000181', $account->credential('pix_key'));
        $this->assertSame(['online_pix'], $account->methods);

        // A key that does not match its type is refused.
        $this->actingAs($this->owner)
            ->post(route('store-settings.online-payments.pix-direct'), [
                'pix_key_type' => 'cpf', 'pix_key' => '111.111.111-11', 'merchant_name' => 'Zé', 'merchant_city' => 'Lapa',
            ])
            ->assertSessionHasErrors('pix_key');

        $this->actingAs($this->owner)
            ->post(route('store-settings.online-payments.pix-direct'), [
                'pix_key_type' => 'phone', 'pix_key' => '(42) 99999-0000', 'merchant_name' => 'Zé', 'merchant_city' => 'Lapa',
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame('+5542999990000', $account->fresh()?->credential('pix_key'));
    }

    public function test_the_order_waits_for_the_store_to_confirm_the_pix(): void
    {
        Event::fake([OrderPlaced::class]);
        $this->account();

        $this->order()->assertSessionHasNoErrors();

        $order = Order::query()->sole();
        $payment = OnlinePayment::query()->sole();
        $this->assertSame(OrderStatus::PendingPayment, $order->status);
        $this->assertSame(OnlinePayment::PENDING, $payment->status);
        $this->assertSame("MX{$payment->id}", $payment->provider_ref);
        $this->assertStringContainsString('br.gov.bcb.pix', (string) $payment->pix_code);
        $this->assertStringContainsString('011411222333000181', (string) $payment->pix_code);
        $this->assertStringContainsString('540550.00', (string) $payment->pix_code);
        $this->assertStringContainsString('05'.str_pad((string) strlen("MX{$payment->id}"), 2, '0', STR_PAD_LEFT)."MX{$payment->id}", (string) $payment->pix_code);
        Event::assertNotDispatched(OrderPlaced::class);

        // The customer sees the QR code and knows the store confirms it.
        $this->get("/pizzaria-ze/orders/{$order->public_token}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('payment.status', 'pending')
                ->where('payment.confirmedByStore', true)
                ->where('payment.pixQrSvg', fn (string $svg) => str_contains($svg, '<svg')));

        // Unlike other online payments, the store sees it at once, rings, and must confirm.
        $this->actingAs($this->owner)->get(route('orders.index'))->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)
            ->where('orders.data.0.awaitingConfirmation', true)
            ->where('orders.data.0.nextAction', null));
        $this->actingAs($this->owner)->getJson(route('orders.pending'))->assertJson(['count' => 1]);
        $this->actingAs($this->owner)->get(route('orders.show', $order))->assertInertia(fn (Assert $page) => $page
            ->where('order.paymentToConfirm.amountCents', 5000)
            ->where('order.paymentToConfirm.reference', "MX{$payment->id}")
            ->where('order.paymentToConfirm.canConfirm', true));

        $this->actingAs($this->owner)->post(route('orders.payment.confirm', $order))->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(OnlinePayment::PAID, $payment->fresh()?->status);
        $this->assertStringContainsString($this->owner->name, (string) $payment->fresh()?->note);
        Event::assertDispatchedTimes(OrderPlaced::class, 1);

        // Confirming twice does nothing more.
        $this->actingAs($this->owner)->post(route('orders.payment.confirm', $order));
        Event::assertDispatchedTimes(OrderPlaced::class, 1);
        $this->actingAs($this->owner)->get(route('orders.show', $order))->assertInertia(fn (Assert $page) => $page->where('order.paymentToConfirm', null));
    }

    public function test_only_who_handles_money_confirms(): void
    {
        $this->account();
        $this->order()->assertSessionHasNoErrors();
        $order = Order::query()->sole();

        $waiter = User::factory()->create();
        $this->store->users()->attach($waiter, ['role' => StoreRole::Waiter->value]);

        $this->actingAs($waiter)->post(route('orders.payment.confirm', $order))->assertForbidden();
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()?->status);
    }

    public function test_an_unconfirmed_pix_is_cancelled_when_the_time_runs_out(): void
    {
        $this->account();
        $this->order()->assertSessionHasNoErrors();

        $this->travelTo(CarbonImmutable::now()->addMinutes(40));
        $this->artisan('app:sync-online-payments')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, Order::query()->sole()->status);
        $this->assertSame(OnlinePayment::EXPIRED, OnlinePayment::query()->sole()->status);
        $this->assertSame(10, $this->product->fresh()?->stock_quantity);
        $this->actingAs($this->owner)->getJson(route('orders.pending'))->assertJson(['count' => 0]);
    }

    private function account(): PaymentAccount
    {
        return $this->store->paymentAccounts()->create([
            'provider' => PaymentProvider::PixDirect,
            'methods' => ['online_pix'],
            'credentials' => ['pix_key_type' => 'cnpj', 'pix_key' => '11222333000181', 'merchant_name' => 'Pizzaria do Zé', 'merchant_city' => 'Palmeira'],
            'webhook_token' => str_repeat('t', 48),
            'is_active' => true,
        ]);
    }

    /**
     * @return TestResponse<Response>
     */
    private function order(): TestResponse
    {
        return $this->post('/pizzaria-ze/orders', [
            'fulfillment' => 'pickup',
            'customer' => ['name' => 'Maria', 'phone' => '(11) 99999-8888'],
            'payment_method' => 'online_pix',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ]);
    }
}

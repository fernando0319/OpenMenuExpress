<?php

namespace Tests\Feature\Marketing;

use App\Enums\CouponType;
use App\Enums\Fulfillment;
use App\Enums\StoreRole;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class CouponTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create(['slug' => 'pizzaria-ze', 'timezone' => 'America/Sao_Paulo']);
        $this->product = Product::factory()->for(Category::factory()->for($this->store))->create(['price_cents' => 5000]);
        $this->owner = User::factory()->create();
        $this->store->users()->attach($this->owner, ['role' => StoreRole::Owner->value]);
    }

    public function test_percent_coupon_respects_the_cap_and_is_case_insensitive(): void
    {
        $this->coupon(['code' => 'PROMO20', 'type' => CouponType::Percent, 'percent' => 20, 'max_discount_cents' => 1500]);

        $this->order(['coupon_code' => ' promo20 '], quantity: 2)->assertSessionHasNoErrors();

        $order = Order::query()->sole();
        $this->assertSame(1500, $order->discount_cents); // 20% de 100,00 = 20,00, limitado a 15,00
        $this->assertSame(8500, $order->total_cents);
        $this->assertSame('PROMO20', $order->coupon_code);
    }

    public function test_fixed_coupon_never_exceeds_the_products(): void
    {
        $this->coupon(['code' => 'DEZ', 'type' => CouponType::Fixed, 'amount_cents' => 9000]);

        $this->order(['coupon_code' => 'DEZ'])->assertSessionHasNoErrors();

        $this->assertSame(0, Order::query()->sole()->total_cents);
    }

    public function test_free_delivery_removes_the_fee_and_needs_delivery(): void
    {
        $zone = DeliveryZone::query()->create(['store_id' => $this->store->id, 'name' => 'Centro', 'fee_cents' => 800]);
        $this->coupon(['code' => 'FRETE', 'type' => CouponType::FreeDelivery]);

        $this->order(['coupon_code' => 'FRETE'])->assertSessionHasErrors('coupon');

        $this->order([
            'coupon_code' => 'FRETE',
            'fulfillment' => 'delivery',
            'delivery_zone_id' => $zone->id,
            'address' => ['street' => 'Rua A', 'number' => '1'],
        ])->assertSessionHasNoErrors();

        $order = Order::query()->sole();
        $this->assertSame(800, $order->discount_cents);
        $this->assertSame(5000, $order->total_cents);
    }

    public function test_rules_reject_with_a_clear_reason(): void
    {
        $this->coupon(['code' => 'MINIMO', 'type' => CouponType::Fixed, 'amount_cents' => 500, 'min_subtotal_cents' => 6000]);
        $this->coupon(['code' => 'VENCIDO', 'type' => CouponType::Fixed, 'amount_cents' => 500, 'ends_at' => now()->subMinute()]);
        $this->coupon(['code' => 'FUTURO', 'type' => CouponType::Fixed, 'amount_cents' => 500, 'starts_at' => now()->addDay()]);
        $this->coupon(['code' => 'ENTREGA', 'type' => CouponType::Fixed, 'amount_cents' => 500, 'fulfillment' => Fulfillment::Delivery]);
        $this->coupon(['code' => 'DESLIGADO', 'type' => CouponType::Fixed, 'amount_cents' => 500, 'is_active' => false]);

        $this->order(['coupon_code' => 'MINIMO'])->assertSessionHasErrors(['coupon' => 'Este cupom vale para pedidos a partir de R$ 60,00.']);
        $this->order(['coupon_code' => 'VENCIDO'])->assertSessionHasErrors(['coupon' => 'Este cupom expirou.']);
        $this->order(['coupon_code' => 'FUTURO'])->assertSessionHasErrors(['coupon' => 'Este cupom ainda não começou a valer.']);
        $this->order(['coupon_code' => 'ENTREGA'])->assertSessionHasErrors(['coupon' => 'Este cupom vale só para entrega.']);
        $this->order(['coupon_code' => 'DESLIGADO'])->assertSessionHasErrors('coupon');
        $this->order(['coupon_code' => 'NAOEXISTE'])->assertSessionHasErrors('coupon');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_usage_limits_count_per_coupon_and_per_customer_and_cancel_gives_it_back(): void
    {
        $this->coupon(['code' => 'UMAVEZ', 'type' => CouponType::Fixed, 'amount_cents' => 500, 'per_customer_limit' => 1, 'usage_limit' => 2]);

        $this->order(['coupon_code' => 'UMAVEZ'])->assertSessionHasNoErrors();
        $this->order(['coupon_code' => 'UMAVEZ'])->assertSessionHasErrors(['coupon' => 'Você já usou este cupom.']);
        $this->order(['coupon_code' => 'UMAVEZ'], phone: '11988887777')->assertSessionHasNoErrors();
        $this->order(['coupon_code' => 'UMAVEZ'], phone: '11977776666')->assertSessionHasErrors(['coupon' => 'Este cupom esgotou.']);

        $first = Order::query()->orderBy('id')->firstOrFail();
        $this->actingAs($this->owner)->patch(route('orders.status.update', $first), ['status' => 'cancelled']);

        $this->order(['coupon_code' => 'UMAVEZ'])->assertSessionHasNoErrors();
    }

    public function test_first_order_only(): void
    {
        $this->coupon(['code' => 'BEMVINDO', 'type' => CouponType::Percent, 'percent' => 10, 'first_order_only' => true]);

        $this->order()->assertSessionHasNoErrors();
        $this->order(['coupon_code' => 'BEMVINDO'])->assertSessionHasErrors(['coupon' => 'Este cupom vale só na primeira compra.']);
        $this->order(['coupon_code' => 'BEMVINDO'], phone: '11988887777')->assertSessionHasNoErrors();
    }

    public function test_preview_endpoint_answers_for_the_cart(): void
    {
        $this->coupon(['code' => 'PROMO10', 'type' => CouponType::Percent, 'percent' => 10]);
        Coupon::query()->create(['store_id' => Store::factory()->create()->id, 'code' => 'OUTRALOJA', 'type' => CouponType::Fixed, 'amount_cents' => 100]);

        $this->postJson('/pizzaria-ze/coupon', ['code' => 'promo10', 'subtotal_cents' => 5000, 'fulfillment' => 'pickup'])
            ->assertOk()
            ->assertJson(['valid' => true, 'code' => 'PROMO10', 'discountCents' => 500, 'label' => '10% de desconto']);

        $this->postJson('/pizzaria-ze/coupon', ['code' => 'OUTRALOJA', 'subtotal_cents' => 5000])
            ->assertStatus(422)
            ->assertJson(['valid' => false]);
    }

    public function test_owner_creates_coupon_with_dates_in_the_store_timezone(): void
    {
        $this->actingAs($this->owner)
            ->post(route('marketing.coupons.store'), [
                'code' => 'natal@10',
                'type' => 'percent',
                'percent' => 10,
                'max_discount' => '30,00',
                'min_subtotal' => '50',
                'starts_at' => '2026-12-20T00:00',
                'ends_at' => '2026-12-26T00:00',
                'is_active' => true,
            ])
            ->assertSessionHasErrors('code');

        $this->actingAs($this->owner)
            ->post(route('marketing.coupons.store'), [
                'code' => 'natal10',
                'type' => 'percent',
                'percent' => 10,
                'max_discount' => '30,00',
                'min_subtotal' => '50',
                'amount' => '99',
                'starts_at' => '2026-12-20T00:00',
                'ends_at' => '2026-12-26T00:00',
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $coupon = $this->store->coupons()->sole();
        $this->assertSame('NATAL10', $coupon->code);
        $this->assertSame(3000, $coupon->max_discount_cents);
        $this->assertSame(5000, $coupon->min_subtotal_cents);
        $this->assertNull($coupon->amount_cents);
        $this->assertSame('2026-12-20 03:00:00', $coupon->starts_at?->utc()->toDateTimeString());

        $this->actingAs($this->owner)
            ->post(route('marketing.coupons.store'), ['code' => 'NATAL10', 'type' => 'fixed', 'amount' => '5'])
            ->assertSessionHasErrors('code');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function coupon(array $attributes): Coupon
    {
        return $this->store->coupons()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function order(array $overrides = [], int $quantity = 1, string $phone = '11999998888'): TestResponse
    {
        return $this->post('/pizzaria-ze/orders', array_merge([
            'fulfillment' => 'pickup',
            'customer' => ['name' => 'Maria', 'phone' => $phone],
            'payment_method' => 'pix',
            'items' => [['product_id' => $this->product->id, 'quantity' => $quantity]],
        ], $overrides));
    }
}

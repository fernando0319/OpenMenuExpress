<?php

namespace Tests\Feature\Menu;

use App\Enums\OrderStatus;
use App\Enums\PricingMode;
use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create(['slug' => 'pizzaria-ze']);
        $this->category = Category::factory()->for($this->store)->create();
    }

    public function test_menu_shows_only_active_categories_and_products(): void
    {
        $visible = Product::factory()->for($this->category)->create(['name' => 'X-Burger']);
        Product::factory()->for($this->category)->create(['name' => 'Desativado', 'is_active' => false]);
        $hiddenCategory = Category::factory()->for($this->store)->create(['is_active' => false]);
        Product::factory()->for($hiddenCategory)->create(['name' => 'Em categoria oculta']);

        $this->get('/pizzaria-ze')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('menu/Show')
                ->has('categories', 1)
                ->has('categories.0.products', 1)
                ->where('categories.0.products.0.id', $visible->id));
    }

    public function test_unknown_store_is_not_found(): void
    {
        $this->get('/nao-existe')->assertNotFound();
    }

    public function test_pickup_order_is_priced_on_the_server(): void
    {
        $product = Product::factory()->for($this->category)->create(['price_cents' => 2500]);

        $response = $this->placeOrder([
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'total_cents' => 1]],
            'total_cents' => 1,
        ]);

        $order = Order::query()->sole();
        $response->assertRedirect(route('menu.orders.show', ['pizzaria-ze', $order->public_token]));
        $response->assertCookie('customer_'.$this->store->id);

        $this->assertSame(5000, $order->subtotal_cents);
        $this->assertSame(5000, $order->total_cents);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(1, $order->number);
        $this->assertSame('5511999998888', $order->customer_phone);
        $this->assertSame(1, $this->store->customers()->count());
        $this->assertCount(1, $order->statusHistory);

        $this->get(route('menu.orders.show', ['pizzaria-ze', $order->public_token]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('menu/OrderPlaced')
                ->where('order.totalCents', 5000));
    }

    public function test_promotional_price_and_highest_pricing_mode_are_applied(): void
    {
        $pizza = Product::factory()->for($this->category)->create(['price_cents' => 1000, 'promotional_price_cents' => 800]);
        $flavors = OptionGroup::factory()->for($this->store)->pizzaFlavors(PricingMode::Highest)->create();
        $calabresa = Option::factory()->for($flavors, 'group')->create(['price_cents' => 4500]);
        $camarao = Option::factory()->for($flavors, 'group')->create(['price_cents' => 5900]);
        $pizza->optionGroups()->attach($flavors);

        $this->placeOrder(['items' => [[
            'product_id' => $pizza->id,
            'quantity' => 1,
            'options' => [['option_id' => $calabresa->id], ['option_id' => $camarao->id]],
        ]]])->assertSessionHasNoErrors();

        $order = Order::query()->sole();
        $this->assertSame(800 + 5900, $order->total_cents);
        $this->assertSame(['Sabores', 'Sabores'], $order->items->sole()->options->pluck('group_name')->all());
    }

    public function test_option_group_limits_are_enforced_with_product_overrides(): void
    {
        $pizza = Product::factory()->for($this->category)->create();
        $flavors = OptionGroup::factory()->for($this->store)->pizzaFlavors()->create(); // 1 a 2
        $options = Option::factory()->count(3)->for($flavors, 'group')->create();
        $pizza->optionGroups()->attach($flavors, ['max_choices' => 3]);

        $this->placeOrder(['items' => [['product_id' => $pizza->id, 'quantity' => 1]]])
            ->assertSessionHasErrors('items.0.options');

        $this->placeOrder(['items' => [[
            'product_id' => $pizza->id,
            'quantity' => 1,
            'options' => $options->map(fn (Option $option) => ['option_id' => $option->id])->all(),
        ]]])->assertSessionHasNoErrors();
    }

    public function test_options_from_other_products_or_repeated_without_permission_are_rejected(): void
    {
        $burger = Product::factory()->for($this->category)->create();
        $extras = OptionGroup::factory()->for($this->store)->create(['allows_quantity' => false]);
        $bacon = Option::factory()->for($extras, 'group')->create();
        $burger->optionGroups()->attach($extras);

        $otherGroup = OptionGroup::factory()->for($this->store)->create();
        $notAttached = Option::factory()->for($otherGroup, 'group')->create();

        $this->placeOrder(['items' => [[
            'product_id' => $burger->id,
            'quantity' => 1,
            'options' => [['option_id' => $notAttached->id]],
        ]]])->assertSessionHasErrors('items.0.options');

        $this->placeOrder(['items' => [[
            'product_id' => $burger->id,
            'quantity' => 1,
            'options' => [['option_id' => $bacon->id, 'quantity' => 2]],
        ]]])->assertSessionHasErrors('items.0.options');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_delivery_charges_the_zone_fee(): void
    {
        $product = Product::factory()->for($this->category)->create(['price_cents' => 3000]);
        $zone = DeliveryZone::query()->create(['store_id' => $this->store->id, 'name' => 'Centro', 'fee_cents' => 700]);

        $this->placeOrder([
            'fulfillment' => 'delivery',
            'delivery_zone_id' => $zone->id,
            'address' => ['street' => 'Rua das Flores', 'number' => '10'],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $order = Order::query()->sole();
        $this->assertSame(3700, $order->total_cents);
        $this->assertSame('Centro', $order->delivery_district);
        $this->assertSame(1, $order->customer?->addresses()->count());
    }

    public function test_delivery_requires_an_active_zone_of_the_store(): void
    {
        $product = Product::factory()->for($this->category)->create();
        $inactive = DeliveryZone::query()->create(['store_id' => $this->store->id, 'name' => 'Longe', 'fee_cents' => 0, 'is_active' => false]);

        $this->placeOrder([
            'fulfillment' => 'delivery',
            'delivery_zone_id' => $inactive->id,
            'address' => ['street' => 'Rua A', 'number' => '1'],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('delivery_zone_id');
    }

    public function test_minimum_order_paused_store_and_inactive_products_are_rejected(): void
    {
        $product = Product::factory()->for($this->category)->create(['price_cents' => 1000]);
        $inactive = Product::factory()->for($this->category)->create(['is_active' => false]);

        $this->store->update(['minimum_order_cents' => 2000]);
        $this->placeOrder(['items' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->assertSessionHasErrors('items');

        $this->store->update(['minimum_order_cents' => 0]);
        $this->placeOrder(['items' => [['product_id' => $inactive->id, 'quantity' => 1]]])
            ->assertSessionHasErrors('items.0.product_id');

        $this->store->update(['is_paused' => true]);
        $this->placeOrder(['items' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->assertSessionHasErrors('store');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_stock_is_checked_and_decremented(): void
    {
        $product = Product::factory()->for($this->category)->create(['track_stock' => true, 'stock_quantity' => 3]);

        $this->placeOrder(['items' => [['product_id' => $product->id, 'quantity' => 4]]])
            ->assertSessionHasErrors('items.0.quantity');

        $this->placeOrder(['items' => [['product_id' => $product->id, 'quantity' => 2]]])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $product->fresh()?->stock_quantity);
    }

    public function test_change_must_cover_the_total(): void
    {
        $product = Product::factory()->for($this->category)->create(['price_cents' => 5000]);

        $this->placeOrder([
            'payment_method' => 'cash',
            'change_for' => '20,00',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('change_for');

        $this->placeOrder([
            'payment_method' => 'cash',
            'change_for' => '100',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(10000, Order::query()->sole()->change_for_cents);
    }

    public function test_paying_cash_the_customer_must_answer_about_change(): void
    {
        $product = Product::factory()->for($this->category)->create(['price_cents' => 5000]);

        $this->placeOrder([
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasErrors(['change_for' => 'Informe para quanto precisa de troco, ou marque "Não preciso de troco".']);

        $this->placeOrder([
            'payment_method' => 'cash',
            'no_change' => true,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $this->assertNull(Order::query()->sole()->change_for_cents);
    }

    public function test_order_numbers_are_sequential_per_store(): void
    {
        $product = Product::factory()->for($this->category)->create();
        $otherStore = Store::factory()->create(['slug' => 'outra']);
        $otherProduct = Product::factory()->for(Category::factory()->for($otherStore))->create();

        $this->placeOrder(['items' => [['product_id' => $product->id, 'quantity' => 1]]]);
        $this->placeOrder(['items' => [['product_id' => $product->id, 'quantity' => 1]]]);
        $this->placeOrder(['items' => [['product_id' => $otherProduct->id, 'quantity' => 1]]], 'outra');

        $this->assertSame([1, 2], $this->store->orders()->orderBy('number')->pluck('number')->all());
        $this->assertSame([1], $otherStore->orders()->pluck('number')->all());
    }

    public function test_products_of_another_store_cannot_be_ordered(): void
    {
        $otherProduct = Product::factory()->for(Category::factory()->for(Store::factory()))->create();

        $this->placeOrder(['items' => [['product_id' => $otherProduct->id, 'quantity' => 1]]])
            ->assertSessionHasErrors('items.0.product_id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function placeOrder(array $overrides, string $slug = 'pizzaria-ze'): TestResponse
    {
        return $this->post("/{$slug}/orders", array_merge([
            'fulfillment' => 'pickup',
            'customer' => ['name' => 'Maria', 'phone' => '(11) 99999-8888'],
            'payment_method' => 'pix',
        ], $overrides));
    }
}

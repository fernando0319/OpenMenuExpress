<?php

namespace Tests\Feature\Panel;

use App\Enums\StoreRole;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreBranding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardHomeTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create(['slug' => 'pizzaria-ze', 'timezone' => 'America/Sao_Paulo']);
        $this->owner = $this->member(StoreRole::Owner);
        $this->product = Product::factory()->for(Category::factory()->for($this->store))->create(['name' => 'Pizza', 'price_cents' => 5000]);
    }

    public function test_owner_sees_sales_orders_best_sellers_customers_and_tables(): void
    {
        $completed = $this->order();
        $this->actingAs($this->owner)->patch(route('orders.status.update', $completed), ['status' => 'accepted']);
        $this->actingAs($this->owner)->patch(route('orders.status.update', $completed), ['status' => 'completed']);
        $this->order(); // still pending

        $table = $this->store->diningTables()->create(['name' => 'Mesa 1']);
        $this->store->diningTables()->create(['name' => 'Mesa 2']);
        $this->actingAs($this->owner)->post(route('tables.open', $table), ['people' => 3]);

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('today.current.revenueCents', 5000)
                ->where('today.current.salesCount', 1)
                ->has('week', 7)
                ->where('topProducts.0.name', 'Pizza')
                ->where('topCustomers.0.name', 'Maria')
                ->where('orders.count', 1)
                ->where('orders.pending', 1)
                ->where('tables.total', 2)
                ->where('tables.busy', 1)
                ->where('tables.occupied.0.people', 3)
                ->where('setup.1', ['key' => 'products', 'done' => true]));
    }

    public function test_attendants_see_the_floor_but_not_sales_or_customers(): void
    {
        $this->order();

        $this->actingAs($this->member(StoreRole::Attendant))
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('today', null)
                ->where('week', null)
                ->where('topProducts', null)
                ->where('topCustomers', null)
                ->where('setup', null)
                ->where('orders.count', 1));
    }

    public function test_waiters_and_couriers_go_to_their_own_screens(): void
    {
        $this->actingAs($this->member(StoreRole::Waiter))->get(route('dashboard'))->assertRedirect(route('tables.index'));
        $this->actingAs($this->member(StoreRole::Courier))->get(route('dashboard'))->assertRedirect(route('deliveries.index'));
    }

    public function test_the_panel_takes_the_store_color_when_it_reads_well_in_both_modes(): void
    {
        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentStore.palette.custom', false)
                ->where('currentStore.palette.light.primary', StoreBranding::DEFAULT_PRIMARY));

        $this->store->update(['primary_color' => '#b91c1c']);

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentStore.palette.custom', true)
                ->where('currentStore.palette.light.primary', '#b91c1c')
                ->where('currentStore.palette.dark.foreground', '#111115'));

        $palette = StoreBranding::panelPalette($this->store->fresh() ?? $this->store);
        $this->assertGreaterThanOrEqual(4.5, StoreBranding::contrast($palette['light']['primary'], '#ffffff'));
        $this->assertGreaterThanOrEqual(4.5, StoreBranding::contrast($palette['dark']['primary'], '#111115'));
    }

    public function test_colors_that_would_clash_fall_back_to_the_default_in_both_modes(): void
    {
        // Light orange: darkened a little for the light panel, kept for the dark one.
        $orange = StoreBranding::panelPalette(new Store(['primary_color' => '#f97316']));
        $this->assertTrue($orange['custom']);
        $this->assertNotSame('#f97316', $orange['light']['primary']);
        $this->assertSame('#f97316', $orange['dark']['primary']);

        // Pale yellow would have to change too much: same default in both modes.
        $yellow = StoreBranding::panelPalette(new Store(['primary_color' => '#fde047']));
        $this->assertFalse($yellow['custom']);
        $this->assertSame(StoreBranding::DEFAULT_PRIMARY, $yellow['light']['primary']);
        $this->assertSame('#b9a2f5', $yellow['dark']['primary']);
    }

    private function member(StoreRole $role): User
    {
        $user = User::factory()->create();
        $this->store->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    private function order(): Order
    {
        $this->post('/pizzaria-ze/orders', [
            'fulfillment' => 'pickup',
            'customer' => ['name' => 'Maria', 'phone' => '11999998888'],
            'payment_method' => 'pix',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        return Order::query()->latest('id')->firstOrFail();
    }
}

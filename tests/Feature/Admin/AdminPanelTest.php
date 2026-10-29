<?php

namespace Tests\Feature\Admin;

use App\Enums\StoreRole;
use App\Models\AdminAuditLog;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_super_admin' => true]);
        $this->store = Store::factory()->create(['name' => 'Pizzaria do Zé', 'slug' => 'pizzaria-ze']);
        $this->owner = User::factory()->create(['name' => 'Zé']);
        $this->store->users()->attach($this->owner, ['role' => StoreRole::Owner->value]);
    }

    public function test_only_the_platform_admin_gets_in(): void
    {
        $this->actingAs($this->owner)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($this->owner)->get(route('admin.stores.index'))->assertForbidden();
        $this->actingAs($this->owner)->post(route('admin.stores.suspend', $this->store), ['reason' => 'Teste de acesso'])->assertForbidden();

        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_dashboard_and_store_list_show_activity(): void
    {
        $this->placeOrder();
        Store::factory()->create(['name' => 'Hamburgueria Nova']);

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Dashboard')
                ->where('totals.storesTotal', 2)
                ->where('totals.storesActive7d', 1)
                ->where('totals.ordersToday', 1)
                ->has('daily', 30));

        $this->actingAs($this->admin)
            ->get(route('admin.stores.index', ['sort' => 'orders']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Stores')
                ->where('stores.data.0.name', 'Pizzaria do Zé')
                ->where('stores.data.0.owner', 'Zé')
                ->where('stores.data.0.orders30d', 1));

        $this->actingAs($this->admin)
            ->get(route('admin.stores.index', ['search' => 'hambur']))
            ->assertInertia(fn (Assert $page) => $page->has('stores.data', 1)->where('stores.data.0.name', 'Hamburgueria Nova'));
    }

    public function test_store_detail_keeps_customer_data_private(): void
    {
        $this->placeOrder();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.stores.show', $this->store))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/StoreDetail')
                ->where('members.0.name', 'Zé')
                ->where('recentOrders.0.number', 1));

        $this->assertStringNotContainsString('5511999998888', (string) $response->getContent());
        $this->assertStringNotContainsString('Cliente Secreto', (string) $response->getContent());
    }

    public function test_suspending_blocks_the_menu_and_the_panel_until_reactivated(): void
    {
        $this->actingAs($this->admin)->post(route('admin.stores.suspend', $this->store), ['reason' => ''])->assertSessionHasErrors('reason');

        $this->actingAs($this->admin)
            ->post(route('admin.stores.suspend', $this->store), ['reason' => 'Pagamento da mensalidade em atraso'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->store->fresh()?->isSuspended());

        $this->actingAs($this->owner)
            ->get(route('orders.index'))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('StoreSuspended')->where('reason', 'Pagamento da mensalidade em atraso'));

        auth()->logout();
        $this->get('/pizzaria-ze')->assertStatus(503)->assertInertia(fn (Assert $page) => $page->component('menu/Unavailable'));
        $this->post('/pizzaria-ze/orders', [])->assertStatus(503);

        $this->actingAs($this->admin)->post(route('admin.stores.reactivate', $this->store))->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->get(route('orders.index'))->assertOk();
        $this->assertSame(
            [AdminAuditLog::ACTION_SUSPEND, AdminAuditLog::ACTION_REACTIVATE],
            AdminAuditLog::query()->orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame($this->admin->id, AdminAuditLog::query()->firstOrFail()->user_id);
    }

    public function test_admin_access_is_granted_only_from_the_command_line(): void
    {
        $this->artisan('app:super-admin', ['login' => $this->owner->email])->assertSuccessful();
        $this->assertTrue((bool) $this->owner->fresh()?->is_super_admin);

        $this->artisan('app:super-admin', ['login' => $this->owner->email, '--revoke' => true])->assertSuccessful();
        $this->assertFalse((bool) $this->owner->fresh()?->is_super_admin);

        $this->artisan('app:super-admin', ['login' => 'ninguem@example.com'])->assertFailed();
    }

    private function placeOrder(): void
    {
        $product = Product::factory()->for(Category::factory()->for($this->store))->create(['price_cents' => 3000]);

        $this->post('/pizzaria-ze/orders', [
            'fulfillment' => 'pickup',
            'customer' => ['name' => 'Cliente Secreto', 'phone' => '11999998888'],
            'payment_method' => 'pix',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Order::query()->count());
    }
}

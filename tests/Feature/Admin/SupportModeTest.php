<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
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

class SupportModeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Store $store;

    private User $owner;

    private Store $adminStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_super_admin' => true, 'name' => 'Suporte Fernando']);
        $this->adminStore = Store::factory()->create(['name' => 'Loja do Admin']);
        $this->adminStore->users()->attach($this->admin, ['role' => StoreRole::Owner->value]);

        $this->store = Store::factory()->create(['name' => 'Pizzaria do Zé', 'slug' => 'pizzaria-ze']);
        $this->owner = User::factory()->create();
        $this->store->users()->attach($this->owner, ['role' => StoreRole::Owner->value]);
    }

    public function test_only_the_platform_admin_starts_support_and_must_say_why(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.stores.support', $this->store), ['mode' => 'read', 'reason' => 'Curiosidade'])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->post(route('admin.stores.support', $this->store), ['mode' => 'read', 'reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, AdminAuditLog::query()->count());
    }

    public function test_read_only_support_sees_the_store_but_changes_nothing(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin)
            ->post(route('admin.stores.support', $this->store), ['mode' => 'read', 'reason' => 'Lojista não acha os pedidos'])
            ->assertRedirect(route('dashboard'));

        $this->actingAs($this->admin)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentStore.name', 'Pizzaria do Zé')
                ->where('currentStore.role', 'owner')
                ->where('currentStore.otherStores', [])
                ->where('support.mode', 'read')
                ->where('support.storeName', 'Pizzaria do Zé')
                ->where('orders.data.0.id', $order->id));

        $this->actingAs($this->admin)
            ->patch(route('orders.status.update', $order), ['status' => 'accepted'])
            ->assertRedirect();

        $this->assertSame(OrderStatus::Pending, $order->fresh()?->status);

        $this->actingAs($this->admin)->post(route('admin.support.stop'))->assertRedirect(route('admin.stores.show', $this->store));

        // Back to the admin's own store.
        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('currentStore.name', 'Loja do Admin')->where('support', null));

        $this->assertSame(
            [AdminAuditLog::ACTION_SUPPORT_START, AdminAuditLog::ACTION_SUPPORT_STOP],
            AdminAuditLog::query()->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_full_support_can_change_and_every_change_is_logged(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin)->post(route('admin.stores.support', $this->store), ['mode' => 'full', 'reason' => 'Pedido travado como pendente']);

        $this->actingAs($this->admin)
            ->patch(route('orders.status.update', $order), ['status' => 'accepted'])
            ->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::Accepted, $order->fresh()?->status);
        $this->assertSame($this->admin->id, $order->statusHistory()->latest('id')->firstOrFail()->user_id);

        $change = AdminAuditLog::query()->where('action', AdminAuditLog::ACTION_SUPPORT_CHANGE)->sole();
        $this->assertSame($this->store->id, $change->store_id);
        $this->assertSame('orders.status.update', $change->details['route'] ?? null);

        // The owner sees the support visits and changes in the team screen.
        $this->actingAs($this->owner)
            ->get(route('store-settings.team.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('supportAccesses.0.action', 'Suporte alterou dados')
                ->where('supportAccesses.1.action', 'Suporte entrou (pode alterar)')
                ->where('supportAccesses.1.reason', 'Pedido travado como pendente'));
    }

    public function test_support_ends_by_itself_after_an_hour(): void
    {
        $this->actingAs($this->admin)->post(route('admin.stores.support', $this->store), ['mode' => 'read', 'reason' => 'Verificar impressão']);

        $this->travel(61)->minutes();

        $this->actingAs($this->admin)
            ->get(route('orders.index'))
            ->assertRedirect(route('admin.stores.show', $this->store));

        $this->assertSame(['why' => 'expired'], AdminAuditLog::query()->where('action', AdminAuditLog::ACTION_SUPPORT_STOP)->sole()->details);

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('currentStore.name', 'Loja do Admin'));
    }

    public function test_support_opens_a_suspended_store_and_losing_admin_ends_it(): void
    {
        $this->store->update(['suspended_at' => now(), 'suspension_reason' => 'Teste']);

        $this->actingAs($this->admin)->post(route('admin.stores.support', $this->store), ['mode' => 'read', 'reason' => 'Revisar antes de reativar']);
        $this->actingAs($this->admin)->get(route('orders.index'))->assertOk();

        $this->admin->forceFill(['is_super_admin' => false])->save();

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('currentStore.name', 'Loja do Admin')->where('support', null));
    }

    private function order(): Order
    {
        $product = Product::factory()->for(Category::factory()->for($this->store))->create();

        $this->post('/pizzaria-ze/orders', [
            'fulfillment' => 'pickup',
            'customer' => ['name' => 'Maria', 'phone' => '11999998888'],
            'payment_method' => 'pix',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        return Order::query()->latest('id')->firstOrFail();
    }
}

<?php

namespace Tests\Feature\Panel;

use App\Enums\StoreRole;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaymentMethodsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create(['slug' => 'pizzaria-ze']);
        $this->owner = User::factory()->create();
        $this->store->users()->attach($this->owner, ['role' => StoreRole::Owner->value]);
    }

    public function test_a_new_store_accepts_every_method(): void
    {
        $this->get('/pizzaria-ze')->assertInertia(fn (Assert $page) => $page->has('paymentMethods', 5));

        $this->actingAs($this->owner)
            ->get(route('store-settings.payments.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('store-settings/Payments')
                ->has('methods', 5)
                ->where('methods.0.value', 'cash')
                ->where('methods.0.active', true));
    }

    public function test_owner_chooses_methods_and_their_order_everywhere(): void
    {
        $this->actingAs($this->owner)
            ->put(route('store-settings.payments.update'), ['methods' => [
                ['value' => 'pix', 'active' => true],
                ['value' => 'cash', 'active' => true],
                ['value' => 'credit_card', 'active' => false],
                ['value' => 'debit_card', 'active' => false],
                ['value' => 'meal_voucher', 'active' => false],
            ]])
            ->assertSessionHasNoErrors();

        $expected = [['value' => 'pix', 'label' => 'PIX'], ['value' => 'cash', 'label' => 'Dinheiro']];

        $this->get('/pizzaria-ze')->assertInertia(fn (Assert $page) => $page->where('paymentMethods', $expected));
        $this->actingAs($this->owner)->get(route('pos.show'))->assertInertia(fn (Assert $page) => $page->where('paymentMethods', $expected));

        $this->actingAs($this->owner)
            ->get(route('store-settings.payments.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('methods.0.value', 'pix')->where('methods.2.active', false));
    }

    public function test_the_menu_refuses_a_method_the_store_does_not_accept(): void
    {
        $this->actingAs($this->owner)->put(route('store-settings.payments.update'), ['methods' => [
            ['value' => 'pix', 'active' => true],
            ['value' => 'cash', 'active' => false],
        ]]);
        auth()->logout();

        $product = Product::factory()->for(Category::factory()->for($this->store))->create();

        $this->post('/pizzaria-ze/orders', [
            'fulfillment' => 'pickup',
            'customer' => ['name' => 'Maria', 'phone' => '11999998888'],
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasErrors(['payment_method' => 'Esta loja não aceita Dinheiro. Escolha outra forma de pagamento.']);

        $this->assertSame(0, Order::query()->count());
    }

    public function test_at_least_one_method_stays_on_and_unknown_ones_are_ignored(): void
    {
        $this->actingAs($this->owner)
            ->put(route('store-settings.payments.update'), ['methods' => [
                ['value' => 'cash', 'active' => false],
                ['value' => 'bitcoin', 'active' => true],
            ]])
            ->assertSessionHasErrors('methods');

        $this->actingAs($this->owner)
            ->put(route('store-settings.payments.update'), ['methods' => [['value' => 'debit_card', 'active' => true]]])
            ->assertSessionHasNoErrors();

        // Methods left out of the request are saved as off, after the ones sent.
        $this->actingAs($this->owner)
            ->get(route('store-settings.payments.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('methods', 5)
                ->where('methods.0.value', 'debit_card')
                ->where('methods.1.active', false));
    }

    public function test_admin_sees_what_the_store_accepts_and_attendants_cannot_change_it(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($this->owner)->put(route('store-settings.payments.update'), ['methods' => [['value' => 'pix', 'active' => true]]]);

        $this->actingAs($admin)
            ->get(route('admin.stores.show', $this->store))
            ->assertInertia(fn (Assert $page) => $page->where('paymentMethods.0', ['label' => 'PIX', 'active' => true]));

        $attendant = User::factory()->create();
        $this->store->users()->attach($attendant, ['role' => StoreRole::Attendant->value]);

        $this->actingAs($attendant)->get(route('store-settings.payments.edit'))->assertForbidden();
    }
}

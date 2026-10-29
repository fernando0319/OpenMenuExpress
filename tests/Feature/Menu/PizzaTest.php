<?php

namespace Tests\Feature\Menu;

use App\Enums\FlavorKind;
use App\Enums\PizzaPricing;
use App\Enums\StoreRole;
use App\Models\Category;
use App\Models\Order;
use App\Models\PizzaCrust;
use App\Models\PizzaFlavor;
use App\Models\PizzaFlavorGroup;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Printing\OrderTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class PizzaTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $broto;

    private Product $grande;

    private PizzaFlavor $calabresa;

    private PizzaFlavor $portuguesa;

    private PizzaFlavor $camarao;

    private PizzaFlavor $chocolate;

    private PizzaCrust $catupiry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create(['slug' => 'pizzaria-ze']);
        $category = Category::factory()->for($this->store)->create(['name' => 'Pizzas']);
        $this->broto = $this->pizzaSize($category, 'Pizza Broto', 1, 4, 30);
        $this->grande = $this->pizzaSize($category, 'Pizza Grande', 3, 10, 40);

        $tradicionais = $this->group('Salgadas Tradicionais', FlavorKind::Savory, [$this->broto->id => 5000, $this->grande->id => 10000]);
        $premium = $this->group('Salgadas Premium', FlavorKind::Savory, [$this->broto->id => 5500, $this->grande->id => 11000]);
        $doces = $this->group('Doces Tradicionais', FlavorKind::Sweet, [$this->grande->id => 9000]);

        $this->calabresa = $this->flavor($tradicionais, 'Calabresa');
        $this->portuguesa = $this->flavor($tradicionais, 'Portuguesa');
        $this->camarao = $this->flavor($premium, 'Camarão', extra: 1500);
        $this->chocolate = $this->flavor($doces, 'Chocolate');

        $this->catupiry = $this->store->pizzaCrusts()->create(['name' => 'Catupiry', 'prices' => [$this->grande->id => 1200]]);
    }

    public function test_the_most_expensive_flavor_sets_the_price_by_default(): void
    {
        $this->order($this->grande, [$this->calabresa->id, $this->camarao->id], $this->catupiry->id)->assertSessionHasNoErrors();

        $order = Order::query()->with('items.options')->sole();
        $item = $order->items->sole();
        $this->assertSame(12500 + 1200, $order->total_cents); // Camarão: 110,00 + 15,00, borda 12,00
        $this->assertSame(12500, $item->unit_price_cents);
        $this->assertSame(['½ Calabresa', '½ Camarão', 'Catupiry'], $item->options->pluck('option_name')->all());
        $this->assertSame(['Sabores', 'Sabores', 'Borda'], $item->options->pluck('group_name')->all());

        $ticket = (string) iconv('CP850', 'UTF-8', app(OrderTicket::class)->render($order, 48));
        $this->assertStringContainsString('½ Calabresa / ½ Camarão', $ticket);
        $this->assertStringContainsString('Borda: Catupiry', $ticket);
    }

    public function test_the_store_may_charge_the_average_instead(): void
    {
        $this->store->update(['pizza_pricing' => PizzaPricing::Average]);

        $this->order($this->grande, [$this->calabresa->id, $this->portuguesa->id, $this->camarao->id])->assertSessionHasNoErrors();

        $this->assertSame((int) round((10000 + 10000 + 12500) / 3), Order::query()->sole()->total_cents);
        $this->assertSame(['⅓ Calabresa', '⅓ Portuguesa', '⅓ Camarão'], Order::query()->sole()->items->sole()->options->pluck('option_name')->all());
    }

    public function test_size_rules_are_enforced(): void
    {
        $this->order($this->broto, [$this->calabresa->id, $this->portuguesa->id])
            ->assertSessionHasErrors(['items.0.pizza' => 'A Pizza Broto aceita até 1 sabor(es).']);

        $this->order($this->broto, [])->assertSessionHasErrors('items.0.pizza');

        // Sweet flavors have no price in the broto: not offered there.
        $this->order($this->broto, [$this->chocolate->id])->assertSessionHasErrors('items.0.pizza');

        // The crust only exists in the grande.
        $this->order($this->broto, [$this->calabresa->id], $this->catupiry->id)->assertSessionHasErrors('items.0.pizza');

        $this->camarao->update(['unavailable_product_ids' => [$this->grande->id]]);
        $this->order($this->grande, [$this->camarao->id])->assertSessionHasErrors('items.0.pizza');

        $this->calabresa->update(['is_active' => false]);
        $this->order($this->grande, [$this->calabresa->id])->assertSessionHasErrors('items.0.pizza');

        $this->order($this->grande, [$this->portuguesa->id, $this->portuguesa->id])->assertSessionHasErrors('items.0.pizza');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_mixing_sweet_and_savory_follows_the_store(): void
    {
        $this->order($this->grande, [$this->calabresa->id, $this->chocolate->id])->assertSessionHasNoErrors();

        $this->store->update(['pizza_mix_kinds' => false]);
        $this->order($this->grande, [$this->calabresa->id, $this->chocolate->id])
            ->assertSessionHasErrors(['items.0.pizza' => 'Esta pizzaria não mistura sabores doces e salgados na mesma pizza.']);
    }

    public function test_the_menu_shows_each_size_from_its_cheapest_flavor(): void
    {
        $this->get('/pizzaria-ze')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('categories.0.products.0.name', 'Pizza Broto')
                ->where('categories.0.products.0.effectivePriceCents', 5000)
                ->where('categories.0.products.0.pizza.maxFlavors', 1)
                ->where('categories.0.products.0.pizza.slices', 4)
                ->has('categories.0.products.0.pizza.groups', 2) // no sweet flavors in the broto
                ->has('categories.0.products.0.pizza.crusts', 0)
                ->where('categories.0.products.1.effectivePriceCents', 9000)
                ->where('categories.0.products.1.pizza.groups.1.flavors.0.priceCents', 12500)
                ->has('categories.0.products.1.pizza.crusts', 1));
    }

    public function test_the_merchant_manages_the_price_table(): void
    {
        $owner = User::factory()->create();
        $this->store->users()->attach($owner, ['role' => StoreRole::Owner->value]);
        $group = PizzaFlavorGroup::query()->where('name', 'Salgadas Tradicionais')->sole();

        $this->actingAs($owner)->get(route('catalog.pizzas.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('catalog/Pizzas')
                ->has('sizes', 2)
                ->where("groups.0.prices.{$this->grande->id}", '100,00'));

        $this->actingAs($owner)->put(route('catalog.pizzas.prices'), [
            'groups' => [$group->id => [$this->broto->id => '52,00', $this->grande->id => '']],
            'crusts' => [$this->catupiry->id => [$this->broto->id => '8,00', $this->grande->id => '12,00']],
        ])->assertSessionHasNoErrors();

        $this->assertSame([$this->broto->id => 5200], $group->fresh()?->prices);
        $this->assertSame(800, $this->catupiry->fresh()?->priceFor($this->broto->id));

        $this->actingAs($owner)->put(route('catalog.pizzas.prices'), [
            'groups' => [$group->id => [$this->broto->id => 'caro']],
        ])->assertSessionHasErrors("groups.{$group->id}.{$this->broto->id}");

        $this->actingAs($owner)->post(route('catalog.pizzas.adjust'), ['percent' => '10', 'target' => 'groups'])->assertSessionHasNoErrors();
        $this->assertSame(5720, $group->fresh()?->priceFor($this->broto->id));
        $this->assertSame(800, $this->catupiry->fresh()?->priceFor($this->broto->id));

        $this->actingAs($owner)->patch(route('catalog.pizzas.flavors.toggle', $this->calabresa))->assertSessionHasNoErrors();
        $this->assertFalse($this->calabresa->fresh()?->is_active);

        $this->actingAs($owner)->put(route('catalog.pizzas.settings'), ['pricing' => 'average', 'mix_kinds' => false])->assertSessionHasNoErrors();
        $this->assertSame(PizzaPricing::Average, $this->store->fresh()?->pizza_pricing);
    }

    public function test_a_pizza_size_is_a_product_without_its_own_price(): void
    {
        $owner = User::factory()->create();
        $this->store->users()->attach($owner, ['role' => StoreRole::Owner->value]);

        $this->actingAs($owner)->post(route('catalog.products.store'), [
            'category_id' => $this->broto->category_id,
            'kind' => 'pizza',
            'name' => 'Pizza Gigante',
            'price' => '',
            'pizza_max_flavors' => 4,
            'pizza_slices' => 16,
            'pizza_diameter_cm' => 45,
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $gigante = Product::query()->where('name', 'Pizza Gigante')->sole();
        $this->assertTrue($gigante->isPizza());
        $this->assertSame(0, $gigante->price_cents);
        $this->assertSame(4, $gigante->pizza_max_flavors);
    }

    private function pizzaSize(Category $category, string $name, int $maxFlavors, int $slices, int $diameter): Product
    {
        return Product::factory()->for($category)->create([
            'name' => $name,
            'kind' => Product::KIND_PIZZA,
            'price_cents' => 0,
            'pizza_max_flavors' => $maxFlavors,
            'pizza_slices' => $slices,
            'pizza_diameter_cm' => $diameter,
            'sort_order' => $maxFlavors,
        ]);
    }

    /**
     * @param  array<int, int>  $prices
     */
    private function group(string $name, FlavorKind $kind, array $prices): PizzaFlavorGroup
    {
        return $this->store->pizzaFlavorGroups()->create(['name' => $name, 'kind' => $kind, 'prices' => $prices]);
    }

    private function flavor(PizzaFlavorGroup $group, string $name, int $extra = 0): PizzaFlavor
    {
        return $this->store->pizzaFlavors()->create(['group_id' => $group->id, 'name' => $name, 'extra_cents' => $extra]);
    }

    /**
     * @param  list<int>  $flavorIds
     * @return TestResponse<Response>
     */
    private function order(Product $size, array $flavorIds, ?int $crustId = null): TestResponse
    {
        return $this->post('/pizzaria-ze/orders', [
            'fulfillment' => 'pickup',
            'customer' => ['name' => 'Maria', 'phone' => '(11) 99999-8888'],
            'payment_method' => 'pix',
            'items' => [[
                'product_id' => $size->id,
                'quantity' => 1,
                'pizza' => ['flavor_ids' => $flavorIds, 'crust_id' => $crustId],
            ]],
        ]);
    }
}

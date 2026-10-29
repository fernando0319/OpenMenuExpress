<?php

namespace Tests\Feature\Panel;

use App\Enums\PricingMode;
use App\Enums\StoreRole;
use App\Models\Category;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CatalogManagementTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->owner = $this->memberOf($this->store, StoreRole::Owner);
    }

    public function test_owner_manages_categories(): void
    {
        $this->actingAs($this->owner)
            ->post(route('catalog.categories.store'), ['name' => 'Pizzas', 'is_active' => true])
            ->assertRedirect(route('catalog.categories.index'));

        $category = $this->store->categories()->sole();

        $this->actingAs($this->owner)
            ->put(route('catalog.categories.update', $category), ['name' => 'Pizzas salgadas', 'is_active' => false])
            ->assertRedirect();

        $this->assertSame('Pizzas salgadas', $category->fresh()?->name);

        $this->actingAs($this->owner)->delete(route('catalog.categories.destroy', $category));
        $this->assertModelMissing($category);
    }

    public function test_category_with_products_is_not_deleted(): void
    {
        $category = Category::factory()->for($this->store)->create();
        Product::factory()->for($category)->create();

        $this->actingAs($this->owner)->delete(route('catalog.categories.destroy', $category));

        $this->assertModelExists($category);
    }

    public function test_option_group_is_created_with_options_and_pricing_mode(): void
    {
        $this->actingAs($this->owner)
            ->post(route('catalog.option-groups.store'), [
                'name' => 'Sabores',
                'min_choices' => 1,
                'max_choices' => 2,
                'pricing_mode' => PricingMode::Average->value,
                'options' => [
                    ['name' => 'Calabresa', 'price' => '45,00'],
                    ['name' => 'Camarão', 'price' => '59,90'],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('catalog.option-groups.index'));

        $group = $this->store->optionGroups()->sole();
        $this->assertSame(PricingMode::Average, $group->pricing_mode);
        $this->assertSame([4500, 5990], $group->options->pluck('price_cents')->all());
    }

    public function test_updating_an_option_group_syncs_its_options(): void
    {
        $group = OptionGroup::factory()->for($this->store)->create();
        $kept = Option::factory()->for($group, 'group')->create(['name' => 'Bacon']);
        $removed = Option::factory()->for($group, 'group')->create(['name' => 'Ovo']);

        $this->actingAs($this->owner)
            ->put(route('catalog.option-groups.update', $group), [
                'name' => 'Adicionais',
                'min_choices' => 0,
                'max_choices' => 3,
                'pricing_mode' => PricingMode::Sum->value,
                'options' => [
                    ['id' => $kept->id, 'name' => 'Bacon crocante', 'price' => '6'],
                    ['name' => 'Cheddar', 'price' => '4,50'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Bacon crocante', $kept->fresh()?->name);
        $this->assertSame(600, $kept->fresh()?->price_cents);
        $this->assertModelMissing($removed);
        $this->assertSame(['Bacon crocante', 'Cheddar'], $group->options()->pluck('name')->all());
    }

    public function test_max_choices_cannot_be_lower_than_min(): void
    {
        $this->actingAs($this->owner)
            ->post(route('catalog.option-groups.store'), [
                'name' => 'Sabores',
                'min_choices' => 3,
                'max_choices' => 2,
                'pricing_mode' => PricingMode::Sum->value,
                'options' => [['name' => 'A', 'price' => '1']],
            ])
            ->assertSessionHasErrors('max_choices');
    }

    public function test_product_is_created_with_prices_and_option_groups(): void
    {
        $category = Category::factory()->for($this->store)->create();
        $flavors = OptionGroup::factory()->for($this->store)->pizzaFlavors()->create();

        $this->actingAs($this->owner)
            ->post(route('catalog.products.store'), [
                'category_id' => $category->id,
                'name' => 'Pizza grande',
                'price' => '1.234,50',
                'promotional_price' => '999,90',
                'is_active' => true,
                'option_groups' => [['id' => $flavors->id, 'max_choices' => 3]],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('catalog.products.index'));

        $product = $this->store->products()->sole();
        $this->assertSame(123450, $product->price_cents);
        $this->assertSame(99990, $product->promotional_price_cents);
        $this->assertSame(3, $product->optionGroups->first()?->pivot?->getAttribute('max_choices'));
    }

    public function test_promotional_price_must_be_lower_than_price(): void
    {
        $category = Category::factory()->for($this->store)->create();

        $this->actingAs($this->owner)
            ->post(route('catalog.products.store'), [
                'category_id' => $category->id,
                'name' => 'X-Burger',
                'price' => '20,00',
                'promotional_price' => '25,00',
            ])
            ->assertSessionHasErrors('promotional_price_cents');
    }

    public function test_invalid_price_is_rejected(): void
    {
        $category = Category::factory()->for($this->store)->create();

        $this->actingAs($this->owner)
            ->post(route('catalog.products.store'), [
                'category_id' => $category->id,
                'name' => 'X-Burger',
                'price' => 'vinte reais',
            ])
            ->assertSessionHasErrors('price_cents');
    }

    public function test_records_from_another_store_are_not_reachable(): void
    {
        $otherStore = Store::factory()->create();
        $otherCategory = Category::factory()->for($otherStore)->create();
        $otherProduct = Product::factory()->for($otherCategory)->create();
        $otherGroup = OptionGroup::factory()->for($otherStore)->create();

        $this->actingAs($this->owner)
            ->get(route('catalog.products.edit', $otherProduct))
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->delete(route('catalog.option-groups.destroy', $otherGroup))
            ->assertNotFound();

        $ownCategory = Category::factory()->for($this->store)->create();

        $this->actingAs($this->owner)
            ->post(route('catalog.products.store'), [
                'category_id' => $otherCategory->id,
                'name' => 'Invasor',
                'price' => '10',
            ])
            ->assertSessionHasErrors('category_id');

        $this->actingAs($this->owner)
            ->post(route('catalog.products.store'), [
                'category_id' => $ownCategory->id,
                'name' => 'Invasor',
                'price' => '10',
                'option_groups' => [['id' => $otherGroup->id]],
            ])
            ->assertSessionHasErrors('option_groups.0.id');
    }

    public function test_catalog_screens_render(): void
    {
        $category = Category::factory()->for($this->store)->create();
        $product = Product::factory()->for($category)->create();
        $group = OptionGroup::factory()->for($this->store)->create();
        Option::factory()->for($group, 'group')->create();
        $product->optionGroups()->attach($group);

        $screens = [
            route('catalog.categories.index') => 'catalog/Categories',
            route('catalog.option-groups.index') => 'catalog/option-groups/Index',
            route('catalog.option-groups.create') => 'catalog/option-groups/Form',
            route('catalog.option-groups.edit', $group) => 'catalog/option-groups/Form',
            route('catalog.products.index') => 'catalog/products/Index',
            route('catalog.products.create') => 'catalog/products/Form',
            route('catalog.products.edit', $product) => 'catalog/products/Form',
        ];

        foreach ($screens as $url => $component) {
            $this->actingAs($this->owner)
                ->get($url)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component($component));
        }
    }

    public function test_attendant_cannot_manage_catalog(): void
    {
        $attendant = $this->memberOf($this->store, StoreRole::Attendant);

        $this->actingAs($attendant)
            ->get(route('catalog.products.index'))
            ->assertForbidden();
    }

    private function memberOf(Store $store, StoreRole $role): User
    {
        $user = User::factory()->create();
        $store->users()->attach($user, ['role' => $role->value]);

        return $user;
    }
}

<?php

namespace Tests\Feature\Domain;

use App\Enums\PricingMode;
use App\Enums\StoreRole;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_gets_a_role_in_a_store(): void
    {
        $store = Store::factory()->create();
        $user = User::factory()->create();

        $store->users()->attach($user, ['role' => StoreRole::Owner->value]);

        $this->assertSame(StoreRole::Owner, $store->roleOf($user));
        $this->assertTrue($user->stores->contains($store));
    }

    public function test_option_group_is_shared_between_products_with_overrides(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->for($store)->create();
        $flavors = OptionGroup::factory()->for($store)->pizzaFlavors(PricingMode::Average)->create();
        Option::factory()->count(3)->for($flavors, 'group')->create();

        $small = Product::factory()->for($category)->create(['name' => 'Pizza broto']);
        $large = Product::factory()->for($category)->create(['name' => 'Pizza grande']);

        $small->optionGroups()->attach($flavors, ['max_choices' => 1]);
        $large->optionGroups()->attach($flavors);

        $this->assertSame(PricingMode::Average, $large->optionGroups->first()?->pricing_mode);
        $this->assertSame(1, $small->optionGroups->first()?->pivot?->getAttribute('max_choices'));
        $this->assertNull($large->optionGroups->first()?->pivot?->getAttribute('max_choices'));
        $this->assertCount(3, $flavors->options);
    }

    public function test_promotional_price_only_applies_when_lower(): void
    {
        $product = Product::factory()->make(['price_cents' => 3000, 'promotional_price_cents' => 2500]);
        $this->assertSame(2500, $product->effectivePriceCents());

        $product->promotional_price_cents = 3500;
        $this->assertSame(3000, $product->effectivePriceCents());
    }

    public function test_customer_phone_is_unique_per_store(): void
    {
        $store = Store::factory()->create();
        Customer::factory()->for($store)->create(['phone' => '5511999998888']);
        Customer::factory()->create(['phone' => '5511999998888']); // outra loja: permitido

        $this->expectException(QueryException::class);

        Customer::factory()->for($store)->create(['phone' => '5511999998888']);
    }
}

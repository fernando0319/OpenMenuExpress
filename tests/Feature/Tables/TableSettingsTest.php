<?php

namespace Tests\Feature\Tables;

use App\Enums\StoreRole;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TableSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->owner = User::factory()->create();
        $this->store->users()->attach($this->owner, ['role' => StoreRole::Owner->value]);
    }

    public function test_owner_creates_numbered_tables_and_single_ones(): void
    {
        $this->actingAs($this->owner)->post(route('store-settings.tables.items.store'), ['from' => 1, 'to' => 5, 'seats' => 4])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('store-settings.tables.items.store'), ['from' => 4, 'to' => 6])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('store-settings.tables.items.store'), ['name' => 'Varanda', 'seats' => 8])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('store-settings.tables.items.store'), ['name' => 'Varanda'])
            ->assertSessionHasErrors('name');

        $this->assertSame(
            ['Mesa 1', 'Mesa 2', 'Mesa 3', 'Mesa 4', 'Mesa 5', 'Mesa 6', 'Varanda'],
            $this->store->diningTables()->orderBy('sort_order')->pluck('name')->all(),
        );

        $this->actingAs($this->owner)
            ->get(route('store-settings.tables.edit'))
            ->assertInertia(fn (Assert $page) => $page->component('store-settings/Tables')->has('tables', 7)->where('serviceFeePercent', 10));
    }

    public function test_service_fee_and_table_history_protection(): void
    {
        $this->actingAs($this->owner)->put(route('store-settings.tables.update'), ['service_fee_percent' => 12])->assertSessionHasNoErrors();
        $this->assertSame(12, $this->store->fresh()?->service_fee_percent);

        $this->actingAs($this->owner)->put(route('store-settings.tables.update'), ['service_fee_percent' => 50])->assertSessionHasErrors('service_fee_percent');

        $used = $this->store->diningTables()->create(['name' => 'Mesa 1']);
        $used->tabs()->create(['store_id' => $this->store->id, 'status' => 'closed', 'opened_at' => now()]);
        $unused = $this->store->diningTables()->create(['name' => 'Mesa 2']);

        $this->actingAs($this->owner)->delete(route('store-settings.tables.items.destroy', $used));
        $this->actingAs($this->owner)->delete(route('store-settings.tables.items.destroy', $unused));

        $this->assertSame(['Mesa 1'], $this->store->diningTables()->pluck('name')->all());

        $this->actingAs($this->owner)
            ->put(route('store-settings.tables.items.update', $used), ['name' => 'Mesa 1', 'is_active' => false])
            ->assertSessionHasNoErrors();
        $this->assertFalse($used->fresh()?->is_active);
    }
}

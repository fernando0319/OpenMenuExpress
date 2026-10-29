<?php

namespace Tests\Feature\Tables;

use App\Enums\StoreRole;
use App\Enums\TabStatus;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class JoinTablesTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $beer;

    private User $waiter;

    private DiningTable $three;

    private DiningTable $four;

    private DiningTable $five;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create(['service_fee_percent' => 10]);
        $this->beer = Product::factory()->for(Category::factory()->for($this->store))->create(['name' => 'Chopp', 'price_cents' => 1000]);
        $this->waiter = User::factory()->create();
        $this->store->users()->attach($this->waiter, ['role' => StoreRole::Waiter->value]);
        $this->three = $this->store->diningTables()->create(['name' => 'Mesa 3', 'sort_order' => 3]);
        $this->four = $this->store->diningTables()->create(['name' => 'Mesa 4', 'sort_order' => 4]);
        $this->five = $this->store->diningTables()->create(['name' => 'Mesa 5', 'sort_order' => 5]);
    }

    public function test_a_free_table_joins_and_the_group_has_one_bill(): void
    {
        $tab = $this->open($this->three, 4);

        $this->actingAs($this->waiter)->post(route('tables.join', $tab), ['table_id' => $this->four->id])->assertSessionHasNoErrors();

        $this->assertSame($tab->id, $this->four->fresh()?->joined_tab_id);
        $this->round($tab, 2);
        $this->assertSame('Mesa 3 + Mesa 4', Order::query()->sole()->customer_name);

        // The joined table is taken: it cannot be opened, and its screen leads to the main table.
        $this->actingAs($this->waiter)->post(route('tables.open', $this->four))->assertSessionHasErrors('table');
        $this->actingAs($this->waiter)->get(route('tables.show', $this->four))->assertRedirect(route('tables.show', $this->three));

        $this->actingAs($this->waiter)->get(route('tables.index'))->assertInertia(fn (Assert $page) => $page
            ->where('tables.0.joinedNames', ['Mesa 4'])
            ->where('tables.1.status', 'joined')
            ->where('tables.1.joinedTo.name', 'Mesa 3')
            ->where('tables.2.status', 'free'));
    }

    public function test_an_occupied_table_brings_its_rounds_when_joined(): void
    {
        $main = $this->open($this->three, 2);
        $this->round($main, 1);
        $other = $this->open($this->four, 3);
        $this->round($other, 2);

        $this->actingAs($this->waiter)->post(route('tables.join', $main), ['table_id' => $this->four->id])->assertSessionHasNoErrors();

        $this->assertSame(3000, $main->fresh()?->consumedCents());
        $this->assertSame(5, $main->fresh()?->people);
        $this->assertSame(TabStatus::Cancelled, $other->fresh()?->status);
        $this->assertSame(0, $other->fresh()?->orders()->count());
    }

    public function test_separating_frees_the_table_and_keeps_the_consumption(): void
    {
        $tab = $this->open($this->three);
        $this->actingAs($this->waiter)->post(route('tables.join', $tab), ['table_id' => $this->four->id]);
        $this->round($tab, 1);

        $this->actingAs($this->waiter)->post(route('tables.separate', $tab), ['table_id' => $this->four->id])->assertSessionHasNoErrors();

        $this->assertNull($this->four->fresh()?->joined_tab_id);
        $this->assertSame(1000, $tab->fresh()?->consumedCents());
        $this->actingAs($this->waiter)->post(route('tables.open', $this->four))->assertSessionHasNoErrors();
    }

    public function test_closing_or_releasing_frees_the_joined_tables(): void
    {
        $tab = $this->open($this->three);
        $this->actingAs($this->waiter)->post(route('tables.join', $tab), ['table_id' => $this->four->id]);

        $this->actingAs($this->waiter)->post(route('tables.release', $tab))->assertSessionHasNoErrors();

        $this->assertNull($this->four->fresh()?->joined_tab_id);
    }

    public function test_tables_already_joined_elsewhere_or_the_same_table_are_refused(): void
    {
        $first = $this->open($this->three);
        $this->actingAs($this->waiter)->post(route('tables.join', $first), ['table_id' => $this->four->id]);
        $second = $this->open($this->five);

        $this->actingAs($this->waiter)->post(route('tables.join', $second), ['table_id' => $this->four->id])
            ->assertSessionHasErrors(['table' => 'A Mesa 4 já está junta com outra mesa. Separe antes.']);
        $this->actingAs($this->waiter)->post(route('tables.join', $first), ['table_id' => $this->three->id])
            ->assertSessionHasErrors('table');

        // Transfer only goes to a table that is really free.
        $this->actingAs($this->waiter)->post(route('tables.transfer', $second), ['table_id' => $this->four->id])
            ->assertSessionHasErrors('table');
    }

    public function test_tables_of_another_store_cannot_be_joined(): void
    {
        $tab = $this->open($this->three);
        $foreign = Store::factory()->create()->diningTables()->create(['name' => 'Mesa 9']);

        $this->actingAs($this->waiter)->post(route('tables.join', $tab), ['table_id' => $foreign->id])->assertNotFound();
    }

    private function open(DiningTable $table, ?int $people = null): Tab
    {
        $this->actingAs($this->waiter)->post(route('tables.open', $table), ['people' => $people])->assertSessionHasNoErrors();

        return Tab::query()->where('dining_table_id', $table->id)->latest('id')->firstOrFail();
    }

    private function round(Tab $tab, int $quantity): void
    {
        $this->actingAs($this->waiter)->post(route('tables.rounds.store', $tab), [
            'items' => [['product_id' => $this->beer->id, 'quantity' => $quantity]],
        ])->assertSessionHasNoErrors();
    }
}

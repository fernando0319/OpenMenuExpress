<?php

namespace Tests\Feature\Pos;

use App\Enums\StoreRole;
use App\Models\CashSession;
use App\Models\Category;
use App\Models\PrintAgent;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Printing\CashReportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CashRegisterTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create(['name' => 'Pizzaria do Zé']);
        $this->owner = User::factory()->create(['name' => 'Zé']);
        $this->store->users()->attach($this->owner, ['role' => StoreRole::Owner->value]);
    }

    public function test_full_cash_day_from_opening_to_closing(): void
    {
        $this->actingAs($this->owner)->post(route('cash.open'), ['opening' => '150,00'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('cash.open'), ['opening' => '10'])->assertSessionHasErrors('cash');

        $product = Product::factory()->for(Category::factory()->for($this->store))->create(['price_cents' => 3000]);
        $this->actingAs($this->owner)->post(route('pos.orders.store'), [
            'fulfillment' => 'pickup',
            'complete_now' => true,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => '30']],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->post(route('cash.movements.store'), ['type' => 'supply', 'amount' => '20', 'description' => 'Troco'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('cash.movements.store'), ['type' => 'withdrawal', 'amount' => '500'])
            ->assertSessionHasErrors('amount');
        $this->actingAs($this->owner)->post(route('cash.movements.store'), ['type' => 'withdrawal', 'amount' => '100', 'description' => 'Depósito'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->owner)
            ->get(route('cash.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('pos/Cash')
                ->where('session.summary.expectedCashCents', 10000) // 150 + 30 + 20 - 100
                ->where('session.summary.ordersCount', 1)
                ->has('session.movements', 3));

        $session = CashSession::query()->sole();

        $this->actingAs($this->owner)
            ->post(route('cash.close'), ['counted' => '95,50', 'notes' => 'Faltou troco'])
            ->assertRedirect(route('cash.show', $session));

        $session->refresh();
        $this->assertFalse($session->isOpen());
        $this->assertSame(10000, $session->expected_cash_cents);
        $this->assertSame(-450, $session->differenceCents());

        $this->actingAs($this->owner)->post(route('cash.movements.store'), ['type' => 'supply', 'amount' => '5'])
            ->assertSessionHasErrors('cash');

        // A new day can start right after.
        $this->actingAs($this->owner)->post(route('cash.open'), ['opening' => '0'])->assertSessionHasNoErrors();
        $this->assertSame(2, CashSession::query()->count());
    }

    public function test_closing_report_prints_on_the_first_printer(): void
    {
        $agent = $this->store->printAgents()->create(['name' => 'Caixa', 'token_hash' => PrintAgent::hashToken('x')]);
        $agent->printers()->create(['store_id' => $this->store->id, 'system_name' => 'POS-58', 'label' => 'Balcão', 'paper_width' => 58, 'copies' => 2]);

        $this->actingAs($this->owner)->post(route('cash.open'), ['opening' => '50']);
        $this->actingAs($this->owner)->post(route('cash.close'), ['counted' => '50', 'print' => true])->assertSessionHasNoErrors();

        $job = PrintJob::query()->sole();
        $this->assertSame('cash', $job->kind);

        $session = CashSession::query()->sole();
        $text = (string) iconv('CP850', 'UTF-8', (new CashReportTicket)->render($session, 32));
        $this->assertStringContainsString('FECHAMENTO', $text);
        $this->assertStringContainsString('R$ 50,00', $text);

        foreach (explode("\n", $text) as $line) {
            // ESC/POS commands: "ESC @" and three-byte "ESC x n" / "GS x n".
            $printable = preg_replace('/\x1B@|[\x1B\x1D]..|[\x00-\x1F]/', '', $line) ?? '';
            $this->assertLessThanOrEqual(32, mb_strlen($printable), "Line too long: {$printable}");
        }
    }

    public function test_sessions_of_other_stores_are_hidden(): void
    {
        $other = Store::factory()->create();
        $session = $other->cashSessions()->create(['opening_cents' => 0, 'opened_at' => now()]);

        $this->actingAs($this->owner)->get(route('cash.show', $session))->assertNotFound();
        $this->actingAs($this->owner)->post(route('cash.print', $session))->assertNotFound();
    }
}

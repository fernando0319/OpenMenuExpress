<?php

namespace Tests\Feature\Printing;

use App\Enums\AutoPrint;
use App\Enums\StoreRole;
use App\Models\Category;
use App\Models\Order;
use App\Models\PrintAgent;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Printing\OrderTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PrintingTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create(['slug' => 'pizzaria-ze', 'name' => 'Pizzaria do Zé']);
        $this->owner = User::factory()->create();
        $this->store->users()->attach($this->owner, ['role' => StoreRole::Owner->value]);
    }

    public function test_agent_pairs_with_a_one_time_code(): void
    {
        $this->actingAs($this->owner)->post(route('store-settings.printing.pairing-code'));
        $code = Session::get('print_pairing_code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);

        $response = $this->postJson('/api/print-agent/pair', ['code' => $code, 'name' => 'Caixa', 'platform' => 'windows'])
            ->assertCreated()
            ->assertJsonPath('store', 'Pizzaria do Zé');

        $token = $response->json('token');
        $agent = $this->store->printAgents()->sole();
        $this->assertSame(PrintAgent::hashToken($token), $agent->token_hash);

        $this->postJson('/api/print-agent/pair', ['code' => $code, 'name' => 'Outro'])
            ->assertStatus(422);
    }

    public function test_heartbeat_reports_printers_and_requires_a_valid_token(): void
    {
        [$agent, $token] = $this->agent();

        $this->postJson('/api/print-agent/heartbeat', ['printers' => ['EPSON TM-T20', 'POS-58']])->assertUnauthorized();

        $this->withToken($token)
            ->postJson('/api/print-agent/heartbeat', ['printers' => ['EPSON TM-T20', 'POS-58'], 'version' => '0.1.0'])
            ->assertOk();

        $agent->refresh();
        $this->assertSame(['EPSON TM-T20', 'POS-58'], $agent->available_printers);
        $this->assertTrue($agent->isOnline());
    }

    public function test_jobs_are_handed_out_once_and_again_if_never_confirmed(): void
    {
        [$agent, $token] = $this->agent();
        $printer = $this->printer($agent);
        $order = $this->order();

        $this->actingAs($this->owner)->post(route('orders.print', $order))->assertSessionHasNoErrors();

        $jobs = $this->withToken($token)->getJson('/api/print-agent/jobs')->assertOk()->json('jobs');
        $this->assertCount(1, $jobs);
        $this->assertSame('EPSON TM-T20', $jobs[0]['printer']);
        $this->assertStringContainsString('PEDIDO 1', (string) base64_decode($jobs[0]['payload']));

        $this->assertSame([], $this->withToken($token)->getJson('/api/print-agent/jobs')->json('jobs'));

        $this->travel(PrintJob::RETRY_AFTER_SECONDS + 5)->seconds();
        $this->assertCount(1, $this->withToken($token)->getJson('/api/print-agent/jobs')->json('jobs'));

        $this->withToken($token)
            ->postJson("/api/print-agent/jobs/{$jobs[0]['id']}/result", ['status' => 'printed'])
            ->assertOk();

        $job = PrintJob::query()->sole();
        $this->assertSame(PrintJob::STATUS_PRINTED, $job->status);
        $this->assertSame(2, $job->attempts);
        $this->assertSame($printer->id, $job->printer_id);
    }

    public function test_failed_print_goes_back_to_the_queue(): void
    {
        [$agent, $token] = $this->agent();
        $this->printer($agent);
        $this->actingAs($this->owner)->post(route('orders.print', $this->order()));

        $id = $this->withToken($token)->getJson('/api/print-agent/jobs')->json('jobs.0.id');
        $this->withToken($token)->postJson("/api/print-agent/jobs/{$id}/result", ['status' => 'failed', 'error' => 'Sem papel']);

        $job = PrintJob::query()->sole();
        $this->assertSame(PrintJob::STATUS_PENDING, $job->status);
        $this->assertSame('Sem papel', $job->error);
    }

    public function test_an_agent_cannot_take_or_confirm_another_stores_jobs(): void
    {
        [$agent] = $this->agent();
        $this->printer($agent);
        $this->actingAs($this->owner)->post(route('orders.print', $this->order()));

        $otherStore = Store::factory()->create();
        [, $otherToken] = $this->agent($otherStore);

        $this->assertSame([], $this->withToken($otherToken)->getJson('/api/print-agent/jobs')->json('jobs'));

        $id = PrintJob::query()->sole()->id;
        $this->withToken($otherToken)->postJson("/api/print-agent/jobs/{$id}/result", ['status' => 'printed'])->assertNotFound();
    }

    public function test_tickets_print_automatically_when_the_order_is_accepted(): void
    {
        [$agent] = $this->agent();
        $this->printer($agent, copies: 2);
        $order = $this->order();

        $this->assertSame(0, PrintJob::query()->count());

        $this->actingAs($this->owner)->patch(route('orders.status.update', $order), ['status' => 'accepted']);

        $this->assertSame(2, PrintJob::query()->count());
    }

    public function test_tickets_print_when_placed_if_the_store_chose_so(): void
    {
        $this->store->update(['auto_print' => AutoPrint::WhenPlaced]);
        [$agent] = $this->agent();
        $this->printer($agent);

        $this->order();

        $this->assertSame(1, PrintJob::query()->count());
    }

    public function test_ticket_has_the_order_details_and_fits_58mm(): void
    {
        $order = $this->order();
        $bytes = app(OrderTicket::class)->render($order, 32);
        $text = (string) iconv('CP850', 'UTF-8', $bytes);

        $this->assertStringContainsString('Pizzaria do Zé', $text);
        $this->assertStringContainsString('PEDIDO 1', $text);
        $this->assertStringContainsString('2x X-Burger', $text);
        $this->assertStringContainsString('R$ 50,00', $text);
        $this->assertStringContainsString("\x1DV", $bytes); // cut

        foreach (explode("\n", $text) as $line) {
            $visible = (string) preg_replace('/[\x00-\x1F]./', '', $line);
            $this->assertLessThanOrEqual(34, mb_strlen($visible), "Line too long: {$visible}");
        }
    }

    public function test_settings_page_lists_agents_and_saves_printers(): void
    {
        [$agent] = $this->agent();
        $agent->update(['available_printers' => ['EPSON TM-T20']]);

        $this->actingAs($this->owner)
            ->get(route('store-settings.printing.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('store-settings/Printing')
                ->where('agents.0.availablePrinters.0', 'EPSON TM-T20'));

        $this->actingAs($this->owner)->post(route('store-settings.printing.printers.save'), [
            'print_agent_id' => $agent->id,
            'system_name' => 'EPSON TM-T20',
            'label' => 'Cozinha',
            'paper_width' => 58,
            'copies' => 1,
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Cozinha', Printer::query()->sole()->label);

        $foreignAgent = $this->agent(Store::factory()->create())[0];
        $this->actingAs($this->owner)->post(route('store-settings.printing.printers.save'), [
            'print_agent_id' => $foreignAgent->id,
            'system_name' => 'X',
            'label' => 'X',
            'paper_width' => 80,
            'copies' => 1,
        ])->assertSessionHasErrors('print_agent_id');
    }

    /**
     * @return array{0: PrintAgent, 1: string}
     */
    private function agent(?Store $store = null): array
    {
        $token = 'token-'.uniqid();
        $agent = ($store ?? $this->store)->printAgents()->create([
            'name' => 'Caixa',
            'token_hash' => PrintAgent::hashToken($token),
        ]);

        return [$agent, $token];
    }

    private function printer(PrintAgent $agent, int $copies = 1): Printer
    {
        return $agent->printers()->create([
            'store_id' => $agent->store_id,
            'system_name' => 'EPSON TM-T20',
            'label' => 'Balcão',
            'paper_width' => 80,
            'copies' => $copies,
        ]);
    }

    private function order(): Order
    {
        $product = Product::factory()
            ->for(Category::factory()->for($this->store))
            ->create(['name' => 'X-Burger', 'price_cents' => 2500]);

        $this->post('/pizzaria-ze/orders', [
            'fulfillment' => 'pickup',
            'customer' => ['name' => 'Maria', 'phone' => '11999998888'],
            'payment_method' => 'cash',
            'change_for' => '100',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'notes' => 'sem cebola']],
        ])->assertSessionHasNoErrors();

        return Order::query()->latest('id')->firstOrFail();
    }
}

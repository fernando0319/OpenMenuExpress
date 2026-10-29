<?php

namespace Tests\Feature\Panel;

use App\Contracts\MessagingProvider;
use App\Enums\MessageEvent;
use App\Enums\StoreRole;
use App\Messaging\MessagingException;
use App\Models\Category;
use App\Models\MessageLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    /** @var list<array{phone: string, text: string}> */
    private array $sent = [];

    private bool $failSending = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(MessagingProvider::class, new class($this) implements MessagingProvider
        {
            public function __construct(private readonly OrderMessagesTest $test) {}

            public function sendText(Store $store, string $phone, string $text): void
            {
                $this->test->recordSend($phone, $text);
            }

            public function isConfiguredFor(Store $store): bool
            {
                return true;
            }
        });

        $this->store = Store::factory()->create(['slug' => 'lanche-bom', 'name' => 'Lanche Bom', 'whatsapp_enabled' => true]);
        $this->owner = User::factory()->create();
        $this->store->users()->attach($this->owner, ['role' => StoreRole::Owner->value]);
    }

    public function recordSend(string $phone, string $text): void
    {
        if ($this->failSending) {
            throw new MessagingException('Instância desconectada');
        }

        $this->sent[] = ['phone' => $phone, 'text' => $text];
    }

    public function test_customer_gets_the_order_placed_message_with_variables(): void
    {
        $this->placeOrder();

        $this->assertCount(1, $this->sent);
        $this->assertSame('5511999998888', $this->sent[0]['phone']);
        $this->assertStringContainsString('Olá, Maria!', $this->sent[0]['text']);
        $this->assertStringContainsString('1x X-Burger', $this->sent[0]['text']);
        $this->assertStringContainsString('R$ 25,00', $this->sent[0]['text']);
        $this->assertSame(MessageLog::STATUS_SENT, MessageLog::query()->sole()->status);
    }

    public function test_status_change_uses_the_custom_template(): void
    {
        $this->store->messageTemplates()->create([
            'event' => MessageEvent::OrderAccepted,
            'body' => '{nome}, aceitamos o pedido {pedido} da {loja}!',
        ]);
        $order = $this->placeOrder();

        $this->actingAs($this->owner)->patch(route('orders.status.update', $order), ['status' => 'accepted']);

        $this->assertSame('Maria, aceitamos o pedido 1 da Lanche Bom!', end($this->sent)['text']);
    }

    public function test_disabled_templates_and_disabled_whatsapp_send_nothing(): void
    {
        $this->store->messageTemplates()->create([
            'event' => MessageEvent::OrderPlaced,
            'body' => 'x',
            'is_enabled' => false,
        ]);
        $this->placeOrder();
        $this->assertSame([], $this->sent);

        $this->store->messageTemplates()->delete();
        $this->store->update(['whatsapp_enabled' => false]);
        $this->placeOrder();
        $this->assertSame([], $this->sent);
        $this->assertSame(0, MessageLog::query()->count());
    }

    public function test_a_failed_send_is_logged_and_does_not_break_the_order(): void
    {
        $this->failSending = true;

        $order = $this->placeOrder();

        $this->assertNotNull($order);
        $log = MessageLog::query()->sole();
        $this->assertSame(MessageLog::STATUS_FAILED, $log->status);
        $this->assertSame('Instância desconectada', $log->error);
    }

    public function test_owner_edits_and_resets_templates(): void
    {
        $this->actingAs($this->owner)
            ->get(route('store-settings.messages.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('store-settings/Messages')
                ->has('templates', count(MessageEvent::cases())));

        $this->actingAs($this->owner)
            ->put(route('store-settings.messages.template.update'), [
                'event' => 'order_completed',
                'body' => 'Valeu, {nome}!',
                'is_enabled' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Valeu, {nome}!', $this->store->messageTemplates()->sole()->body);

        $this->actingAs($this->owner)
            ->delete(route('store-settings.messages.template.reset'), ['event' => 'order_completed']);

        $this->assertSame(0, $this->store->messageTemplates()->count());
    }

    private function placeOrder(): Order
    {
        $product = Product::factory()
            ->for(Category::factory()->for($this->store))
            ->create(['name' => 'X-Burger', 'price_cents' => 2500]);

        $this->post('/lanche-bom/orders', [
            'fulfillment' => 'pickup',
            'customer' => ['name' => 'Maria Silva', 'phone' => '11999998888'],
            'payment_method' => 'pix',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        return Order::query()->latest('id')->firstOrFail();
    }
}

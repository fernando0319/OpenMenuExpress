<?php

namespace Tests\Feature\Messaging;

use App\Enums\MessageEvent;
use App\Enums\OrderStatus;
use App\Messaging\OrderMessageComposer;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderMessageComposerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_delivery_shows_the_address_and_the_fee_apart_from_the_total(): void
    {
        $store = Store::factory()->create(['name' => 'Pizzaria do Zé']);
        $order = $this->order($store, [
            'fulfillment' => 'delivery',
            'delivery_street' => 'Rua das Flores',
            'delivery_number' => '120',
            'delivery_complement' => 'Casa 2',
            'delivery_district' => 'Jardins',
            'delivery_city' => 'São Paulo',
            'delivery_reference' => 'Portão azul',
            'subtotal_cents' => 4000,
            'delivery_fee_cents' => 700,
            'discount_cents' => 500,
            'coupon_code' => 'BEMVINDO',
            'total_cents' => 4200,
        ]);

        $text = app(OrderMessageComposer::class)->render(MessageEvent::OrderPlaced->defaultBody(), $order);

        $this->assertStringContainsString(
            "Subtotal: R$ 40,00\nTaxa de entrega: R$ 7,00\nDesconto (BEMVINDO): - R$ 5,00\nTotal: R$ 42,00\nPagamento: Dinheiro",
            $text,
        );
        $this->assertStringContainsString("Entrega em: Rua das Flores, 120 (Casa 2) - Jardins, São Paulo\nReferência: Portão azul", $text);
        $this->assertSame('R$ 7,00 | Rua das Flores, 120 (Casa 2) - Jardins, São Paulo', app(OrderMessageComposer::class)->render('{taxa_entrega} | {endereco}', $order));
    }

    public function test_a_pickup_has_no_fee_nor_address_and_no_empty_lines(): void
    {
        $store = Store::factory()->create(['address_street' => 'Rua XV', 'address_number' => '450', 'address_district' => 'Centro']);
        $order = $this->order($store, ['fulfillment' => 'pickup', 'subtotal_cents' => 3000, 'total_cents' => 3000]);

        $text = app(OrderMessageComposer::class)->render(MessageEvent::OrderPlaced->defaultBody(), $order);

        $this->assertStringContainsString("Total: R$ 30,00\nPagamento: Dinheiro\n\nRetirada na loja: Rua XV, 450 - Centro", $text);
        $this->assertStringNotContainsString('Taxa de entrega', $text);
        $this->assertStringNotContainsString("\n\n\n", $text);
        $this->assertSame('Sem taxa: .', app(OrderMessageComposer::class)->render('Sem taxa: {taxa_entrega}{endereco}.', $order));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(Store $store, array $attributes): Order
    {
        return Order::query()->create([
            'store_id' => $store->id,
            'number' => 1,
            'customer_name' => 'Maria Oliveira',
            'channel' => 'online',
            'status' => OrderStatus::Pending,
            'discount_cents' => 0,
            'delivery_fee_cents' => 0,
            'payment_method' => 'cash',
            'placed_at' => now(),
            ...$attributes,
        ]);
    }
}

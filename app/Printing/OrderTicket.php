<?php

namespace App\Printing;

use App\Enums\Fulfillment;
use App\Enums\PrintFont;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use App\Services\Catalog\PizzaMenu;
use App\Support\Money;
use App\Support\SlotLabel;
use Carbon\CarbonImmutable;

/**
 * The order ticket for the kitchen/counter, built from the order data (not from a saved text).
 */
final class OrderTicket
{
    public function render(Order $order, int $columns, PrintFont $font = PrintFont::Normal): string
    {
        $store = $order->store;
        $order->loadMissing('items.options');
        $now = CarbonImmutable::now();

        $doc = (new EscPos($columns, $font))
            ->align('center')
            ->bold()->line($store->name)->bold(false)
            ->size(2)->bold()->line("PEDIDO {$order->number}")->bold(false)->size(1)
            ->line($order->placed_at->setTimezone($store->timezone)->format('d/m/Y H:i'));

        if ($order->scheduled_for !== null) {
            $doc->bold()->line('*** AGENDADO: '.SlotLabel::describe($order->scheduled_for, $store->timezone, $now).' ***')->bold(false);
        }

        $doc->align('left')->separator()
            ->bold()->line(mb_strtoupper($order->fulfillment->label()))->bold(false)
            ->wrapped("Cliente: {$order->customer_name}");

        if ($order->customer_phone !== null) {
            $doc->line('Tel: '.$this->phone($order->customer_phone));
        }

        if ($order->fulfillment === Fulfillment::Delivery && $order->delivery_street !== null) {
            $doc->wrapped(trim("{$order->delivery_street}, {$order->delivery_number} {$order->delivery_complement}"));

            if ($order->delivery_district !== null) {
                $doc->line($order->delivery_district);
            }

            if ($order->delivery_reference !== null) {
                $doc->wrapped("Ref.: {$order->delivery_reference}");
            }
        }

        $doc->separator();

        foreach ($order->items as $item) {
            $this->item($doc, $item);
        }

        $doc->separator()
            ->columnsLine('Subtotal', $this->money($order->subtotal_cents));

        if ($order->delivery_fee_cents > 0) {
            $doc->columnsLine('Entrega', $this->money($order->delivery_fee_cents));
        }

        if ($order->discount_cents > 0) {
            $doc->columnsLine('Desconto', '-'.$this->money($order->discount_cents));
        }

        $doc->bold()->columnsLine('TOTAL', $this->money($order->total_cents))->bold(false)
            ->wrapped('Pagamento: '.$order->paymentLabel());

        if ($order->change_for_cents !== null) {
            $doc->columnsLine('Troco para', $this->money($order->change_for_cents))
                ->bold()->columnsLine('Levar de troco', $this->money($order->change_for_cents - $order->total_cents))->bold(false);
        }

        if ($order->notes !== null && $order->notes !== '') {
            $doc->separator()->bold()->line('OBSERVACOES:')->bold(false)->wrapped($order->notes);
        }

        return $doc->feed(3)->cut()->bytes();
    }

    private function item(EscPos $doc, OrderItem $item): void
    {
        $doc->bold()->columnsLine("{$item->quantity}x {$item->product_name}", $this->money($item->total_cents))->bold(false);

        $byGroup = $item->options->groupBy('group_name');

        foreach ($byGroup as $group => $options) {
            // Pizza builder flavors: "½ Calabresa / ½ Portuguesa", the way the pizza maker reads it.
            if ($group === PizzaMenu::FLAVORS_GROUP && $options->every(fn (OrderItemOption $option) => $option->option_id === null)) {
                $doc->bold()->wrapped($options->pluck('option_name')->implode(' / '), '   ')->bold(false);

                continue;
            }

            $names = $options
                ->map(fn (OrderItemOption $option) => $option->quantity > 1 ? "{$option->quantity}x {$option->option_name}" : $option->option_name)
                ->implode(', ');

            $doc->wrapped("{$group}: {$names}", '   ');
        }

        if ($item->notes !== null && $item->notes !== '') {
            $doc->bold()->wrapped("Obs: {$item->notes}", '   ')->bold(false);
        }
    }

    private function money(int $cents): string
    {
        return 'R$ '.Money::toInput($cents);
    }

    private function phone(string $digits): string
    {
        $local = (string) preg_replace('/^55/', '', $digits);

        return strlen($local) >= 10
            ? '('.substr($local, 0, 2).') '.substr($local, 2, -4).'-'.substr($local, -4)
            : $digits;
    }
}

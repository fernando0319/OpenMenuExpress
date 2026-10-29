<?php

namespace App\Printing;

use App\Enums\PrintFont;
use App\Models\Order;
use App\Models\OrderChange;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * What changed in an order, for the kitchen: only what to add and what to take out,
 * so nobody prepares the whole order twice.
 */
final class OrderChangeTicket
{
    public function render(Order $order, OrderChange $change, int $columns, PrintFont $font = PrintFont::Normal): string
    {
        $store = $order->store;
        $at = ($change->created_at ?? CarbonImmutable::now())->setTimezone($store->timezone);

        $doc = (new EscPos($columns, $font))
            ->align('center')
            ->bold()->line($store->name)->bold(false)
            ->size(2)->bold()->line("PEDIDO {$order->number}")->line('ALTERADO')->bold(false)->size(1)
            ->line($at->format('d/m/Y H:i'))
            ->align('left')->separator()
            ->wrapped($order->fulfillment->label().' · '.$order->customer_name)
            ->separator();

        foreach (['add' => 'ACRESCENTAR', 'remove' => 'RETIRAR', 'note' => 'OBSERVACAO ALTERADA'] as $kind => $title) {
            $items = array_values(array_filter($change->items, fn (array $item) => $item['kind'] === $kind));

            if ($items === []) {
                continue;
            }

            $doc->size(1)->bold()->line("{$title}:")->bold(false);

            foreach ($items as $item) {
                $doc->bold()->wrapped(($kind === 'remove' ? '- ' : '+ ')."{$item['quantity']}x {$item['name']}")->bold(false);

                if ($item['options'] !== []) {
                    $doc->wrapped(implode(', ', $item['options']), '   ');
                }

                if ($item['notes'] !== null && $item['notes'] !== '') {
                    $doc->bold()->wrapped("Obs: {$item['notes']}", '   ')->bold(false);
                }
            }

            $doc->line();
        }

        foreach ($change->fields as $field) {
            $doc->wrapped($field);
        }

        $doc->separator()
            ->bold()->columnsLine('NOVO TOTAL', 'R$ '.Money::toInput($order->total_cents))->bold(false);

        if ($change->reason !== null) {
            $doc->wrapped("Motivo: {$change->reason}");
        }

        return $doc->feed(3)->cut()->bytes();
    }
}

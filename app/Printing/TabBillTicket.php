<?php

namespace App\Printing;

use App\Enums\OrderStatus;
use App\Enums\PrintFont;
use App\Models\Tab;
use App\Services\Tables\TabBill;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The check handed to the guests before paying (conferência de conta).
 */
final class TabBillTicket
{
    public function render(Tab $tab, TabBill $bill, int $columns, PrintFont $font = PrintFont::Normal): string
    {
        $store = $tab->store;
        $orders = $tab->orders()
            ->where('status', '!=', OrderStatus::Cancelled)
            ->with('items.options')
            ->orderBy('id')
            ->get();

        $doc = (new EscPos($columns, $font))
            ->align('center')
            ->bold()->line($store->name)->bold(false)
            ->size(2)->bold()->line(mb_strtoupper($tab->tablesLabel()))->bold(false)->size(1)
            ->line('CONFERÊNCIA DE CONTA')
            ->line(CarbonImmutable::now()->setTimezone($store->timezone)->format('d/m/Y H:i'))
            ->align('left')->separator();

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $doc->columnsLine("{$item->quantity}x {$item->product_name}", $this->money($item->total_cents));

                $options = $item->options
                    ->map(fn ($option) => $option->quantity > 1 ? "{$option->quantity}x {$option->option_name}" : $option->option_name)
                    ->implode(', ');

                if ($options !== '') {
                    $doc->wrapped($options, '   ');
                }
            }
        }

        $doc->separator()->columnsLine('Consumo', $this->money($bill->subtotalCents));

        if ($bill->serviceFeeCents > 0) {
            $doc->columnsLine("Serviço ({$bill->serviceFeePercent}%)", $this->money($bill->serviceFeeCents));
        }

        if ($bill->discountCents > 0) {
            $doc->columnsLine('Desconto', '-'.$this->money($bill->discountCents));
        }

        $doc->bold()->columnsLine('TOTAL', $this->money($bill->totalCents()))->bold(false);

        if ($tab->people !== null && $tab->people > 1) {
            $doc->columnsLine("Por pessoa ({$tab->people})", $this->money((int) ceil($bill->totalCents() / $tab->people)));
        }

        if ($bill->serviceFeeCents > 0) {
            $doc->separator()->align('center')->wrapped('A taxa de serviço é opcional.');
        }

        return $doc->feed(3)->cut()->bytes();
    }

    private function money(int $cents): string
    {
        return 'R$ '.Money::toInput($cents);
    }
}

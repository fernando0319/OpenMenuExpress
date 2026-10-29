<?php

namespace App\Printing;

use App\Enums\PrintFont;
use App\Models\CashSession;
use App\Services\Cash\CashSummary;
use App\Support\Money;

/**
 * Cash session report for the thermal printer: totals per method and the drawer count.
 */
final class CashReportTicket
{
    public function render(CashSession $session, int $columns, PrintFont $font = PrintFont::Normal): string
    {
        $store = $session->store;
        $session->loadMissing(['openedBy', 'closedBy']);
        $summary = CashSummary::of($session);
        $time = fn ($at) => $at?->setTimezone($store->timezone)->format('d/m/Y H:i') ?? '-';

        $doc = (new EscPos($columns, $font))
            ->align('center')
            ->bold()->line($store->name)->bold(false)
            ->size(2)->bold()->line($session->isOpen() ? 'PARCIAL DO CAIXA' : 'FECHAMENTO')->bold(false)->size(1)
            ->align('left')->separator()
            ->columnsLine('Abertura', $time($session->opened_at))
            ->wrapped('Por: '.($session->openedBy->name ?? '-'));

        if (! $session->isOpen()) {
            $doc->columnsLine('Fechamento', $time($session->closed_at))
                ->wrapped('Por: '.($session->closedBy->name ?? '-'));
        }

        $doc->separator()->bold()->line('VENDAS POR FORMA')->bold(false);

        foreach ($summary->byMethod() as $row) {
            if ($row['cents'] !== 0) {
                $doc->columnsLine($row['label'], $this->money($row['cents']));
            }
        }

        $doc->bold()->columnsLine('Total líquido', $this->money($summary->salesCents - $summary->refundsCents))->bold(false)
            ->columnsLine('Pedidos', (string) $summary->ordersCount);

        if ($summary->refundsCents > 0) {
            $doc->columnsLine('Estornos', '-'.$this->money($summary->refundsCents));
        }

        $doc->separator()->bold()->line('DINHEIRO NA GAVETA')->bold(false)
            ->columnsLine('Fundo inicial', $this->money($summary->openingCents))
            ->columnsLine('Vendas em dinheiro', $this->money($summary->netByMethod['cash'] ?? 0))
            ->columnsLine('Suprimentos', $this->money($summary->suppliesCents))
            ->columnsLine('Sangrias', '-'.$this->money($summary->withdrawalsCents))
            ->bold()->columnsLine('Esperado', $this->money($session->expected_cash_cents ?? $summary->expectedCashCents()))->bold(false);

        if ($session->counted_cash_cents !== null) {
            $difference = (int) $session->differenceCents();

            $doc->columnsLine('Contado', $this->money($session->counted_cash_cents))
                ->bold()->columnsLine($difference < 0 ? 'Falta' : ($difference > 0 ? 'Sobra' : 'Diferença'), $this->money(abs($difference)))->bold(false);
        }

        if ($session->notes !== null && $session->notes !== '') {
            $doc->separator()->wrapped("Obs.: {$session->notes}");
        }

        return $doc->feed(3)->cut()->bytes();
    }

    private function money(int $cents): string
    {
        return ($cents < 0 ? '-' : '').'R$ '.Money::toInput(abs($cents));
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\Asset;
use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Models\LedgerEntry;
use App\Models\Store;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Everything the store received (and gave back) in a month, in each asset, with the value in
 * reais at the time. Part of the store's records, so it does not depend on the finance module.
 */
class LedgerController extends Controller
{
    use InteractsWithCurrentStore;

    public function index(Request $request): Response
    {
        $store = $this->currentStore();
        [$start, $end] = $this->month($request, $store);

        $totalsByAsset = $this->entries($store, $start, $end)
            ->selectRaw('asset, SUM(units) as units, SUM(brl_cents) as brl_cents, COUNT(*) as entries, SUM(CASE WHEN units IS NULL THEN 1 ELSE 0 END) as unknown')
            ->groupBy('asset')
            ->get()
            ->map(function (LedgerEntry $row): array {
                $asset = $row->asset;

                return [
                    'asset' => $asset->value,
                    'label' => $asset->label(),
                    'quantity' => $asset === Asset::Brl ? null : $asset->format((int) $row->getAttribute('units')),
                    'brlCents' => (int) $row->getAttribute('brl_cents'),
                    'unknownQuantity' => (int) $row->getAttribute('unknown') > 0,
                ];
            })
            ->values();

        $totalsByMethod = $this->entries($store, $start, $end)
            ->selectRaw('method, SUM(CASE WHEN brl_cents > 0 THEN brl_cents ELSE 0 END) as received, SUM(CASE WHEN brl_cents < 0 THEN -brl_cents ELSE 0 END) as returned')
            ->groupBy('method')
            ->get()
            ->map(fn (LedgerEntry $row) => [
                'method' => $row->methodLabel(),
                'receivedCents' => (int) $row->getAttribute('received'),
                'returnedCents' => (int) $row->getAttribute('returned'),
            ])
            ->sortByDesc('receivedCents')
            ->values();

        $entries = $this->entries($store, $start, $end)
            ->with('order:id,number')
            ->latest('occurred_at')
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (LedgerEntry $entry) => [
                'id' => $entry->id,
                'occurredAt' => $entry->occurred_at->toIso8601String(),
                'kind' => $entry->kind,
                'method' => $entry->methodLabel(),
                'asset' => $entry->asset->value,
                'quantity' => $entry->asset === Asset::Brl || $entry->units === null ? null : $entry->asset->format($entry->units),
                'rate' => $entry->rate,
                'brlCents' => $entry->brl_cents,
                'orderId' => $entry->order_id,
                'orderNumber' => $entry->order?->number,
                'description' => $entry->description,
            ]);

        return Inertia::render('ledger/Index', [
            'month' => $start->format('Y-m'),
            'totalsByAsset' => $totalsByAsset,
            'totalsByMethod' => $totalsByMethod,
            'entries' => $entries,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $store = $this->currentStore();
        [$start, $end] = $this->month($request, $store);
        $entries = $this->entries($store, $start, $end)->with('order:id,number')->orderBy('occurred_at')->orderBy('id');
        $filename = 'recebimentos-'.$store->slug.'-'.$start->format('Y-m').'.csv';

        return response()->streamDownload(function () use ($entries, $store): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM, so Excel shows accents
            fputcsv($out, ['Data', 'Tipo', 'Forma', 'Ativo', 'Quantidade', 'Cotação (R$ por unidade)', 'Valor (R$)', 'Pedido', 'Descrição'], ';', '"', '');

            foreach ($entries->lazyById(500) as $entry) {
                fputcsv($out, [
                    $entry->occurred_at->setTimezone($store->timezone)->format('d/m/Y H:i'),
                    $entry->kind === LedgerEntry::IN ? 'Entrada' : 'Saída',
                    $entry->methodLabel(),
                    $entry->asset->value,
                    $entry->units === null ? '' : str_replace('.', ',', $entry->asset->format($entry->units)),
                    $entry->rate === null ? '' : str_replace('.', ',', $entry->rate),
                    ($entry->brl_cents < 0 ? '-' : '').Money::toInput(abs($entry->brl_cents)),
                    $entry->order->number ?? '',
                    $entry->description ?? '',
                ], ';', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The month asked for ("2026-10"), in the store's timezone; this month by default.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function month(Request $request, Store $store): array
    {
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? null;
        $start = is_string($month)
            ? CarbonImmutable::createFromFormat('Y-m-d', $month.'-01', $store->timezone)
            : CarbonImmutable::now($store->timezone);
        $start = ($start ?: CarbonImmutable::now($store->timezone))->startOfMonth();

        return [$start, $start->endOfMonth()];
    }

    /**
     * @return Builder<LedgerEntry>
     */
    private function entries(Store $store, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return LedgerEntry::query()
            ->where('store_id', $store->id)
            ->whereBetween('occurred_at', [$start->utc(), $end->utc()]);
    }
}

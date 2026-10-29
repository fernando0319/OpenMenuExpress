<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\StoreReport;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sales reports for owners and managers.
 */
class ReportController extends Controller
{
    use InteractsWithCurrentStore;

    public function index(Request $request): Response
    {
        $period = $this->period($request);

        return Inertia::render('reports/Index', [
            'period' => [
                'preset' => $period->preset,
                'from' => $period->startLocal->format('Y-m-d'),
                'to' => $period->endLocal->format('Y-m-d'),
                'label' => $period->label(),
                'previousLabel' => $period->previous()->label(),
            ],
            'report' => (new StoreReport($this->currentStore(), $period))->toArray(),
        ]);
    }

    /**
     * Orders of the period as a spreadsheet (CSV that opens in Excel with accents).
     */
    public function export(Request $request): StreamedResponse
    {
        $store = $this->currentStore();
        $period = $this->period($request);
        $report = new StoreReport($store, $period);
        $filename = 'pedidos-'.$store->slug.'-'.$period->startLocal->format('Y-m-d').'-a-'.$period->endLocal->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($report, $store): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM, so Excel shows accents
            fputcsv($out, ['Número', 'Data', 'Origem', 'Entrega', 'Mesa', 'Status', 'Cliente', 'Telefone', 'Subtotal', 'Taxa de entrega', 'Desconto', 'Total', 'Pagamento', 'Cupom'], ';', '"', '');

            foreach ($report->ordersForExport() as $order) {
                fputcsv($out, [
                    $order->number,
                    $order->placed_at->setTimezone($store->timezone)->format('d/m/Y H:i'),
                    $order->channel->label(),
                    $order->fulfillment->label(),
                    $order->tab?->table->name ?? '',
                    $order->status->label(),
                    $order->customer_name,
                    $order->customer_phone ?? '',
                    Money::toInput($order->subtotal_cents),
                    Money::toInput($order->delivery_fee_cents),
                    Money::toInput($order->discount_cents),
                    Money::toInput($order->total_cents),
                    $order->paymentLabel(),
                    $order->coupon_code ?? '',
                ], ';', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function period(Request $request): ReportPeriod
    {
        $data = $request->validate([
            'period' => ['nullable', Rule::in(ReportPeriod::PRESETS)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return ReportPeriod::from($data['period'] ?? '7d', $data['from'] ?? null, $data['to'] ?? null, $this->currentStore()->timezone);
    }
}

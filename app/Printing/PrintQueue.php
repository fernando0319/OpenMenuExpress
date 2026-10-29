<?php

namespace App\Printing;

use App\Models\CashSession;
use App\Models\Order;
use App\Models\OrderChange;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\Store;
use App\Models\Tab;
use App\Services\Tables\TabBill;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Puts tickets in the queue and hands them to agents.
 */
final class PrintQueue
{
    public function __construct(
        private readonly OrderTicket $ticket,
        private readonly CashReportTicket $cashReport,
        private readonly TabBillTicket $tabBill,
        private readonly OrderChangeTicket $changeTicket,
    ) {}

    /**
     * The "order changed" ticket, one copy on every active printer (same places as the order).
     *
     * @return int Jobs created.
     */
    public function queueOrderChange(Order $order, OrderChange $change): int
    {
        $created = 0;

        foreach ($order->store->printers()->where('is_active', true)->get() as $printer) {
            $printer->jobs()->create([
                'store_id' => $order->store_id,
                'order_id' => $order->id,
                'kind' => 'order',
                'payload' => base64_encode($this->changeTicket->render($order, $change, $printer->columns(), $printer->font)),
                'status' => PrintJob::STATUS_PENDING,
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * One copy of the guests' check on the store's first active printer.
     *
     * @return int Jobs created.
     */
    public function queueTabBill(Tab $tab, TabBill $bill): int
    {
        $printer = $tab->store->printers()->where('is_active', true)->orderBy('id')->first();

        if ($printer === null) {
            return 0;
        }

        $printer->jobs()->create([
            'store_id' => $tab->store_id,
            'kind' => 'bill',
            'payload' => base64_encode($this->tabBill->render($tab, $bill, $printer->columns(), $printer->font)),
            'status' => PrintJob::STATUS_PENDING,
        ]);

        return 1;
    }

    /**
     * One copy on the store's first active printer: the report is for the manager, not the kitchen.
     *
     * @return int Jobs created.
     */
    public function queueCashReport(CashSession $session): int
    {
        $printer = $session->store->printers()->where('is_active', true)->orderBy('id')->first();

        if ($printer === null) {
            return 0;
        }

        $printer->jobs()->create([
            'store_id' => $session->store_id,
            'kind' => 'cash',
            'payload' => base64_encode($this->cashReport->render($session, $printer->columns(), $printer->font)),
            'status' => PrintJob::STATUS_PENDING,
        ]);

        return 1;
    }

    /**
     * One job per copy on every active printer of the store (or only the given printer).
     *
     * @return int Jobs created.
     */
    public function queueOrder(Order $order, ?Printer $only = null): int
    {
        $printers = $only !== null
            ? collect([$only])
            : $order->store->printers()->where('is_active', true)->get();

        $created = 0;

        foreach ($printers as $printer) {
            $payload = base64_encode($this->ticket->render($order, $printer->columns(), $printer->font));

            for ($copy = 0; $copy < max(1, $printer->copies); $copy++) {
                $printer->jobs()->create([
                    'store_id' => $order->store_id,
                    'order_id' => $order->id,
                    'kind' => 'order',
                    'payload' => $payload,
                    'status' => PrintJob::STATUS_PENDING,
                ]);
                $created++;
            }
        }

        return $created;
    }

    public function queueTest(Printer $printer): void
    {
        $store = $printer->agent->store;

        $doc = (new EscPos($printer->columns(), $printer->font))
            ->align('center')
            ->size(2)->bold()->line('TESTE')->bold(false)->size(1)
            ->line($store->name)
            ->line(CarbonImmutable::now()->setTimezone($store->timezone)->format('d/m/Y H:i'))
            ->separator()
            ->align('left')
            ->line("Impressora: {$printer->label}")
            ->line("Papel: {$printer->paper_width} mm")
            ->line("Letra: {$printer->font->label()}")
            ->line('Acentuação: ção ã é ü ô À')
            ->columnsLine('Item de exemplo', 'R$ 12,50')
            ->separator()
            ->align('center')->line('Se você leu isto, está tudo certo!')
            ->feed(3)->cut();

        $printer->jobs()->create([
            'store_id' => $printer->store_id,
            'kind' => 'test',
            'payload' => base64_encode($doc->bytes()),
            'status' => PrintJob::STATUS_PENDING,
        ]);
    }

    /**
     * Hands the agent its next jobs: pending ones, plus those handed out earlier and never confirmed.
     *
     * @param  list<int>  $printerIds
     * @return list<PrintJob>
     */
    public function claim(array $printerIds, int $limit = 5): array
    {
        if ($printerIds === []) {
            return [];
        }

        return DB::transaction(function () use ($printerIds, $limit): array {
            $staleBefore = now()->subSeconds(PrintJob::RETRY_AFTER_SECONDS);

            $jobs = PrintJob::query()
                ->whereIn('printer_id', $printerIds)
                ->where(fn ($query) => $query
                    ->where('status', PrintJob::STATUS_PENDING)
                    ->orWhere(fn ($query) => $query
                        ->where('status', PrintJob::STATUS_SENT)
                        ->where('claimed_at', '<', $staleBefore)))
                ->where('attempts', '<', PrintJob::MAX_ATTEMPTS)
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            foreach ($jobs as $job) {
                $job->update([
                    'status' => PrintJob::STATUS_SENT,
                    'attempts' => $job->attempts + 1,
                    'claimed_at' => now(),
                ]);
            }

            return array_values($jobs->all());
        });
    }

    public static function storeHasPrinters(Store $store): bool
    {
        return $store->printers()->where('is_active', true)->exists();
    }
}

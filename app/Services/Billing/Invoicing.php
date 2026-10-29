<?php

namespace App\Services\Billing;

use App\Models\AdminAuditLog;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Closes the months of stores on plans charged by use, and records what the platform received.
 * The plan's terms are copied into the invoice, so later plan changes do not alter past months.
 */
final class Invoicing
{
    /**
     * The invoice of that month (store timezone), created on first need. Sales before $from
     * (end of the trial) are not charged. Idempotent.
     */
    public static function close(Store $store, Plan $plan, CarbonImmutable $monthStart, ?CarbonImmutable $from = null): Invoice
    {
        $period = $monthStart->setTimezone($store->timezone)->startOfMonth();
        $existing = $store->invoices()->where('period_start', $period->format('Y-m-d'))->first();

        if ($existing instanceof Invoice) {
            return $existing;
        }

        $sales = MonthlySales::online($store, $period, $from);

        try {
            return $store->invoices()->create([
                'plan_id' => $plan->id,
                'period_start' => $period->format('Y-m-d'),
                'sales_cents' => $sales,
                'free_up_to_cents' => $plan->free_up_to_cents,
                'percent_bps' => (int) $plan->percent_bps,
                'percent_base' => $plan->percent_base,
                'fixed_cents' => $plan->price_cents,
                'fee_min_cents' => $plan->fee_min_cents,
                'fee_max_cents' => $plan->fee_max_cents,
                'amount_cents' => $plan->usageFee($sales),
                'status' => Invoice::OPEN,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two requests closed the same month at once: keep the first.
            return $store->invoices()->where('period_start', $period->format('Y-m-d'))->firstOrFail();
        }
    }

    /**
     * Payment received outside the system (PIX, transfer...), or the month forgiven.
     */
    public function settle(Invoice $invoice, string $status, User $by, ?string $note, ?string $ip = null): void
    {
        $invoice->update([
            'status' => $status,
            'settled_at' => now(),
            'settled_by' => $by->id,
            'note' => $note,
        ]);

        $this->log($invoice, $by, $status === Invoice::PAID ? 'billing.invoice_paid' : 'billing.invoice_waived', [
            'period' => $invoice->period_start->format('Y-m'),
            'amount_cents' => $invoice->amount_cents,
            'note' => $note,
        ], $ip);
    }

    public function reopen(Invoice $invoice, User $by, ?string $ip = null): void
    {
        $invoice->update(['status' => Invoice::OPEN, 'settled_at' => null, 'settled_by' => null]);

        $this->log($invoice, $by, 'billing.invoice_reopened', ['period' => $invoice->period_start->format('Y-m')], $ip);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function log(Invoice $invoice, User $admin, string $action, array $details, ?string $ip): void
    {
        AdminAuditLog::query()->create([
            'user_id' => $admin->id,
            'store_id' => $invoice->store_id,
            'action' => $action,
            'details' => $details,
            'ip' => $ip,
        ]);
    }
}

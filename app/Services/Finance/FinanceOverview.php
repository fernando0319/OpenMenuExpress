<?php

namespace App\Services\Finance;

use App\Enums\FinanceType;
use App\Models\FinanceInstallment;
use App\Models\Store;
use App\Services\Billing\MonthlySales;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A store's month in money: what came in and went out (by payment date), what is still due,
 * what is late, and where the money went by category.
 */
final class FinanceOverview
{
    private readonly CarbonImmutable $today;

    public function __construct(
        private readonly Store $store,
        /** First day of the month, local. */
        private readonly CarbonImmutable $month,
        ?CarbonImmutable $now = null,
    ) {
        $this->today = ($now ?? CarbonImmutable::now())->setTimezone($store->timezone)->startOfDay();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $receivedCents = $this->paidInMonth(FinanceType::Receivable);
        $paidCents = $this->paidInMonth(FinanceType::Payable);
        $salesCents = MonthlySales::of($this->store, $this->month);

        return [
            'salesCents' => $salesCents,
            'receivedCents' => $receivedCents,
            'paidCents' => $paidCents,
            'resultCents' => $salesCents + $receivedCents - $paidCents,
            'toReceiveCents' => $this->openDueInMonth(FinanceType::Receivable),
            'toPayCents' => $this->openDueInMonth(FinanceType::Payable),
            'overdue' => [
                'payable' => $this->overdue(FinanceType::Payable),
                'receivable' => $this->overdue(FinanceType::Receivable),
            ],
            'expensesByCategory' => $this->expensesByCategory(),
        ];
    }

    /**
     * @return array{count: int, cents: int}
     */
    public function overdue(FinanceType $type): array
    {
        $row = $this->open($type)
            ->where('due_on', '<', $this->today->format('Y-m-d'))
            ->selectRaw('count(*) as quantity, coalesce(sum(amount_cents), 0) as cents')
            ->toBase()
            ->first();

        return ['count' => (int) ($row->quantity ?? 0), 'cents' => (int) ($row->cents ?? 0)];
    }

    /**
     * @return array{count: int, cents: int}
     */
    public function dueToday(FinanceType $type): array
    {
        $row = $this->open($type)
            ->where('due_on', $this->today->format('Y-m-d'))
            ->selectRaw('count(*) as quantity, coalesce(sum(amount_cents), 0) as cents')
            ->toBase()
            ->first();

        return ['count' => (int) ($row->quantity ?? 0), 'cents' => (int) ($row->cents ?? 0)];
    }

    /**
     * @return array{count: int, cents: int}
     */
    public function dueNextDays(FinanceType $type, int $days): array
    {
        $row = $this->open($type)
            ->where('due_on', '>', $this->today->format('Y-m-d'))
            ->where('due_on', '<=', $this->today->addDays($days)->format('Y-m-d'))
            ->selectRaw('count(*) as quantity, coalesce(sum(amount_cents), 0) as cents')
            ->toBase()
            ->first();

        return ['count' => (int) ($row->quantity ?? 0), 'cents' => (int) ($row->cents ?? 0)];
    }

    public function paidInMonth(FinanceType $type): int
    {
        return (int) $this->ofType($type)
            ->whereBetween('paid_on', [$this->month->format('Y-m-d'), $this->month->endOfMonth()->format('Y-m-d')])
            ->sum('paid_cents');
    }

    private function openDueInMonth(FinanceType $type): int
    {
        return (int) $this->open($type)
            ->whereBetween('due_on', [$this->month->format('Y-m-d'), $this->month->endOfMonth()->format('Y-m-d')])
            ->sum('amount_cents');
    }

    /**
     * @return list<array{name: string, cents: int}>
     */
    private function expensesByCategory(): array
    {
        return array_values($this->ofType(FinanceType::Payable)
            ->join('finance_titles', 'finance_titles.id', '=', 'finance_installments.title_id')
            ->leftJoin('finance_categories', 'finance_categories.id', '=', 'finance_titles.category_id')
            ->whereBetween('finance_installments.paid_on', [$this->month->format('Y-m-d'), $this->month->endOfMonth()->format('Y-m-d')])
            ->groupBy('finance_categories.name')
            ->selectRaw('finance_categories.name as name, sum(finance_installments.paid_cents) as cents')
            ->orderByDesc('cents')
            ->toBase()
            ->get()
            ->map(fn ($row) => ['name' => (string) ($row->name ?? 'Sem categoria'), 'cents' => (int) $row->cents])
            ->all());
    }

    /**
     * @return Builder<FinanceInstallment>
     */
    private function ofType(FinanceType $type): Builder
    {
        return FinanceInstallment::query()
            ->where('finance_installments.store_id', $this->store->id)
            ->whereHas('title', fn (Builder $title) => $title->where('type', $type->value));
    }

    /**
     * @return Builder<FinanceInstallment>
     */
    private function open(FinanceType $type): Builder
    {
        return $this->ofType($type)->open();
    }
}

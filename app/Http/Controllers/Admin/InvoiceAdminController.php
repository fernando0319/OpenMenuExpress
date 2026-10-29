<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\Invoicing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Monthly invoices of plans charged by use: who owes what, and recording payments received
 * outside the system.
 */
class InvoiceAdminController extends Controller
{
    public function __construct(private readonly Invoicing $invoicing) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['due', 'paid', 'waived', 'all'])],
            'store' => ['nullable', 'integer'],
        ]);
        $status = $filters['status'] ?? 'due';

        $query = Invoice::query()
            ->with('store:id,name,slug,timezone')
            ->when(isset($filters['store']), fn (Builder $query) => $query->where('store_id', $filters['store']))
            ->when($status === 'due', fn (Builder $query) => $query->where('status', Invoice::OPEN)->where('amount_cents', '>', 0))
            ->when(in_array($status, ['paid', 'waived'], true), fn (Builder $query) => $query->where('status', $status === 'paid' ? Invoice::PAID : Invoice::WAIVED));

        $dueTotal = (int) (clone $query)->where('status', Invoice::OPEN)->sum('amount_cents');
        $now = now();

        return Inertia::render('admin/Invoices', [
            'invoices' => $query->orderByDesc('period_start')->orderBy('store_id')->paginate(50)->withQueryString()->through(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'storeId' => $invoice->store_id,
                'storeName' => $invoice->store->name,
                'period' => $invoice->periodLabel(),
                'salesCents' => $invoice->sales_cents,
                'amountCents' => $invoice->amount_cents,
                'status' => $invoice->status,
                'statusLabel' => $invoice->statusLabel(),
                'overdue' => $invoice->isDue() && $invoice->dueAt()->addDays((int) config('billing.grace_days'))->lessThan($now),
                'dueAt' => $invoice->dueAt()->toIso8601String(),
                'note' => $invoice->note,
                'settledAt' => $invoice->settled_at?->toIso8601String(),
            ]),
            'dueTotalCents' => $dueTotal,
            'filters' => ['status' => $status, 'store' => $filters['store'] ?? null],
            'storeName' => isset($filters['store']) ? Invoice::query()->where('store_id', $filters['store'])->with('store:id,name')->first()?->store->name : null,
        ]);
    }

    public function settle(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([Invoice::PAID, Invoice::WAIVED])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->invoicing->settle($invoice, $data['status'], $this->admin($request), $data['note'] ?? null, $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => $data['status'] === Invoice::PAID
            ? "Fatura de {$invoice->periodLabel()} marcada como paga."
            : "Fatura de {$invoice->periodLabel()} dispensada."]);

        return back();
    }

    public function reopen(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->invoicing->reopen($invoice, $this->admin($request), $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Fatura de {$invoice->periodLabel()} reaberta."]);

        return back();
    }

    private function admin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_super_admin === true, 403);

        return $user;
    }
}

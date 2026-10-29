<?php

namespace App\Http\Controllers\Finance;

use App\Enums\FinancePaymentMethod;
use App\Enums\FinanceType;
use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\FinanceTitleRequest;
use App\Models\FinanceInstallment;
use App\Models\FinanceTitle;
use App\Models\User;
use App\Services\Cash\CashRegister;
use App\Services\Finance\FinanceOverview;
use App\Services\Finance\FinancePresenter;
use App\Services\Finance\FinanceService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payables and receivables: the list of installments, and the title form.
 */
class TitleController extends Controller
{
    use InteractsWithCurrentStore;

    public const STATUSES = ['open', 'overdue', 'paid', 'canceled', 'all'];

    public function payables(Request $request, FinanceService $finance): Response
    {
        return $this->list($request, $finance, FinanceType::Payable);
    }

    public function receivables(Request $request, FinanceService $finance): Response
    {
        return $this->list($request, $finance, FinanceType::Receivable);
    }

    public function create(Request $request, FinanceService $finance): Response
    {
        $store = $this->currentStore();
        $finance->ensureCategories($store);
        $type = FinanceType::tryFrom((string) $request->query('type')) ?? FinanceType::Payable;
        $supplierId = $request->integer('supplier') ?: null;

        return Inertia::render('finance/TitleForm', [
            'type' => $type->value,
            'title' => null,
            'installments' => [],
            'locked' => false,
            'preselectedSupplierId' => $supplierId,
            'today' => FinancePresenter::today($store)->format('Y-m-d'),
            ...FinancePresenter::formOptions($store, $type->value),
        ]);
    }

    public function store(FinanceTitleRequest $request, FinanceService $finance): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $title = $finance->create($this->currentStore(), $request->titleAttributes(), $request->installmentRows(), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => $title->type === FinanceType::Payable ? 'Conta a pagar lançada.' : 'Conta a receber lançada.']);

        return to_route($request->boolean('another') ? 'finance.titles.create' : 'finance.titles.show', $request->boolean('another') ? ['type' => $title->type->value] : $title);
    }

    public function show(FinanceTitle $title): Response
    {
        $this->ensureBelongsToCurrentStore($title);
        $store = $this->currentStore();
        $today = FinancePresenter::today($store);
        $title->load(['supplier', 'category', 'installments']);

        $paid = $title->installments->whereNotNull('paid_on');

        return Inertia::render('finance/TitleShow', [
            'title' => FinancePresenter::title($title),
            'installments' => $title->installments
                ->each(fn (FinanceInstallment $installment) => $installment->setRelation('title', $title))
                ->map(fn (FinanceInstallment $installment) => FinancePresenter::installment($installment, $today))
                ->values(),
            'paidCents' => (int) $paid->sum('paid_cents'),
            'openCents' => $title->canceled_at === null ? (int) $title->installments->whereNull('paid_on')->sum('amount_cents') : 0,
            'hasPaid' => $paid->isNotEmpty(),
            'paymentMethods' => FinancePaymentMethod::options(),
            'today' => $today->format('Y-m-d'),
            'cashOpen' => app(CashRegister::class)->current($store) !== null,
        ]);
    }

    public function edit(FinanceTitle $title): Response
    {
        $this->ensureBelongsToCurrentStore($title);
        $store = $this->currentStore();
        $title->load(['supplier', 'category', 'installments']);

        return Inertia::render('finance/TitleForm', [
            'type' => $title->type->value,
            'title' => FinancePresenter::title($title),
            'installments' => $title->installments->map(fn (FinanceInstallment $installment) => [
                'due_on' => $installment->due_on->format('Y-m-d'),
                'amount' => Money::toInput($installment->amount_cents),
            ])->values(),
            'locked' => $title->installments->whereNotNull('paid_on')->isNotEmpty(),
            'preselectedSupplierId' => null,
            'today' => FinancePresenter::today($store)->format('Y-m-d'),
            ...FinancePresenter::formOptions($store, $title->type->value),
        ]);
    }

    public function update(FinanceTitleRequest $request, FinanceTitle $title, FinanceService $finance): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($title);

        $finance->update($title, $request->titleAttributes(), $request->installmentRows());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Lançamento atualizado.']);

        return to_route('finance.titles.show', $title);
    }

    public function destroy(FinanceTitle $title): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($title);

        if ($title->installments()->whereNotNull('paid_on')->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Este lançamento já tem parcelas baixadas. Cancele-o em vez de excluir, ou desfaça as baixas antes.']);

            return back();
        }

        $type = $title->type;
        $title->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Lançamento excluído.']);

        return to_route($type === FinanceType::Payable ? 'finance.payables' : 'finance.receivables');
    }

    public function cancel(FinanceTitle $title, FinanceService $finance): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($title);
        $finance->cancel($title);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Parcelas em aberto canceladas. As já baixadas continuam no histórico.']);

        return back();
    }

    public function reopen(FinanceTitle $title, FinanceService $finance): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($title);
        $finance->reopen($title);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Lançamento reaberto.']);

        return back();
    }

    private function list(Request $request, FinanceService $finance, FinanceType $type): Response
    {
        $store = $this->currentStore();
        $finance->ensureCategories($store);
        $today = FinancePresenter::today($store);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:80'],
            'supplier' => ['nullable', 'integer'],
            'category' => ['nullable', 'integer'],
        ]);
        $status = $filters['status'] ?? 'open';

        $query = FinanceInstallment::query()
            ->where('finance_installments.store_id', $store->id)
            ->whereHas('title', function (Builder $title) use ($type, $filters): void {
                $title->where('type', $type->value);

                if (! empty($filters['supplier'])) {
                    $title->where('supplier_id', $filters['supplier']);
                }

                if (! empty($filters['category'])) {
                    $title->where('category_id', $filters['category']);
                }

                if (! empty($filters['q'])) {
                    $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['q']).'%';
                    $title->where(fn (Builder $search) => $search
                        ->where('description', 'like', $term)
                        ->orWhere('counterparty', 'like', $term)
                        ->orWhere('document_number', 'like', $term)
                        ->orWhereHas('supplier', fn (Builder $supplier) => $supplier->where('name', 'like', $term)));
                }
            });

        $dateColumn = $status === 'paid' ? 'paid_on' : 'due_on';

        match ($status) {
            'open' => $query->open(),
            'overdue' => $query->open()->where('due_on', '<', $today->format('Y-m-d')),
            'paid' => $query->whereNotNull('paid_on'),
            'canceled' => $query->whereNull('paid_on')->whereHas('title', fn (Builder $title) => $title->whereNotNull('canceled_at')),
            default => $query,
        };

        if (! empty($filters['from'])) {
            $query->where($dateColumn, '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where($dateColumn, '<=', $filters['to']);
        }

        $totalCents = (int) (clone $query)->sum($status === 'paid' ? 'paid_cents' : 'amount_cents');

        $installments = $query
            ->with(['title.supplier', 'title.category'])
            ->orderBy($dateColumn, $status === 'paid' ? 'desc' : 'asc')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (FinanceInstallment $installment) => FinancePresenter::installment($installment, $today));

        $overview = new FinanceOverview($store, $today->startOfMonth());

        return Inertia::render('finance/Titles', [
            'type' => $type->value,
            'installments' => $installments,
            'totalCents' => $totalCents,
            'filters' => [
                'status' => $status,
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
                'q' => $filters['q'] ?? '',
                'supplier' => isset($filters['supplier']) ? (string) $filters['supplier'] : '',
                'category' => isset($filters['category']) ? (string) $filters['category'] : '',
            ],
            'summary' => [
                'overdue' => $overview->overdue($type),
                'today' => $overview->dueToday($type),
                'next7' => $overview->dueNextDays($type, 7),
                'paidThisMonthCents' => $overview->paidInMonth($type),
            ],
            'suppliers' => $type === FinanceType::Payable
                ? $store->suppliers()->orderBy('name')->get(['id', 'name'])->map(fn ($supplier) => ['id' => $supplier->id, 'name' => $supplier->name])->values()
                : [],
            'categories' => $store->financeCategories()->where('type', $type->value)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($category) => ['id' => $category->id, 'name' => $category->name])->values(),
            'paymentMethods' => FinancePaymentMethod::options(),
            'today' => $today->format('Y-m-d'),
            'cashOpen' => app(CashRegister::class)->current($store) !== null,
        ]);
    }
}

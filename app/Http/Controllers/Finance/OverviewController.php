<?php

namespace App\Http\Controllers\Finance;

use App\Enums\FinancePaymentMethod;
use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\FinanceInstallment;
use App\Services\Cash\CashRegister;
use App\Services\Finance\FinanceOverview;
use App\Services\Finance\FinancePresenter;
use App\Services\Finance\FinanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OverviewController extends Controller
{
    use InteractsWithCurrentStore;

    private const MONTHS = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    public function index(Request $request, FinanceService $finance): Response
    {
        $store = $this->currentStore();
        $finance->ensureCategories($store);
        $today = FinancePresenter::today($store);

        $month = $today->startOfMonth();
        $requested = $request->query('month');

        if (is_string($requested) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requested) === 1) {
            $month = CarbonImmutable::createFromFormat('Y-m-d', $requested.'-01', $store->timezone)?->startOfDay() ?? $month;
        }

        $upcoming = FinanceInstallment::query()
            ->where('store_id', $store->id)
            ->open()
            ->where('due_on', '<=', $today->addDays(7)->format('Y-m-d'))
            ->with(['title.supplier', 'title.category'])
            ->orderBy('due_on')
            ->limit(12)
            ->get()
            ->map(fn (FinanceInstallment $installment) => FinancePresenter::installment($installment, $today))
            ->values();

        return Inertia::render('finance/Overview', [
            'month' => $month->format('Y-m'),
            'monthLabel' => self::MONTHS[$month->month - 1].' de '.$month->year,
            'isCurrentMonth' => $month->equalTo($today->startOfMonth()),
            'overview' => (new FinanceOverview($store, $month))->toArray(),
            'upcoming' => $upcoming,
            'paymentMethods' => FinancePaymentMethod::options(),
            'today' => $today->format('Y-m-d'),
            'cashOpen' => app(CashRegister::class)->current($store) !== null,
        ]);
    }
}

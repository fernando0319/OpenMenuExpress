<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CourierPayout;
use App\Models\DeliveryBill;
use App\Models\User;
use App\Services\CourierNetwork\NetworkBilling;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The network's money: bills the stores pay and the weekly payouts to couriers.
 * Both happen by PIX outside the system; here they are recorded.
 */
class NetworkFinanceAdminController extends Controller
{
    public function __construct(private readonly NetworkBilling $billing) {}

    public function bills(Request $request): Response
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(['open', 'paid', 'waived', 'all'])]]);
        $status = $filters['status'] ?? 'open';

        $query = DeliveryBill::query()
            ->with('store:id,name')
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status));

        return Inertia::render('admin/network/Bills', [
            'bills' => (clone $query)->orderBy('status')->orderByDesc('period_start')->paginate(50)->withQueryString()->through(fn (DeliveryBill $bill) => [
                'id' => $bill->id,
                'storeId' => $bill->store_id,
                'storeName' => $bill->store->name,
                'period' => $bill->periodLabel(),
                'cycle' => $bill->cycle->label(),
                'count' => $bill->deliveries_count,
                'amountCents' => $bill->amount_cents,
                'dueAt' => $bill->due_at->toIso8601String(),
                'status' => $bill->status,
                'statusLabel' => $bill->statusLabel(),
                'overdue' => $bill->isOverdue(),
                'note' => $bill->note,
            ]),
            'totalCents' => (int) $query->sum('amount_cents'),
            'filters' => ['status' => $status],
        ]);
    }

    public function settleBill(Request $request, DeliveryBill $bill): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([DeliveryBill::PAID, DeliveryBill::WAIVED])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->billing->settleBill($bill, $data['status'], $this->admin($request), $data['note'] ?? null, $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Fatura de {$bill->store->name} ({$bill->periodLabel()}) ".($data['status'] === DeliveryBill::PAID ? 'paga.' : 'dispensada.')]);

        return back();
    }

    public function reopenBill(Request $request, DeliveryBill $bill): RedirectResponse
    {
        $this->billing->reopenBill($bill, $this->admin($request), $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Fatura reaberta.']);

        return back();
    }

    public function payouts(Request $request): Response
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(['pending', 'paid', 'all'])]]);
        $status = $filters['status'] ?? 'pending';

        $query = $this->payoutQuery($status);

        return Inertia::render('admin/network/Payouts', [
            'payouts' => (clone $query)->paginate(50)->withQueryString()->through(fn (CourierPayout $payout) => [
                'id' => $payout->id,
                'courierId' => $payout->network_courier_id,
                'courierName' => $payout->courier->user->name,
                'period' => $payout->periodLabel(),
                'count' => $payout->deliveries_count,
                'amountCents' => $payout->amount_cents,
                'pixKeyType' => $payout->pix_key_type->label(),
                'pixKey' => $payout->pix_key,
                'status' => $payout->status,
                'paidAt' => $payout->paid_at?->toIso8601String(),
                'note' => $payout->note,
            ]),
            'totalCents' => (int) $query->sum('amount_cents'),
            'filters' => ['status' => $status],
        ]);
    }

    public function payPayout(Request $request, CourierPayout $payout): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        $this->billing->markPayoutPaid($payout, $this->admin($request), $data['note'] ?? null, $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Repasse de {$payout->courier->user->name} marcado como pago."]);

        return back();
    }

    public function reopenPayout(Request $request, CourierPayout $payout): RedirectResponse
    {
        $this->billing->reopenPayout($payout, $this->admin($request), $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Repasse reaberto.']);

        return back();
    }

    /**
     * Pending payouts as a spreadsheet, to pay them one by one in the bank app.
     */
    public function exportPayouts(): StreamedResponse
    {
        $payouts = $this->payoutQuery('pending')->get();

        return response()->streamDownload(function () use ($payouts): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF"); // Excel reads the accents as UTF-8
            fputcsv($out, ['Entregador', 'CPF', 'Semana', 'Corridas', 'Valor', 'Tipo da chave', 'Chave PIX'], ';');

            foreach ($payouts as $payout) {
                fputcsv($out, [
                    $payout->courier->user->name,
                    $payout->courier->cpf,
                    $payout->periodLabel(),
                    $payout->deliveries_count,
                    number_format($payout->amount_cents / 100, 2, ',', ''),
                    $payout->pix_key_type->label(),
                    $payout->pix_key,
                ], ';');
            }

            fclose($out);
        }, 'repasses-pendentes-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return Builder<CourierPayout>
     */
    private function payoutQuery(string $status): Builder
    {
        return CourierPayout::query()
            ->with('courier.user:id,name')
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->orderBy('status')
            ->orderByDesc('period_start')
            ->orderByDesc('amount_cents');
    }

    private function admin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_super_admin === true, 403);

        return $user;
    }
}

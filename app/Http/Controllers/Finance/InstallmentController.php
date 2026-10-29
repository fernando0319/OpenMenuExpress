<?php

namespace App\Http\Controllers\Finance;

use App\Enums\FinancePaymentMethod;
use App\Enums\FinanceType;
use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\SettleInstallmentRequest;
use App\Models\FinanceInstallment;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * "Baixa": recording that an installment was paid (or received), and undoing it.
 */
class InstallmentController extends Controller
{
    use InteractsWithCurrentStore;

    public function settle(SettleInstallmentRequest $request, FinanceInstallment $installment, FinanceService $finance): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($installment);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $data = $request->validated();

        $finance->settle(
            $installment,
            CarbonImmutable::createFromFormat('Y-m-d', $data['paid_on'], $this->currentStore()->timezone) ?: CarbonImmutable::now(),
            (int) $data['amount'],
            FinancePaymentMethod::from($data['payment_method']),
            $data['notes'] ?? null,
            (bool) ($data['through_cash'] ?? false),
            $user,
        );

        $payable = $installment->title()->value('type') === FinanceType::Payable->value;
        Inertia::flash('toast', ['type' => 'success', 'message' => $payable ? 'Pagamento registrado.' : 'Recebimento registrado.']);

        return back();
    }

    public function undo(Request $request, FinanceInstallment $installment, FinanceService $finance): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($installment);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $hadCash = $installment->cash_movement_id !== null;
        $adjusted = $finance->undo($installment, $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => match (true) {
                $adjusted => 'Baixa desfeita. O valor também foi estornado no caixa.',
                $hadCash => 'Baixa desfeita. O caixa daquele dia já foi fechado: ajuste-o com um suprimento ou sangria, se precisar.',
                default => 'Baixa desfeita.',
            },
        ]);

        return back();
    }
}

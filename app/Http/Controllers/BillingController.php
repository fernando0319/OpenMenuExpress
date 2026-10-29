<?php

namespace App\Http\Controllers;

use App\Enums\Module;
use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use App\Models\VoucherRedemption;
use App\Services\Billing\BillingService;
use App\Services\Billing\Subscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Assinatura": where the merchant sees the plan, the due date and enters vouchers.
 * Reachable even when the subscription expired (it is the way back in).
 */
class BillingController extends Controller
{
    use InteractsWithCurrentStore;

    public function show(): Response|RedirectResponse
    {
        if (! config('billing.enabled')) {
            return to_route('dashboard');
        }

        $store = $this->currentStore();
        $subscription = Subscription::for($store);

        return Inertia::render('billing/Show', [
            'subscription' => $subscription->toArray(),
            'modules' => array_map(fn (Module $module) => [
                'value' => $module->value,
                'label' => $module->label(),
                'included' => $subscription->hasModule($module),
            ], Module::cases()),
            'plans' => Plan::query()->where('is_active', true)->orderBy('sort_order')->orderBy('price_cents')->get()->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'priceCents' => $plan->price_cents,
                'interval' => $plan->intervalLabel(),
                'freeUpToCents' => $plan->free_up_to_cents,
                'usageSummary' => $plan->usageSummary(),
                'modules' => array_map(fn (Module $module) => $module->label(), $plan->moduleList()),
                // The free plan a store falls back to counts as its plan too.
                'isCurrent' => $plan->id === ($subscription->plan->id ?? $store->plan_id),
            ]),
            'history' => $store->voucherRedemptions()->with('voucher.plan')->latest('id')->limit(10)->get()->map(fn (VoucherRedemption $redemption) => [
                'id' => $redemption->id,
                'code' => $redemption->voucher->code ?? '',
                'plan' => $redemption->voucher?->plan->name,
                'days' => $redemption->days,
                'at' => $redemption->created_at?->toIso8601String(),
                'until' => $redemption->paid_until_after->toIso8601String(),
            ]),
            'invoices' => $store->invoices()->latest('period_start')->limit(12)->get()->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'period' => $invoice->periodLabel(),
                'salesCents' => $invoice->sales_cents,
                'amountCents' => $invoice->amount_cents,
                'status' => $invoice->status,
                'statusLabel' => $invoice->statusLabel(),
            ]),
            'contactWhatsapp' => config('billing.contact_whatsapp'),
            'canRedeem' => Gate::allows('manage-store'),
        ]);
    }

    public function redeem(Request $request, BillingService $billing): RedirectResponse
    {
        abort_unless(config('billing.enabled') && Gate::allows('manage-store'), 403);

        $data = $request->validate(['code' => ['required', 'string', 'max:30']], ['code.required' => 'Digite o código do voucher.']);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $redemption = $billing->redeem($this->currentStore(), $data['code'], $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Voucher aplicado: +{$redemption->days} dias. Assinatura válida até ".$redemption->paid_until_after->setTimezone($this->currentStore()->timezone)->format('d/m/Y').'.',
        ]);

        return to_route('billing.show');
    }
}

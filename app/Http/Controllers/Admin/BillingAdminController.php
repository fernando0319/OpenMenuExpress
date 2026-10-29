<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Module;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Billing\BillingService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plans, vouchers and manual releases, for the platform owner.
 */
class BillingAdminController extends Controller
{
    public function __construct(private readonly BillingService $billing) {}

    public function plans(): Response
    {
        return Inertia::render('admin/Plans', [
            'plans' => Plan::query()->withCount('stores')->orderBy('sort_order')->orderBy('id')->get()->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'priceCents' => $plan->price_cents,
                'interval' => $plan->interval,
                'freeUpToCents' => $plan->free_up_to_cents,
                'percent' => $plan->percentInput(),
                'percentBase' => $plan->percent_base,
                'feeMinCents' => $plan->fee_min_cents,
                'feeMaxCents' => $plan->fee_max_cents,
                'usageSummary' => $plan->usageSummary(),
                'modules' => $plan->modules,
                'isActive' => $plan->is_active,
                'storesCount' => (int) $plan->getAttribute('stores_count'),
            ]),
            'modules' => array_map(fn (Module $module) => ['value' => $module->value, 'label' => $module->label()], Module::cases()),
            'billingEnabled' => (bool) config('billing.enabled'),
        ]);
    }

    public function storePlan(Request $request): RedirectResponse
    {
        Plan::query()->create([...$this->planData($request), 'sort_order' => (int) Plan::query()->max('sort_order') + 1]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Plano criado.']);

        return back();
    }

    public function updatePlan(Request $request, Plan $plan): RedirectResponse
    {
        $plan->update($this->planData($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Plano atualizado.']);

        return back();
    }

    public function vouchers(Request $request): Response
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['all', 'available', 'used'])]])['status'] ?? 'all';

        return Inertia::render('admin/Vouchers', [
            'vouchers' => Voucher::query()
                ->with('plan:id,name')
                ->withCount('redemptions')
                ->when($status === 'available', fn ($query) => $query->where('is_active', true)->whereColumn('redemptions_count', '<', 'max_redemptions'))
                ->when($status === 'used', fn ($query) => $query->whereColumn('redemptions_count', '>=', 'max_redemptions'))
                ->latest('id')
                ->paginate(30)
                ->withQueryString()
                ->through(fn (Voucher $voucher) => [
                    'id' => $voucher->id,
                    'code' => $voucher->code,
                    'plan' => $voucher->plan->name ?? '',
                    'days' => $voucher->days,
                    'uses' => $voucher->redemptions_count,
                    'maxUses' => $voucher->max_redemptions,
                    'expiresAt' => $voucher->expires_at?->toIso8601String(),
                    'note' => $voucher->note,
                    'status' => $voucher->statusLabel(),
                    'isActive' => $voucher->is_active,
                    'createdAt' => $voucher->created_at?->toIso8601String(),
                ]),
            'plans' => Plan::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'filters' => ['status' => $status],
            'generated' => session('generated_codes', []),
        ]);
    }

    public function generateVouchers(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')],
            'days' => ['required', 'integer', 'min:1', 'max:1100'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'max_redemptions' => ['required', 'integer', 'min:1', 'max:1000'],
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:120'],
        ]);

        $vouchers = $this->billing->generateVouchers(
            Plan::query()->whereKey($data['plan_id'])->firstOrFail(),
            (int) $data['days'],
            (int) $data['quantity'],
            (int) $data['max_redemptions'],
            isset($data['expires_at']) ? CarbonImmutable::parse($data['expires_at'])->endOfDay() : null,
            $data['note'] ?? null,
            $this->admin($request),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => count($vouchers).' voucher(s) gerado(s).']);

        // Shown once, right after generating, so they can be copied and sent.
        return back()->with('generated_codes', array_map(fn (Voucher $voucher) => $voucher->code, $vouchers));
    }

    public function toggleVoucher(Voucher $voucher): RedirectResponse
    {
        $voucher->update(['is_active' => ! $voucher->is_active]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $voucher->is_active ? 'Voucher reativado.' : 'Voucher desativado.']);

        return back();
    }

    /**
     * Manual release: adds days (payment received outside the system), optionally changing the plan.
     */
    public function extend(Request $request, Store $store): RedirectResponse
    {
        $data = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:1100'],
            'plan_id' => ['nullable', 'integer', Rule::exists('plans', 'id')],
            'note' => ['nullable', 'string', 'max:120'],
        ]);

        $plan = isset($data['plan_id']) ? Plan::query()->whereKey($data['plan_id'])->first() : null;
        $this->billing->extend($store, (int) $data['days'], $plan, $this->admin($request), $data['note'] ?? null, $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => "+{$data['days']} dias liberados para {$store->name}."]);

        return back();
    }

    public function setPlan(Request $request, Store $store): RedirectResponse
    {
        $data = $request->validate(['plan_id' => ['nullable', 'integer', Rule::exists('plans', 'id')]]);
        $plan = isset($data['plan_id']) ? Plan::query()->whereKey($data['plan_id'])->first() : null;

        $this->billing->setPlan($store, $plan, $this->admin($request), $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Plano da loja atualizado.']);

        return back();
    }

    public function complimentary(Request $request, Store $store): RedirectResponse
    {
        $data = $request->validate([
            'complimentary' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:120'],
        ]);

        $this->billing->setComplimentary($store, (bool) $data['complimentary'], $this->admin($request), $data['note'] ?? null, $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => $data['complimentary'] ? 'Cortesia ativada: a loja não vence.' : 'Cortesia removida.']);

        return back();
    }

    /**
     * @return array{name: string, price_cents: int, interval: string, free_up_to_cents: int|null, percent_bps: int|null, percent_base: string, fee_min_cents: int|null, fee_max_cents: int|null, modules: list<string>, is_active: bool}
     */
    private function planData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'price' => ['required', 'string', 'max:20'],
            'interval' => ['required', Rule::in(['month', 'year'])],
            'free_up_to' => ['nullable', 'string', 'max:20'],
            'percent' => ['nullable', 'string', 'max:10'],
            'percent_base' => ['nullable', Rule::in([Plan::BASE_EXCESS, Plan::BASE_TOTAL])],
            'fee_min' => ['nullable', 'string', 'max:20'],
            'fee_max' => ['nullable', 'string', 'max:20'],
            'modules' => ['present', 'array'],
            'modules.*' => [Rule::in(Module::values())],
            'is_active' => ['boolean'],
        ]);

        $cents = Money::parseToCents($data['price']);

        if ($cents === null) {
            throw ValidationException::withMessages(['price' => 'Preço inválido. Use o formato 49,90.']);
        }

        $freeUpTo = $this->optionalCents($data, 'free_up_to', 'Limite inválido. Use o formato 3000,00.');
        // "2,5" (%) is parsed like money: 2,50 → 250 basis points.
        $percent = $this->optionalCents($data, 'percent', 'Percentual inválido. Use o formato 2 ou 2,5.');

        if ($percent !== null && ($percent < 1 || $percent > 5000)) {
            throw ValidationException::withMessages(['percent' => 'O percentual deve estar entre 0,01% e 50%.']);
        }

        $min = $percent !== null ? $this->optionalCents($data, 'fee_min', 'Mínimo inválido. Use o formato 19,00.') : null;
        $max = $percent !== null ? $this->optionalCents($data, 'fee_max', 'Teto inválido. Use o formato 299,00.') : null;

        if ($min !== null && $max !== null && $max < $min) {
            throw ValidationException::withMessages(['fee_max' => 'O teto precisa ser maior que o mínimo.']);
        }

        return [
            'name' => trim($data['name']),
            'price_cents' => $cents,
            'interval' => $percent !== null ? 'month' : $data['interval'],
            // Free plans cost nothing while the store sells up to this much a month; plans charged
            // by use charge nothing below it.
            'free_up_to_cents' => $freeUpTo,
            'percent_bps' => $percent,
            'percent_base' => $data['percent_base'] ?? Plan::BASE_EXCESS,
            'fee_min_cents' => $min,
            'fee_max_cents' => $max,
            'modules' => array_values(array_unique($data['modules'])),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function optionalCents(array $data, string $key, string $message): ?int
    {
        $value = $data[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return Money::parseToCents($value) ?? throw ValidationException::withMessages([$key => $message]);
    }

    private function admin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_super_admin === true, 403);

        return $user;
    }
}

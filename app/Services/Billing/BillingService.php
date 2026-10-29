<?php

namespace App\Services\Billing;

use App\Models\AdminAuditLog;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes to a store's subscription: vouchers redeemed by the merchant and manual releases
 * by the platform admin. Paid time always stacks on top of what the store already has.
 */
final class BillingService
{
    /**
     * @return list<Voucher>
     */
    public function generateVouchers(Plan $plan, int $days, int $quantity, int $maxRedemptions, ?CarbonImmutable $expiresAt, ?string $note, User $admin): array
    {
        return DB::transaction(function () use ($plan, $days, $quantity, $maxRedemptions, $expiresAt, $note, $admin): array {
            $vouchers = [];

            for ($i = 0; $i < $quantity; $i++) {
                do {
                    $code = Voucher::generateCode();
                } while (Voucher::query()->where('code', $code)->exists());

                $vouchers[] = Voucher::query()->create([
                    'code' => $code,
                    'plan_id' => $plan->id,
                    'days' => $days,
                    'max_redemptions' => $maxRedemptions,
                    'expires_at' => $expiresAt,
                    'note' => $note,
                    'created_by' => $admin->id,
                ]);
            }

            return $vouchers;
        });
    }

    /**
     * The merchant types the code in the panel; the plan and the days are applied at once.
     */
    public function redeem(Store $store, string $code, User $by): VoucherRedemption
    {
        $code = Voucher::normalize($code);

        return DB::transaction(function () use ($store, $code, $by): VoucherRedemption {
            $voucher = Voucher::query()->where('code', $code)->lockForUpdate()->first();

            if ($voucher === null || ! $voucher->is_active) {
                $this->fail('Código inválido. Confira e tente de novo.');
            }

            if ($voucher->expires_at !== null && $voucher->expires_at->isPast()) {
                $this->fail('Este código expirou.');
            }

            if ($voucher->redemptions_count >= $voucher->max_redemptions) {
                $this->fail('Este código já foi usado.');
            }

            if ($voucher->redemptions()->where('store_id', $store->id)->exists()) {
                $this->fail('Sua loja já usou este código.');
            }

            $locked = Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $before = $locked->paid_until;
            $after = $this->stackedEnd($locked)->addDays($voucher->days);

            $locked->update(['plan_id' => $voucher->plan_id, 'paid_until' => $after]);
            $voucher->increment('redemptions_count');

            return $voucher->redemptions()->create([
                'store_id' => $locked->id,
                'user_id' => $by->id,
                'days' => $voucher->days,
                'paid_until_before' => $before,
                'paid_until_after' => $after,
            ]);
        });
    }

    /**
     * Manual release by the admin: days of a plan (payment received outside the system).
     */
    public function extend(Store $store, int $days, ?Plan $plan, User $admin, ?string $note, ?string $ip = null): void
    {
        DB::transaction(function () use ($store, $days, $plan, $admin, $note, $ip): void {
            $locked = Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $after = $this->stackedEnd($locked)->addDays($days);

            $locked->update(['paid_until' => $after, 'plan_id' => $plan->id ?? $locked->plan_id]);

            $this->log($locked, $admin, 'billing.extend', [
                'days' => $days,
                'plan' => $plan?->name,
                'paid_until' => $after->toDateString(),
                'note' => $note,
            ], $ip);
        });
    }

    public function setPlan(Store $store, ?Plan $plan, User $admin, ?string $ip = null): void
    {
        $store->update(['plan_id' => $plan?->id]);
        $this->log($store, $admin, 'billing.plan', ['plan' => $plan?->name], $ip);
    }

    public function setComplimentary(Store $store, bool $complimentary, User $admin, ?string $note, ?string $ip = null): void
    {
        $store->update(['is_complimentary' => $complimentary]);
        $this->log($store, $admin, $complimentary ? 'billing.complimentary' : 'billing.complimentary_off', ['note' => $note], $ip);
    }

    /**
     * New paid time starts when the current one ends (paid or trial), or now if it already ended.
     */
    private function stackedEnd(Store $store): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $candidates = array_filter([$now, $store->paid_until, $store->trial_ends_at]);

        return max($candidates);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function log(Store $store, User $admin, string $action, array $details, ?string $ip): void
    {
        AdminAuditLog::query()->create([
            'user_id' => $admin->id,
            'store_id' => $store->id,
            'action' => $action,
            'details' => $details,
            'ip' => $ip,
        ]);
    }

    /**
     * @return never
     */
    private function fail(string $message): void
    {
        throw ValidationException::withMessages(['code' => $message]);
    }
}

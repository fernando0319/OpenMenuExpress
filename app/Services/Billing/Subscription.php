<?php

namespace App\Services\Billing;

use App\Enums\Module;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Where a store stands with its subscription, computed from its dates (and, on a free plan,
 * from its monthly sales).
 *
 * trial → active (paid) → grace (expired, still working, with warnings) → expired (blocked).
 * A free plan ("free up to R$ X a month") renews by itself every month while the store sells
 * up to the limit; after a month above it, a paid plan is needed from the 1st of the next month.
 * Complimentary stores never expire. With billing off (self-hosted), everything is allowed.
 */
final readonly class Subscription
{
    public const TRIAL = 'trial';

    public const ACTIVE = 'active';

    public const FREE = 'free';

    /** On a plan charged by use, with nothing overdue. */
    public const USAGE = 'usage';

    public const GRACE = 'grace';

    public const EXPIRED = 'expired';

    public const COMPLIMENTARY = 'complimentary';

    public const DISABLED = 'disabled';

    /** Why the store is in grace/expired: its time ran out, or it sold above the free limit. */
    public const REASON_ENDED = 'ended';

    public const REASON_OVER_LIMIT = 'over_limit';

    /** A closed month of a plan charged by use is still unpaid. */
    public const REASON_INVOICE = 'invoice';

    private function __construct(
        public Store $store,
        public string $status,
        /** When access ends (or ended); null when it never ends. */
        public ?CarbonImmutable $endsAt,
        public CarbonImmutable $now,
        /** The plan whose modules apply (the free plan when the store falls back to it). */
        public ?Plan $plan = null,
        public ?string $reason = null,
    ) {}

    public static function for(Store $store, ?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();

        if (! config('billing.enabled')) {
            return new self($store, self::DISABLED, null, $now);
        }

        $plan = $store->plan;

        if ($store->is_complimentary) {
            return new self($store, self::COMPLIMENTARY, null, $now, $plan);
        }

        $byLimitOrUse = ($plan?->isFree() ?? false) || ($plan?->isUsage() ?? false);

        if ($store->paid_until !== null && $store->paid_until->greaterThan($now) && ! $byLimitOrUse) {
            return new self($store, self::ACTIVE, $store->paid_until, $now, $plan);
        }

        if ($store->trial_ends_at !== null && $store->trial_ends_at->greaterThan($now)) {
            return new self($store, self::TRIAL, $store->trial_ends_at, $now, $plan);
        }

        // Out of trial and paid time: the store's free or by-use plan, or the default one if there is one.
        $fallback = $byLimitOrUse ? $plan : Plan::fallback();

        if ($fallback !== null && $fallback->isUsage()) {
            return self::onUsagePlan($store, $fallback, $now);
        }

        if ($fallback !== null) {
            return self::onFreePlan($store, $fallback, $now);
        }

        $endedAt = self::latest($store->paid_until, $store->trial_ends_at) ?? $now;

        return self::ended($store, $endedAt, $now, $plan, self::REASON_ENDED);
    }

    /**
     * Free while last month's sales stayed within the limit. Above it, the store needs a paid
     * plan from the start of this month (or from when its trial/paid time ended, if later).
     */
    private static function onFreePlan(Store $store, Plan $free, CarbonImmutable $now): self
    {
        $monthStart = $now->setTimezone($store->timezone)->startOfMonth();
        $lastMonth = MonthlySales::of($store, $monthStart->subMonthNoOverflow(), $now);

        if ($lastMonth <= (int) $free->free_up_to_cents) {
            return new self($store, self::FREE, $monthStart->endOfMonth()->utc(), $now, $free);
        }

        $owesSince = self::latest($monthStart->utc(), $store->paid_until, $store->trial_ends_at) ?? $monthStart->utc();

        return self::ended($store, $owesSince, $now, $free, self::REASON_OVER_LIMIT);
    }

    /**
     * Charged by use: last month is closed into an invoice (only the part after the trial),
     * and an unpaid invoice works like an expired subscription from the 1st of the next month.
     */
    private static function onUsagePlan(Store $store, Plan $plan, CarbonImmutable $now): self
    {
        $monthStart = $now->setTimezone($store->timezone)->startOfMonth();

        if ($store->trial_ends_at === null || $store->trial_ends_at->lessThan($monthStart)) {
            Invoicing::close($store, $plan, $monthStart->subMonthNoOverflow(), $store->trial_ends_at?->setTimezone($store->timezone));
        }

        $due = $store->invoices()->where('status', Invoice::OPEN)->where('amount_cents', '>', 0)->orderBy('period_start')->first();

        if ($due instanceof Invoice) {
            $due->setRelation('store', $store);

            return self::ended($store, $due->dueAt(), $now, $plan, self::REASON_INVOICE);
        }

        return new self($store, self::USAGE, $monthStart->endOfMonth()->utc(), $now, $plan);
    }

    private static function ended(Store $store, CarbonImmutable $endedAt, CarbonImmutable $now, ?Plan $plan, string $reason): self
    {
        $graceEnds = $endedAt->addDays((int) config('billing.grace_days'));

        return new self($store, $graceEnds->greaterThan($now) ? self::GRACE : self::EXPIRED, $endedAt, $now, $plan, $reason);
    }

    public function isBlocked(): bool
    {
        return $this->status === self::EXPIRED;
    }

    /**
     * Trials and test periods see everything; plans (paid or free) see their modules.
     */
    public function hasModule(Module $module): bool
    {
        return in_array($module, $this->modules(), true);
    }

    /**
     * @return list<Module>
     */
    public function modules(): array
    {
        if (in_array($this->status, [self::DISABLED, self::TRIAL, self::COMPLIMENTARY], true) || $this->plan === null) {
            return Module::cases();
        }

        return $this->plan->moduleList();
    }

    public function isOnFreePlan(): bool
    {
        return $this->plan?->isFree() === true && in_array($this->status, [self::FREE, self::GRACE, self::EXPIRED], true);
    }

    /**
     * This month's sales against the free limit, for the meter (only on the free plan).
     *
     * @return array{limitCents: int, salesCents: int, overLimit: bool}|null
     */
    public function freeUsage(): ?array
    {
        if (! $this->isOnFreePlan() || $this->plan === null) {
            return null;
        }

        $limit = (int) $this->plan->free_up_to_cents;
        $sales = MonthlySales::of($this->store, $this->now->setTimezone($this->store->timezone)->startOfMonth(), $this->now);

        return ['limitCents' => $limit, 'salesCents' => $sales, 'overLimit' => $sales > $limit];
    }

    /**
     * On a plan charged by use: this month's online sales so far, what they would cost, and the
     * oldest unpaid invoice.
     *
     * @return array{summary: string|null, salesCents: int, estimateCents: int, freeUpToCents: int|null, openInvoice: array{period: string, amountCents: int}|null}|null
     */
    public function usage(): ?array
    {
        if ($this->plan === null || ! $this->plan->isUsage() || ! in_array($this->status, [self::USAGE, self::GRACE, self::EXPIRED], true)) {
            return null;
        }

        $monthStart = $this->now->setTimezone($this->store->timezone)->startOfMonth();
        $sales = (int) Cache::remember(
            "billing:online:{$this->store->id}:{$monthStart->format('Y-m')}",
            now()->addMinutes(10),
            fn () => MonthlySales::online($this->store, $monthStart, $this->store->trial_ends_at?->setTimezone($this->store->timezone)),
        );
        $open = $this->store->invoices()->where('status', Invoice::OPEN)->where('amount_cents', '>', 0)->orderBy('period_start')->first();

        return [
            'summary' => $this->plan->usageSummary(),
            'salesCents' => $sales,
            'estimateCents' => $this->plan->usageFee($sales),
            'freeUpToCents' => $this->plan->free_up_to_cents,
            'openInvoice' => $open instanceof Invoice ? ['period' => $open->periodLabel(), 'amountCents' => $open->amount_cents] : null,
        ];
    }

    /**
     * Whole days until access ends (negative once it has ended); null when it never ends.
     */
    public function daysLeft(): ?int
    {
        return $this->endsAt === null ? null : (int) floor($this->now->diffInDays($this->endsAt, false));
    }

    /**
     * Days left in the grace period (only meaningful while in grace).
     */
    public function graceDaysLeft(): int
    {
        if ($this->endsAt === null) {
            return 0;
        }

        return max(0, (int) ceil($this->now->diffInDays($this->endsAt->addDays((int) config('billing.grace_days')), false)));
    }

    /**
     * The panel shows a warning: close to the end, in grace, or above the free limit this month.
     */
    public function shouldWarn(): bool
    {
        if ($this->status === self::GRACE) {
            return true;
        }

        if ($this->status === self::FREE) {
            return $this->freeUsage()['overLimit'] ?? false;
        }

        $days = $this->daysLeft();

        return in_array($this->status, [self::TRIAL, self::ACTIVE], true)
            && $days !== null
            && $days < (int) config('billing.warn_days');
    }

    public function label(): string
    {
        return match ($this->status) {
            self::TRIAL => 'Em teste grátis',
            self::ACTIVE => 'Ativa',
            self::FREE => 'Gratuita',
            self::USAGE => 'Por uso',
            self::GRACE => match ($this->reason) {
                self::REASON_OVER_LIMIT => 'Acima do limite gratuito (em carência)',
                self::REASON_INVOICE => 'Fatura em aberto (em carência)',
                default => 'Vencida (em carência)',
            },
            self::EXPIRED => match ($this->reason) {
                self::REASON_OVER_LIMIT => 'Acima do limite gratuito',
                self::REASON_INVOICE => 'Fatura em aberto',
                default => 'Vencida',
            },
            self::COMPLIMENTARY => 'Cortesia',
            default => 'Sem cobrança',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->status !== self::DISABLED,
            'status' => $this->status,
            'reason' => $this->reason,
            'label' => $this->label(),
            'planName' => $this->plan->name ?? null,
            'endsAt' => $this->endsAt?->toIso8601String(),
            'daysLeft' => $this->daysLeft(),
            'graceDaysLeft' => $this->status === self::GRACE ? $this->graceDaysLeft() : null,
            'warn' => $this->shouldWarn(),
            'blocked' => $this->isBlocked(),
            'modules' => array_map(fn (Module $module) => $module->value, $this->modules()),
            'free' => $this->freeUsage(),
            'usage' => $this->usage(),
        ];
    }

    private static function latest(?CarbonImmutable ...$dates): ?CarbonImmutable
    {
        $dates = array_filter($dates);

        return $dates === [] ? null : max($dates);
    }
}

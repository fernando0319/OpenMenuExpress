<?php

namespace App\Models;

use App\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A closed month of a store on a plan charged by use: its online sales and what it owes.
 *
 * @property int $id
 * @property int $store_id
 * @property int|null $plan_id
 * @property CarbonImmutable $period_start
 * @property int $sales_cents
 * @property int|null $free_up_to_cents
 * @property int $percent_bps
 * @property string $percent_base
 * @property int $fixed_cents
 * @property int|null $fee_min_cents
 * @property int|null $fee_max_cents
 * @property int $amount_cents
 * @property string $status open | paid | waived
 * @property CarbonImmutable|null $settled_at
 * @property int|null $settled_by
 * @property string|null $note
 * @property-read Store $store
 * @property-read Plan|null $plan
 */
#[Fillable([
    'store_id', 'plan_id', 'period_start', 'sales_cents', 'free_up_to_cents', 'percent_bps', 'percent_base', 'fixed_cents',
    'fee_min_cents', 'fee_max_cents', 'amount_cents', 'status', 'settled_at', 'settled_by', 'note',
])]
class Invoice extends Model
{
    public const OPEN = 'open';

    public const PAID = 'paid';

    public const WAIVED = 'waived';

    private const MONTHS = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => DateOnly::class,
            'sales_cents' => 'integer',
            'free_up_to_cents' => 'integer',
            'percent_bps' => 'integer',
            'fixed_cents' => 'integer',
            'fee_min_cents' => 'integer',
            'fee_max_cents' => 'integer',
            'amount_cents' => 'integer',
            'settled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Something is owed: open with an amount.
     */
    public function isDue(): bool
    {
        return $this->status === self::OPEN && $this->amount_cents > 0;
    }

    /**
     * "setembro de 2026".
     */
    public function periodLabel(): string
    {
        return self::MONTHS[$this->period_start->month - 1].' de '.$this->period_start->year;
    }

    /**
     * Day the invoice falls due: the 1st of the month after the period, in the store's timezone.
     */
    public function dueAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->period_start->format('Y-m-d'), $this->store->timezone)->addMonthNoOverflow()->startOfMonth()->utc();
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PAID => 'Paga',
            self::WAIVED => 'Dispensada',
            default => $this->amount_cents > 0 ? 'Em aberto' : 'Sem cobrança',
        };
    }
}

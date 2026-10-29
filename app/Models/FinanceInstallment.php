<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\FinancePaymentMethod;
use App\Enums\InstallmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $store_id
 * @property int $title_id
 * @property int $number
 * @property CarbonImmutable $due_on
 * @property int $amount_cents
 * @property CarbonImmutable|null $paid_on
 * @property int|null $paid_cents
 * @property FinancePaymentMethod|null $payment_method
 * @property bool $settled_automatically
 * @property int|null $cash_movement_id
 * @property int|null $settled_by
 * @property string|null $notes
 * @property-read FinanceTitle $title
 * @property-read CashMovement|null $cashMovement
 */
#[Fillable([
    'store_id', 'title_id', 'number', 'due_on', 'amount_cents', 'paid_on', 'paid_cents', 'payment_method',
    'settled_automatically', 'cash_movement_id', 'settled_by', 'notes',
])]
class FinanceInstallment extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_on' => DateOnly::class,
            'paid_on' => DateOnly::class,
            'amount_cents' => 'integer',
            'paid_cents' => 'integer',
            'number' => 'integer',
            'payment_method' => FinancePaymentMethod::class,
            'settled_automatically' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<FinanceTitle, $this>
     */
    public function title(): BelongsTo
    {
        return $this->belongsTo(FinanceTitle::class, 'title_id');
    }

    /**
     * @return BelongsTo<CashMovement, $this>
     */
    public function cashMovement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class);
    }

    /**
     * Not settled, from a title that was not canceled.
     *
     * @param  Builder<FinanceInstallment>  $query
     * @return Builder<FinanceInstallment>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('paid_on')->whereHas('title', fn (Builder $title) => $title->whereNull('canceled_at'));
    }

    /**
     * Whole days between the due date and $today (dates only: no timezone shifts).
     */
    public function daysLate(CarbonImmutable $today): int
    {
        $due = CarbonImmutable::createFromFormat('!Y-m-d', $this->due_on->format('Y-m-d'), 'UTC');
        $local = CarbonImmutable::createFromFormat('!Y-m-d', $today->format('Y-m-d'), 'UTC');

        return $due && $local ? max(0, (int) $due->diffInDays($local)) : 0;
    }

    /**
     * @param  CarbonImmutable  $today  the store's local date
     */
    public function status(CarbonImmutable $today): InstallmentStatus
    {
        return match (true) {
            $this->paid_on !== null => InstallmentStatus::Paid,
            $this->title->canceled_at !== null => InstallmentStatus::Canceled,
            $this->due_on->format('Y-m-d') < $today->format('Y-m-d') => InstallmentStatus::Overdue,
            default => InstallmentStatus::Open,
        };
    }
}

<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\TabStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The comanda of a table: from the moment guests sit until the bill is paid.
 * Each round the waiter sends is an order linked to it.
 *
 * @property int $id
 * @property int $store_id
 * @property int $dining_table_id
 * @property int|null $opened_by
 * @property int|null $closed_by
 * @property TabStatus $status
 * @property string|null $customer_name
 * @property int|null $people
 * @property int $subtotal_cents
 * @property int $service_fee_cents
 * @property int $discount_cents
 * @property int $total_cents
 * @property string|null $payment_method
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $bill_requested_at
 * @property CarbonImmutable|null $closed_at
 */
#[Fillable([
    'store_id', 'dining_table_id', 'opened_by', 'closed_by', 'status', 'customer_name', 'people',
    'subtotal_cents', 'service_fee_cents', 'discount_cents', 'total_cents', 'payment_method',
    'opened_at', 'bill_requested_at', 'closed_at',
])]
class Tab extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TabStatus::class,
            'people' => 'integer',
            'subtotal_cents' => 'integer',
            'service_fee_cents' => 'integer',
            'discount_cents' => 'integer',
            'total_cents' => 'integer',
            'opened_at' => 'datetime',
            'bill_requested_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * What was consumed so far: every round that was not cancelled.
     */
    public function consumedCents(): int
    {
        return (int) $this->orders()->where('status', '!=', OrderStatus::Cancelled)->sum('total_cents');
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<DiningTable, $this>
     */
    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<CashMovement, $this>
     */
    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    /**
     * Other tables joined to this tab (the group spread over several tables).
     *
     * @return HasMany<DiningTable, $this>
     */
    public function joinedTables(): HasMany
    {
        return $this->hasMany(DiningTable::class, 'joined_tab_id');
    }

    /**
     * "Mesa 3" or, for joined tables, "Mesa 3 + Mesa 4".
     */
    public function tablesLabel(): string
    {
        $joined = $this->joinedTables()->orderBy('sort_order')->orderBy('id')->pluck('name')->all();

        // Inside the model "$this->table" is Eloquent's table name, not the relation.
        return implode(' + ', [(string) $this->table()->value('name'), ...$joined]);
    }
}

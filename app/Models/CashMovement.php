<?php

namespace App\Models;

use App\Enums\CashMovementType;
use App\Enums\PaymentMethod;
use App\Services\Ledger\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $store_id
 * @property int $cash_session_id
 * @property int|null $order_id
 * @property int|null $tab_id
 * @property int|null $user_id
 * @property CashMovementType $type
 * @property PaymentMethod|null $payment_method
 * @property int $amount_cents
 * @property string|null $description
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['store_id', 'cash_session_id', 'order_id', 'tab_id', 'user_id', 'type', 'payment_method', 'amount_cents', 'description'])]
class CashMovement extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        // Sales and refunds go to the ledger once the cash register has committed them. A failure
        // there is logged and never undoes the sale; app:rebuild-ledger records it later.
        static::created(fn (CashMovement $movement) => DB::afterCommit(fn () => rescue(fn () => app(Ledger::class)->recordCashMovement($movement))));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CashMovementType::class,
            'payment_method' => PaymentMethod::class,
            'amount_cents' => 'integer',
        ];
    }

    public function signedCents(): int
    {
        return $this->type->sign() * $this->amount_cents;
    }

    /**
     * @return BelongsTo<CashSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use App\Enums\OnlinePaymentMethod;
use App\Enums\PaymentProvider;
use App\Services\Ledger\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One charge of an order at a provider (a Pix code, a card checkout, a Bitcoin invoice).
 *
 * @property int $id
 * @property int $store_id
 * @property int $order_id
 * @property int|null $payment_account_id
 * @property PaymentProvider $provider
 * @property OnlinePaymentMethod $method
 * @property string|null $provider_ref
 * @property string $status pending | paid | expired | failed | refunded
 * @property int $amount_cents
 * @property string|null $pix_code
 * @property string|null $checkout_url
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $refunded_at
 * @property CarbonImmutable|null $last_checked_at
 * @property string|null $note
 * @property-read Order $order
 * @property-read PaymentAccount|null $account
 */
#[Fillable([
    'store_id', 'order_id', 'payment_account_id', 'provider', 'method', 'provider_ref', 'status', 'amount_cents',
    'pix_code', 'checkout_url', 'expires_at', 'paid_at', 'refunded_at', 'last_checked_at', 'note',
])]
class OnlinePayment extends Model
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const EXPIRED = 'expired';

    public const FAILED = 'failed';

    public const REFUNDED = 'refunded';

    protected static function booted(): void
    {
        // Paid or refunded: the ledger records it once the change is committed. A failure there is
        // logged and never blocks the payment; app:rebuild-ledger records it later.
        static::updated(function (OnlinePayment $payment): void {
            if ($payment->wasChanged('status') && in_array($payment->status, [self::PAID, self::REFUNDED], true)) {
                DB::afterCommit(fn () => rescue(fn () => app(Ledger::class)->recordOnlinePayment($payment->fresh() ?? $payment)));
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'method' => OnlinePaymentMethod::class,
            'amount_cents' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<PaymentAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class, 'payment_account_id');
    }

    /**
     * Reference sent to the provider so its notifications point back to this charge.
     */
    public function externalReference(): string
    {
        return "mx-{$this->id}";
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PAID => 'Pago',
            self::EXPIRED => 'Expirado',
            self::FAILED => 'Recusado',
            self::REFUNDED => 'Estornado',
            default => 'Aguardando pagamento',
        };
    }
}

<?php

namespace App\Models;

use App\Enums\Asset;
use App\Enums\OnlinePaymentMethod;
use App\Enums\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One amount received (in) or given back (out), in the asset it moved, with its value in reais.
 *
 * @property int $id
 * @property int $store_id
 * @property int|null $order_id
 * @property CarbonImmutable $occurred_at
 * @property Asset $asset
 * @property int|null $units signed, in the asset's smallest unit
 * @property int $brl_cents signed
 * @property string|null $rate reais per whole unit of the asset
 * @property string $method
 * @property string $source_type
 * @property int $source_id
 * @property string $kind in | out
 * @property string|null $description
 * @property CarbonImmutable|null $created_at
 * @property-read Order|null $order
 */
#[Fillable(['store_id', 'order_id', 'occurred_at', 'asset', 'units', 'brl_cents', 'rate', 'method', 'source_type', 'source_id', 'kind', 'description'])]
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    public const IN = 'in';

    public const OUT = 'out';

    public const FROM_CASH = 'cash_movement';

    public const FROM_ONLINE = 'online_payment';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'asset' => Asset::class,
            'units' => 'integer',
            'brl_cents' => 'integer',
            'rate' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function methodLabel(): string
    {
        return PaymentMethod::tryFrom($this->method)?->label()
            ?? OnlinePaymentMethod::tryFrom($this->method)?->orderLabel()
            ?? $this->method;
    }
}

<?php

namespace App\Models;

use App\Enums\MessageEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $store_id
 * @property int|null $order_id
 * @property MessageEvent $event
 * @property string $recipient
 * @property string $body
 * @property string $status
 * @property string|null $error
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable $created_at
 */
#[Fillable(['store_id', 'order_id', 'event', 'channel', 'recipient', 'body', 'status', 'error', 'sent_at'])]
class MessageLog extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => MessageEvent::class,
            'sent_at' => 'datetime',
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
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

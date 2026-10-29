<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ticket waiting for (or done by) a printer. It only leaves the queue when the agent
 * confirms; a job handed out but never confirmed is handed out again.
 *
 * @property int $id
 * @property int $store_id
 * @property int $printer_id
 * @property int|null $order_id
 * @property string $kind
 * @property string $payload base64 ESC/POS
 * @property string $status
 * @property int $attempts
 * @property string|null $error
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $printed_at
 * @property CarbonImmutable $created_at
 */
#[Fillable(['store_id', 'printer_id', 'order_id', 'kind', 'payload', 'status', 'attempts', 'error', 'claimed_at', 'printed_at'])]
class PrintJob extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_PRINTED = 'printed';

    public const STATUS_FAILED = 'failed';

    /** A job handed to an agent and not confirmed within this time goes back to the queue. */
    public const RETRY_AFTER_SECONDS = 90;

    public const MAX_ATTEMPTS = 5;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
            'printed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Printer, $this>
     */
    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

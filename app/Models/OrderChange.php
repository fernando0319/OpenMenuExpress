<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One edit of an order.
 *
 * @phpstan-type ItemChange array{kind: 'add'|'remove'|'note', quantity: int, name: string, options: list<string>, notes: string|null}
 *
 * @property int $id
 * @property int $store_id
 * @property int $order_id
 * @property int|null $user_id
 * @property string|null $reason
 * @property list<ItemChange> $items
 * @property list<string> $fields
 * @property int $total_before_cents
 * @property int $total_after_cents
 * @property int $refunded_cents
 * @property CarbonImmutable|null $created_at
 * @property-read Order $order
 * @property-read User|null $user
 */
#[Fillable(['store_id', 'order_id', 'user_id', 'reason', 'items', 'fields', 'total_before_cents', 'total_after_cents', 'refunded_cents', 'created_at'])]
class OrderChange extends Model
{
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'items' => 'array',
            'fields' => 'array',
            'total_before_cents' => 'integer',
            'total_after_cents' => 'integer',
            'refunded_cents' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Something the kitchen must know about (items in or out, or a note on an item).
     */
    public function touchesItems(): bool
    {
        return $this->items !== [];
    }

    /**
     * "+2 X-Bacon (Bacon extra)", "−1 Coca-Cola", for the history and messages.
     *
     * @return list<string>
     */
    public function itemLines(): array
    {
        return array_map(function (array $item): string {
            $options = $item['options'] !== [] ? ' ('.implode(', ', $item['options']).')' : '';

            return match ($item['kind']) {
                'add' => "+{$item['quantity']} {$item['name']}{$options}",
                'remove' => "−{$item['quantity']} {$item['name']}{$options}",
                'note' => "{$item['name']}: obs. \"".($item['notes'] ?? '').'"',
            };
        }, $this->items);
    }
}

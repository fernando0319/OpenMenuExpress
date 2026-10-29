<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Writes a priced order with its items and first history entry.
 * Must run inside the caller's transaction: the number is taken under a lock on the store row.
 */
final class OrderRecorder
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<PricedLine>  $lines
     */
    public function record(Store $store, array $attributes, array $lines, OrderStatus $status, ?User $by = null): Order
    {
        Store::query()->whereKey($store->id)->lockForUpdate()->first();
        $number = (int) $store->orders()->max('number') + 1;

        $order = $store->orders()->create([
            ...$attributes,
            'number' => $number,
            'public_token' => Str::random(32),
            'status' => $status,
            'placed_at' => now(),
        ]);

        foreach ($lines as $line) {
            $item = $order->items()->create([
                'product_id' => $line->product->id,
                'product_name' => $line->product->name,
                'unit_price_cents' => $line->price->unitBaseCents,
                'options_price_cents' => $line->price->optionsPerUnitCents,
                'quantity' => $line->price->quantity,
                'total_cents' => $line->price->totalCents(),
                'notes' => $line->notes,
            ]);

            foreach ($line->options as $chosen) {
                $item->options()->create($chosen);
            }
        }

        $order->statusHistory()->create([
            'from_status' => null,
            'to_status' => $status,
            'user_id' => $by?->id,
            'created_at' => now(),
        ]);

        return $order;
    }
}

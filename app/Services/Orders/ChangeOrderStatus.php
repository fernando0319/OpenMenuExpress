<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChangeOrderStatus
{
    public function handle(Order $order, OrderStatus $to, ?User $by = null): Order
    {
        $from = DB::transaction(function () use ($order, $to, $by): OrderStatus {
            // Re-read under lock: two people clicking at once must not both apply.
            $current = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $from = $current->status;

            if (! $from->canMoveTo($to, $current->fulfillment)) {
                throw ValidationException::withMessages([
                    'status' => "Não é possível mudar de \"{$from->label()}\" para \"{$to->label()}\".",
                ]);
            }

            $current->update(['status' => $to]);

            $current->statusHistory()->create([
                'from_status' => $from,
                'to_status' => $to,
                'user_id' => $by?->id,
                'created_at' => now(),
            ]);

            if ($to === OrderStatus::Cancelled) {
                $this->restock($current);

                // A cancelled order gives the coupon use back.
                CouponRedemption::query()->where('order_id', $current->id)->delete();
            }

            return $from;
        });

        $order->refresh();

        OrderStatusChanged::dispatch($order, $from, $to, $by);

        return $order;
    }

    /**
     * Gives back the units a cancelled order had reserved.
     */
    private function restock(Order $order): void
    {
        foreach ($order->items()->whereNotNull('product_id')->get() as $item) {
            Product::query()
                ->withTrashed()
                ->whereKey($item->product_id)
                ->where('track_stock', true)
                ->increment('stock_quantity', $item->quantity);
        }
    }
}

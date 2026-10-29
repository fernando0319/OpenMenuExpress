<?php

namespace App\Http\Controllers\Orders;

use App\Enums\OrderStatus;
use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\OnlinePayment;
use App\Payments\OnlinePayments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Orders waiting to be accepted, or a Pix straight to the store's key waiting to be confirmed.
 * Polled by every panel page so the alert sound keeps ringing, wherever the team is, until
 * someone takes care of them.
 */
class PendingOrdersController extends Controller
{
    use InteractsWithCurrentStore;

    public function __invoke(): JsonResponse
    {
        $pending = $this->currentStore()->orders()
            ->where(fn (Builder $query) => $query
                ->where('status', OrderStatus::Pending)
                ->orWhere(fn (Builder $query) => $query
                    ->where('status', OrderStatus::PendingPayment)
                    ->whereHas('onlinePayments', fn (Builder $query) => $query
                        ->where('status', OnlinePayment::PENDING)
                        ->whereIn('provider', OnlinePayments::manualProviders()))))
            ->orderBy('placed_at')
            ->get(['id', 'number', 'customer_name', 'total_cents']);

        return response()->json([
            'count' => $pending->count(),
            'oldest' => $pending->first()?->only(['id', 'number', 'customer_name', 'total_cents']),
        ]);
    }
}

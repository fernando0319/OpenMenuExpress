<?php

namespace App\Http\Controllers\Orders;

use App\Enums\OrderStatus;
use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Orders waiting to be accepted. Polled by every panel page so the alert sound keeps ringing,
 * wherever the team is, until someone accepts them.
 */
class PendingOrdersController extends Controller
{
    use InteractsWithCurrentStore;

    public function __invoke(): JsonResponse
    {
        $pending = $this->currentStore()->orders()
            ->where('status', OrderStatus::Pending)
            ->orderBy('placed_at')
            ->get(['id', 'number', 'customer_name', 'total_cents']);

        return response()->json([
            'count' => $pending->count(),
            'oldest' => $pending->first()?->only(['id', 'number', 'customer_name', 'total_cents']),
        ]);
    }
}

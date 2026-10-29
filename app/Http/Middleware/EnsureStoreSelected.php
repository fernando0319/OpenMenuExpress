<?php

namespace App\Http\Middleware;

use App\Services\Billing\Subscription;
use App\Support\CurrentStore;
use App\Support\SupportSession;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends users without a store to the store creation screen, and blocks the panel of a
 * store suspended by the platform admin.
 */
class EnsureStoreSelected
{
    public function __construct(private readonly CurrentStore $currentStore) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $store = $this->currentStore->get();

        if ($store === null) {
            // Network couriers have no store: their place is the courier app.
            return $request->user()?->networkCourier()->exists()
                ? redirect()->route('courier.home')
                : redirect()->route('stores.create');
        }

        // Support can still open a suspended store, to look into it.
        if ($store->isSuspended() && SupportSession::current($request) === null) {
            return Inertia::render('StoreSuspended', [
                'storeName' => $store->name,
                'reason' => $store->suspension_reason,
            ])->toResponse($request)->setStatusCode(403);
        }

        // Expired subscription: only the subscription screen, to enter a voucher (support still gets in).
        if (! $request->routeIs('billing.*') && SupportSession::current($request) === null && Subscription::for($store)->isBlocked()) {
            return redirect()->route('billing.show');
        }

        return $next($request);
    }
}

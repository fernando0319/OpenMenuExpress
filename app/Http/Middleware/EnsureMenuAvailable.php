<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Services\Billing\Subscription;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Takes the menu of a store suspended by the platform admin off the air,
 * before anything else (validation included) runs.
 */
class EnsureMenuAvailable
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('storeSlug');
        $store = is_string($slug) ? Store::query()->where('slug', $slug)->first() : null;

        // Suspended by the admin, or the subscription expired past the grace period.
        if ($store === null || (! $store->isSuspended() && ! Subscription::for($store)->isBlocked())) {
            return $next($request);
        }

        if ($request->isMethod('GET') && $request->route()?->getName() === 'menu.show') {
            return Inertia::render('menu/Unavailable', ['storeName' => $store->name])
                ->toResponse($request)
                ->setStatusCode(503);
        }

        abort(503, 'Cardápio indisponível no momento.');
    }
}

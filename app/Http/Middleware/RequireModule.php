<?php

namespace App\Http\Middleware;

use App\Enums\Module;
use App\Services\Billing\Subscription;
use App\Support\CurrentStore;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Screens of a module outside the store's plan show an invitation to change plans.
 * Usage on routes: ->middleware('module:pos').
 */
class RequireModule
{
    public function __construct(private readonly CurrentStore $currentStore) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $store = $this->currentStore->get();
        $required = Module::from($module);

        if ($store === null || Subscription::for($store)->hasModule($required)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => "O plano da loja não inclui {$required->label()}."], 403);
        }

        return Inertia::render('billing/Upgrade', [
            'module' => ['value' => $required->value, 'label' => $required->label(), 'description' => $required->description()],
        ])->toResponse($request)->setStatusCode(403);
    }
}

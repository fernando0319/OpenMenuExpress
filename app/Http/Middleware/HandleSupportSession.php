<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Admin\SupportAccess;
use App\Support\SupportSession;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces support mode: ends it when the time is up, blocks changes in read-only mode,
 * and logs every change made in full mode.
 */
class HandleSupportSession
{
    public function __construct(private readonly SupportAccess $access) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $session = SupportSession::raw($request);
        $user = $request->user();

        if ($session === null) {
            return $next($request);
        }

        // Admin access was revoked mid-session, or someone else signed in on this browser.
        if (! $user instanceof User || $user->is_super_admin !== true) {
            SupportSession::stop($request);

            return $next($request);
        }

        // The admin's own panel and leaving support always work.
        if ($this->isAdminRoute($request)) {
            return $next($request);
        }

        if (SupportSession::isExpired($session)) {
            $storeId = $this->access->end($request, $user, 'expired');
            Inertia::flash('toast', ['type' => 'warning', 'message' => 'O modo suporte expirou após '.SupportSession::MINUTES.' minutos.']);

            return redirect()->route('admin.stores.show', $storeId);
        }

        if ($this->changesData($request)) {
            if ($session['mode'] === SupportSession::MODE_READ) {
                Inertia::flash('toast', ['type' => 'warning', 'message' => 'Modo suporte somente leitura: nada foi alterado.']);

                return $request->expectsJson()
                    ? response()->json(['message' => 'Modo suporte somente leitura.'], 403)
                    : back(303);
            }

            $this->access->recordChange($request, $user, $session['store_id']);
        }

        return $next($request);
    }

    private function changesData(Request $request): bool
    {
        return ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            && $request->route()?->getName() !== 'logout';
    }

    private function isAdminRoute(Request $request): bool
    {
        return str_starts_with((string) $request->route()?->getName(), 'admin.');
    }
}

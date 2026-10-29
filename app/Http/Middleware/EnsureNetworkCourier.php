<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The courier app: people without a network registration are sent to sign up.
 */
class EnsureNetworkCourier
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->networkCourier()->exists()) {
            return redirect()->route('courier.register');
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\PrintAgent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The agent sends "Authorization: Bearer <token>"; only a hash of the token is stored.
 */
class AuthenticatePrintAgent
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        $agent = $token === null
            ? null
            : PrintAgent::query()->where('token_hash', PrintAgent::hashToken($token))->first();

        if ($agent === null) {
            return response()->json(['message' => 'Agente não autorizado. Vincule novamente pelo painel.'], 401);
        }

        $agent->forceFill(['last_seen_at' => now()])->save();
        $request->attributes->set('printAgent', $agent);

        return $next($request);
    }
}

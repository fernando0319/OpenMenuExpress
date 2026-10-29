<?php

namespace App\Http\Responses;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * After signing in, network couriers without a store go straight to the courier app.
 */
class LoginResponse implements LoginResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['two_factor' => false]);
        }

        $user = $request->user();

        if ($user instanceof User && ! $user->stores()->exists() && $user->networkCourier()->exists()) {
            return redirect()->intended(route('courier.home'));
        }

        return redirect()->intended(Fortify::redirects('login'));
    }
}

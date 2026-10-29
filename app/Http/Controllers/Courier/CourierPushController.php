<?php

namespace App\Http\Controllers\Courier;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Push\PushNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registers the phone that accepted notifications, so runs reach it with the app closed.
 */
class CourierPushController extends Controller
{
    public function store(Request $request, PushNotifications $push): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url:https', 'max:1000'],
            'keys.p256dh' => ['required', 'string', 'max:200'],
            'keys.auth' => ['required', 'string', 'max:100'],
            'contentEncoding' => ['nullable', 'in:aes128gcm,aesgcm'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $push->subscribe($user, [
            'endpoint' => $data['endpoint'],
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'content_encoding' => $data['contentEncoding'] ?? null,
        ], $request->userAgent());

        return response()->json(['subscribed' => true]);
    }
}

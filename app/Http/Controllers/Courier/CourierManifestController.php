<?php

namespace App\Http\Controllers\Courier;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Web app manifest of the courier app, so the phone can install it on the home screen.
 */
class CourierManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'name' => 'OpenMenuExpress Entregador',
            'short_name' => 'Entregador',
            'description' => 'Corridas das lojas OpenMenuExpress perto de você.',
            'id' => '/entregador',
            'start_url' => '/entregador',
            'scope' => '/entregador',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#ffffff',
            'theme_color' => '#7b5cff',
            'lang' => 'pt-BR',
            'icons' => [
                ['src' => '/images/courier-icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/images/courier-icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/images/courier-icon-maskable.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }
}

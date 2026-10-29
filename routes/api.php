<?php

use App\Http\Controllers\Api\PrintAgentController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Middleware\AuthenticatePrintAgent;
use Illuminate\Support\Facades\Route;

Route::prefix('print-agent')->name('api.print-agent.')->group(function () {
    Route::post('pair', [PrintAgentController::class, 'pair'])
        ->middleware('throttle:10,1')
        ->name('pair');

    Route::middleware([AuthenticatePrintAgent::class, 'throttle:120,1'])->group(function () {
        Route::post('heartbeat', [PrintAgentController::class, 'heartbeat'])->name('heartbeat');
        Route::get('jobs', [PrintAgentController::class, 'jobs'])->name('jobs');
        Route::post('jobs/{job}/result', [PrintAgentController::class, 'result'])->name('jobs.result');
    });
});

// Payment providers' notifications (Mercado Pago, Sicoob Pix appends "/pix", BTCPay).
Route::post('webhooks/payments/{token}/{suffix?}', PaymentWebhookController::class)
    ->where(['token' => '[A-Za-z0-9]{40,64}', 'suffix' => 'pix'])
    ->middleware('throttle:240,1')
    ->name('payments.webhook');

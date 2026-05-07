<?php

use App\Http\Controllers\Api\GeocodeController;
use App\Http\Controllers\Api\PushSubscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the api middleware group automatically
| (throttle:api, SubstituteBindings). They receive the /api prefix.
|
*/

Route::middleware(['throttle:api'])->prefix('v1')->group(function () {

    // ── Public Endpoints (no auth required) ────────────

    Route::post('geocode', [GeocodeController::class, 'geocode'])
        ->name('api.geocode');

    Route::get('push/vapid-public-key', [PushSubscriptionController::class, 'vapidPublicKey'])
        ->name('api.push.vapid-public-key');

    // ── Authenticated Endpoints ────────────────────────

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('push/subscribe', [PushSubscriptionController::class, 'subscribe'])
            ->name('api.push.subscribe');

        Route::delete('push/subscribe', [PushSubscriptionController::class, 'unsubscribe'])
            ->name('api.push.unsubscribe');
    });
});

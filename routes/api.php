<?php

use App\Http\Controllers\Api\CitiesSuggestController;
use App\Http\Controllers\Api\GeocodeController;
use App\Http\Controllers\Api\PushSubscriptionController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the api middleware group automatically
| (throttle:api, SubstituteBindings). They receive the /api prefix.
|
*/

// ── Legacy Redirects (POST/DELETE — outside CSRF) ────
// Service workers can't send CSRF tokens, so these must live
// in the api middleware group, not web.

Route::post('/push/subscribe', fn () => redirect('/api/v1/push/subscribe', 308));
Route::delete('/push/subscribe', fn () => redirect('/api/v1/push/subscribe', 308));

Route::middleware(['throttle:api'])->prefix('v1')->group(function () {

    // ── Public Endpoints (no auth required) ────────────

    Route::post('geocode', [GeocodeController::class, 'geocode'])
        ->name('api.geocode');

    Route::get('cities/suggest', CitiesSuggestController::class)
        ->name('api.cities.suggest');

    Route::get('push/vapid-public-key', [PushSubscriptionController::class, 'vapidPublicKey'])
        ->name('api.push.vapid-public-key');

    // ── Authenticated Endpoints ────────────────────────

    // EnsureFrontendRequestsAreStateful: the first-party frontend calls these
    // routes with session cookies (push.js fetch uses credentials:
    // 'same-origin'), not bearer tokens. Without Sanctum's SPA-stateful
    // middleware the session is never booted on API routes, so auth:sanctum
    // 401s every browser subscribe/unsubscribe (observed live 2026-11-15:
    // Enable/Disable push never worked from the settings UI — actingAs-based
    // tests seed the guard directly and cannot catch this).
    Route::middleware([EnsureFrontendRequestsAreStateful::class, 'auth:sanctum', 'not.disabled'])->group(function () {
        Route::post('push/subscribe', [PushSubscriptionController::class, 'subscribe'])
            ->name('api.push.subscribe');

        Route::delete('push/subscribe', [PushSubscriptionController::class, 'unsubscribe'])
            ->name('api.push.unsubscribe');
    });
});

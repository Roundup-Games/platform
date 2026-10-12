<?php

use App\Support\SystemHealth;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Health Route (MEM1147)
|--------------------------------------------------------------------------
|
| Dependency-aware health endpoint: 200 OK when database, Redis, and the
| cache store all answer; 503 with the failing check names otherwise.
| Loaded before all routes; the docker healthcheck gates on it, so a
| dependency outage marks the container unhealthy instead of letting it
| serve 500s while looking healthy.
|
*/

Route::get('/up', function (): Response {
    $checks = SystemHealth::checks();

    return response(
        SystemHealth::healthy() ? 'OK' : json_encode($checks),
        SystemHealth::healthy() ? 200 : 503,
    );
});

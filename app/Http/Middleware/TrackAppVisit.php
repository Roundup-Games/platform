<?php

namespace App\Http\Middleware;

use App\Models\UserAppVisit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class TrackAppVisit
{
    /**
     * Record a daily visit for authenticated users.
     *
     * Uses upsert to ensure idempotency — multiple requests on the same
     * date produce exactly one row per user.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $today = now()->toDateString();

            UserAppVisit::upsert(
                ['user_id' => $user->id, 'visit_date' => $today],
                ['user_id', 'visit_date'],
            );

            Log::channel('daily')->info('pwa.visit.tracked', [
                'user_id' => $user->id,
                'visit_date' => $today,
            ]);
        }

        return $next($request);
    }
}

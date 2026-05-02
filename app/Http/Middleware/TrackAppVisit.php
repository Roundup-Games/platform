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
     * date produce exactly one row per user. Only tracks page-level GET
     * requests, skipping API calls, Livewire updates, and non-GET methods.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $this->shouldTrack($request)) {
            $today = now()->toDateString();

            UserAppVisit::upsert(
                [
                    'id' => (string) \Illuminate\Support\Str::orderedUuid(),
                    'user_id' => $user->id,
                    'visit_date' => $today,
                ],
                ['user_id', 'visit_date'],
            );

            Log::channel('daily')->debug('pwa.visit.tracked', [
                'user_id' => $user->id,
                'visit_date' => $today,
            ]);
        }

        return $next($request);
    }

    /**
     * Only track actual page visits, not API calls or internal requests.
     */
    private function shouldTrack(Request $request): bool
    {
        return $request->isMethod('GET')
            && !$request->is('api/*')
            && !$request->header('X-Livewire');
    }
}

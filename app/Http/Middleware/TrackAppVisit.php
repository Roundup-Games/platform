<?php

namespace App\Http\Middleware;

use App\Models\UserAppVisit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

            // One visit row per user per day. The cache check is atomic and
            // replaces an INSERT..ON CONFLICT round-trip on every page view —
            // idempotent data was being re-written N times per session.
            if (Cache::add("pwa:visit:{$user->id}:{$today}", true, now()->addDay())) {
                try {
                    UserAppVisit::upsert(
                        [
                            'id' => (string) Str::orderedUuid(),
                            'user_id' => $user->id,
                            'visit_date' => $today,
                        ],
                        ['user_id', 'visit_date'],
                    );
                } catch (\Throwable $e) {
                    // Release the daily gate so the next request retries the
                    // write — otherwise a transient DB failure would suppress
                    // visit tracking for the rest of the day.
                    Cache::forget("pwa:visit:{$user->id}:{$today}");

                    throw $e;
                }

                Log::debug('pwa.visit.tracked', [
                    'user_id' => $user->id,
                    'visit_date' => $today,
                ]);
            }
        }

        return $next($request);
    }

    /**
     * Only track actual page visits, not API calls or internal requests.
     */
    private function shouldTrack(Request $request): bool
    {
        return $request->isMethod('GET')
            && ! $request->is('api/*')
            && ! $request->header('X-Livewire');
    }
}

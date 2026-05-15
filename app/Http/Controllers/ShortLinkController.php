<?php

namespace App\Http\Controllers;

use App\Jobs\RecordShortLinkHit;
use App\Models\ShortLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Handles short link resolution and redirect.
 *
 * Flow: /link/{code} → cache lookup → expiration/cap checks →
 *       dispatch analytics job → set PostHog cookie → 302 redirect.
 */
class ShortLinkController extends Controller
{
    /**
     * Maximum cache miss threshold per IP before rate-limiting.
     */
    protected const MISS_THRESHOLD = 50;

    /**
     * TTL for the miss counter window (seconds).
     */
    protected const MISS_TTL = 300;

    /**
     * TTL for the short link cache entry.
     */
    protected const CACHE_TTL_HOURS = 6;

    /**
     * Resolve a short link code and redirect to the target URL.
     */
    public function redirect(Request $request, string $code): RedirectResponse
    {
        $ip = $request->ip();

        // ── Miss counter: guard against enumeration / brute-force ──────
        $missKey = "short_link_misses:{$ip}";
        $misses = Cache::get($missKey, 0);

        if ($misses >= static::MISS_THRESHOLD) {
            Log::warning('short_link.redirect.miss_threshold_exceeded', [
                'code' => $code,
                'ip' => $ip,
                'misses' => $misses,
            ]);

            throw new HttpException(429, 'Too many requests. Please try again later.');
        }

        // ── Cache lookup ───────────────────────────────────────────────
        $cacheKey = "short_link:{$code}";
        $link = Cache::remember($cacheKey, now()->addHours(static::CACHE_TTL_HOURS), function () use ($code): ?ShortLink {
            return ShortLink::where('code', $code)->first();
        });

        // ── Not found ──────────────────────────────────────────────────
        if ($link === null) {
            Cache::increment($missKey);
            // Ensure TTL is set even on first miss
            if (Cache::get($missKey) === 1) {
                Cache::put($missKey, 1, static::MISS_TTL);
            }

            Log::debug('short_link.redirect.not_found', [
                'code' => $code,
                'ip' => $ip,
            ]);

            abort(404, 'Short link not found.');
        }

        // ── Expiration check (application-level on cached data) ────────
        if ($link->isExpired()) {
            Cache::forget($cacheKey);

            Log::debug('short_link.redirect.expired', [
                'code' => $code,
                'ip' => $ip,
                'expires_at' => $link->expires_at?->toIso8601String(),
            ]);

            abort(404, 'Short link not found.');
        }

        // ── Hit cap check ──────────────────────────────────────────────
        if ($link->hasHitCap()) {
            Cache::forget($cacheKey);

            Log::debug('short_link.redirect.hit_cap_exceeded', [
                'code' => $code,
                'ip' => $ip,
                'hit_count' => $link->hit_count,
                'max_hits' => $link->max_hits,
            ]);

            // 404 — no information leakage about whether the link exists
            abort(404, 'Short link not found.');
        }

        // ── Dispatch async analytics ───────────────────────────────────
        RecordShortLinkHit::dispatch(
            $link->id,
            $ip,
            $request->header('referer'),
            $request->header('User-Agent'),
        );

        // ── PostHog stitching cookie ───────────────────────────────────
        // Sets ph_link_id so client-side PostHog can stitch anonymous → identified.
        $cookie = cookie('ph_link_id', (string) $link->id, 60); // 60 minutes

        // ── Short link intent cookie for guest-to-auth participant creation ──
        // Encrypted cookie carrying entity context so ProcessShareIntent can
        // create participants from short link arrivals after auth.
        $intentCookie = cookie('short_link_intent', json_encode([
            'entity_type' => strtolower(class_basename($link->linkable_type)),
            'entity_id' => $link->linkable_id,
            'short_link_id' => $link->id,
        ]), 24 * 60); // 24 hours

        Log::info('short_link.redirect.success', [
            'code' => $code,
            'link_id' => $link->id,
            'ip' => $ip,
            'url' => $link->url,
        ]);

        // ── 302 redirect ───────────────────────────────────────────────
        return redirect($link->url, 302)
            ->withHeaders([
                'Cache-Control' => 'no-cache, no-store',
            ])
            ->withCookie($cookie)
            ->withCookie($intentCookie);
    }
}

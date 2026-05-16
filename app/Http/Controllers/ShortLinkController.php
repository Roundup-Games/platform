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
                'code_prefix' => substr($code, 0, 3) . '…',
                'ip_hash' => hash_hmac('sha256', (string) $ip, (string) config('app.key')),
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
            Cache::add($missKey, 0, static::MISS_TTL);
            $newMisses = Cache::increment($missKey);

            Log::debug('short_link.redirect.not_found', [
                'code_prefix' => substr($code, 0, 3) . '…',
                'ip_hash' => hash_hmac('sha256', (string) $ip, (string) config('app.key')),
                'misses' => $newMisses,
            ]);

            abort(404, 'Short link not found.');
        }

        // ── Freshness check: re-fetch authoritative fields from DB ─────
        // The cached model may predate an expiry change (via expireLinksForEntity)
        // or hit counter increment. A single lightweight query keeps both the
        // expiry and hit-cap decisions consistent with resolveLinkById().
        $fresh = ShortLink::where('id', $link->id)
            ->whereNull('deleted_at')
            ->first(['expires_at', 'max_hits', 'hit_count']);

        if ($fresh === null) {
            Cache::forget($cacheKey);
            abort(404, 'Short link not found.');
        }

        // Overlay fresh values onto cached model for isExpired/hasHitCap
        $link->expires_at = $fresh->expires_at;
        $link->max_hits = $fresh->max_hits;
        $link->hit_count = $fresh->hit_count;

        if ($link->isExpired()) {
            Cache::forget($cacheKey);

            Log::debug('short_link.redirect.expired', [
                'code_prefix' => substr($code, 0, 3) . '…',
                'ip_hash' => hash_hmac('sha256', (string) $ip, (string) config('app.key')),
                'expires_at' => $link->expires_at?->toIso8601String(),
            ]);

            abort(404, 'Short link not found.');
        }

        if ($link->hasHitCap()) {
            Cache::forget($cacheKey);

            Log::debug('short_link.redirect.hit_cap_exceeded', [
                'code_prefix' => substr($code, 0, 3) . '…',
                'ip_hash' => hash_hmac('sha256', (string) $ip, (string) config('app.key')),
                'hit_count' => $link->hit_count,
                'max_hits' => $link->max_hits,
            ]);

            abort(404, 'Short link not found.');
        }

        // ── Dispatch async analytics ───────────────────────────────────
        try {
            RecordShortLinkHit::dispatch(
                $link->id,
                $ip,
                $request->header('referer'),
                $request->header('User-Agent'),
            );
        } catch (\Throwable $e) {
            Log::warning('short_link.redirect.hit_dispatch_failed', [
                'link_id' => $link->id,
                'error' => $e->getMessage(),
            ]);
        }

        // ── PostHog stitching cookie ───────────────────────────────────
        // Sets ph_link_id so client-side PostHog can stitch anonymous → identified.
        $cookie = cookie('ph_link_id', (string) $link->id, 60); // 60 minutes

        // ── Short link intent cookie for guest-to-auth participant creation ──
        // Encrypted cookie carrying ONLY the short_link_id.
        // ProcessShareIntent derives entity_type and entity_id from the link
        // record itself — no attacker-controlled payload in the cookie.
        $intentCookie = cookie('short_link_intent', json_encode([
            'short_link_id' => $link->id,
        ]), 24 * 60); // 24 hours

        Log::info('short_link.redirect.success', [
            'code_prefix' => substr($code, 0, 3) . '…',
            'link_id' => $link->id,
            'ip_hash' => hash_hmac('sha256', (string) $ip, (string) config('app.key')),
            'url_host' => parse_url($link->url, PHP_URL_HOST),
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

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
        // Reads the current miss count first — if already at threshold, reject
        // immediately. The counter is only incremented on actual misses (code not
        // found) to avoid penalizing legitimate traffic. Uses atomic increment
        // to avoid the Cache::add race where concurrent requests could both add
        // and then both increment from the same base.
        $missKey = "short_link_misses:{$ip}";
        $misses = is_numeric($v = Cache::get($missKey, 0)) ? (int) $v : 0;

        if ($misses >= static::MISS_THRESHOLD) {
            Log::warning('short_link.redirect.miss_threshold_exceeded', [
                'code_prefix' => substr($code, 0, 3).'…',
                'ip_hash' => hash_hmac('sha256', (string) $ip, is_string($k = config('app.key')) ? $k : ''),
                'misses' => $misses,
            ]);

            throw new HttpException(429, 'Too many requests. Please try again later.');
        }

        // ── Cache lookup ───────────────────────────────────────────────
        // All mutation paths (revokeLink, expireLinksForEntity, RecordShortLinkHit,
        // model events) invalidate both short_link:{code} and short_link_id:{id}
        // caches on every write. No freshness re-fetch is needed — the cache is
        // always consistent after writes.
        $cacheKey = "short_link:{$code}";
        $link = Cache::remember($cacheKey, now()->addHours(static::CACHE_TTL_HOURS), function () use ($code): ?ShortLink {
            return ShortLink::where('code', $code)->first();
        });

        // ── Not found ──────────────────────────────────────────────────
        if ($link === null) {
            // Atomic increment avoids the Cache::add race: two concurrent misses
            // from the same IP will each get a unique incremented value.
            $newMisses = Cache::increment($missKey);
            if ($newMisses === 1 || $newMisses === false) {
                // First miss or key expired — seed with TTL
                Cache::put($missKey, $newMisses === false ? 1 : $newMisses, static::MISS_TTL);
            }

            Log::debug('short_link.redirect.not_found', [
                'code_prefix' => substr($code, 0, 3).'…',
                'ip_hash' => hash_hmac('sha256', (string) $ip, is_string($k = config('app.key')) ? $k : ''),
                'misses' => $newMisses,
            ]);

            abort(404, 'Short link not found.');
        }

        if ($link->trashed()) {
            Cache::forget($cacheKey);
            abort(404, 'Short link not found.');
        }

        if ($link->isExpired()) {
            Cache::forget($cacheKey);

            Log::debug('short_link.redirect.expired', [
                'code_prefix' => substr($code, 0, 3).'…',
                'ip_hash' => hash_hmac('sha256', (string) $ip, is_string($k = config('app.key')) ? $k : ''),
                'expires_at' => $link->expires_at?->toIso8601String(),
            ]);

            abort(404, 'Short link not found.');
        }

        if ($link->hasHitCap()) {
            Cache::forget($cacheKey);

            Log::debug('short_link.redirect.hit_cap_exceeded', [
                'code_prefix' => substr($code, 0, 3).'…',
                'ip_hash' => hash_hmac('sha256', (string) $ip, is_string($k = config('app.key')) ? $k : ''),
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
        ]) ?: '{}', 24 * 60); // 24 hours

        Log::info('short_link.redirect.success', [
            'code_prefix' => substr($code, 0, 3).'…',
            'link_id' => $link->id,
            'ip_hash' => hash_hmac('sha256', (string) $ip, is_string($k = config('app.key')) ? $k : ''),
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

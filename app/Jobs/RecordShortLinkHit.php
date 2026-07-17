<?php

namespace App\Jobs;

use App\Models\ShortLink;
use App\Models\ShortLinkHit;
use App\Services\PostHogClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Async job that records a short link hit and fires the PostHog event.
 *
 * Writes the ShortLinkHit row (IP is hashed before entering the queue) and
 * increments the hit counter on the parent ShortLink inside a DB transaction.
 * After commit, dispatches a link.hit PostHog event for analytics stitching.
 * PostHog failures are caught and logged — analytics never blocks the job.
 *
 * Triggered by: ShortLinkController::redirect() on every successful resolution.
 */
class RecordShortLinkHit implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum retry attempts before marking as failed.
     */
    public int $tries = 3;

    /**
     * Maximum time the job may run before timing out.
     */
    public int $timeout = 30;

    /**
     * Discard the job on failure after all retries exhausted.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Pre-computed PostHog distinctId from raw IP+UA fingerprint.
     * Set at construction time before IP is hashed.
     */
    public string $visitorFingerprint;

    /**
     * SHA-256 hash of the visitor IP (salted with app.key).
     * Set at construction time — raw PII never enters the queue store.
     * Public visibility required for Laravel queue serialization.
     */
    public ?string $hashedIpAddress = null;

    /**
     * Whether the visitor granted analytics consent at redirect time.
     *
     * Consent is captured in ShortLinkController (which has request context)
     * and passed here, mirroring EnrichPostHogProfile. The first-party hit row
     * (count, referer domain, browser family) is always recorded — that is the
     * link owner's operational data. Only the cross-user PostHog event is gated
     * behind consent, since it contributes to platform-wide analytics.
     */
    public bool $hasConsent = false;

    /**
     * @param  int  $shortLinkId  The ID of the ShortLink that was resolved.
     * @param  string|null  $ipAddress  The visitor's raw IP address (hashed in constructor).
     * @param  string|null  $referer  The Referer header value.
     * @param  string|null  $userAgent  The User-Agent header value.
     * @param  bool  $hasConsent  Whether analytics consent was granted at redirect time.
     */
    public function __construct(
        public int $shortLinkId,
        ?string $ipAddress = null,
        public ?string $referer = null,
        public ?string $userAgent = null,
        bool $hasConsent = false,
    ) {
        $this->hasConsent = $hasConsent;
        // Compute the PostHog fingerprint from raw IP+UA before hashing.
        // This gives a consistent anonymous visitor ID without storing raw PII.
        $this->visitorFingerprint = ($ipAddress ?? '') !== '' || ($this->userAgent ?? '') !== ''
            ? 'link:'.hash('xxh128', ($ipAddress ?? '').($this->userAgent ?? ''))
            : 'link:anonymous';

        // Hash IP at construction time so raw PII never enters the queue store.
        // Use HMAC (not plain concatenation hashing): HMAC is the idiomatic MAC,
        // and app.key acts as the secret. Plain sha256(ip+key) is brute-forceable
        // on the small IPv4 space if the key ever leaks; HMAC removes that risk.
        $key = config('app.key');
        $this->hashedIpAddress = $ipAddress !== null && is_string($key)
            ? hash_hmac('sha256', $ipAddress, $key)
            : null;

        // Reduce raw User-Agent PII to a browser family string before it
        // enters the queue store. Retains analytics value (browser distribution)
        // without storing the full UA fingerprint. 90-day hit retention in
        // PruneExpiredShortLinks limits any residual exposure.
        $this->userAgent = $this->userAgent !== null
            ? $this->extractBrowserFamily($this->userAgent)
            : null;

        // Reduce raw referer URL to hostname only before it enters the queue
        // store. Full referer URLs may contain UTM parameters, user IDs, or
        // other PII in query strings. Hostname retains analytics value (traffic
        // source) without the privacy exposure.
        $this->referer = $this->referer !== null
            ? (parse_url($this->referer, PHP_URL_HOST) ?: $this->referer)
            : null;

        $this->onQueue('default');
    }

    /**
     * Extract a short browser family string from a raw User-Agent.
     *
     * Reduces full UA (which can contain OS version, device model, build IDs)
     * to a short analytics-friendly label like "Chrome/Android" or "Safari/iOS".
     * Falls back to "Other" for unrecognized agents.
     */
    private function extractBrowserFamily(string $ua): string
    {
        // Order matters — match specific patterns before generic ones.
        return match (true) {
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Chrome/') && str_contains($ua, 'Android') => 'Chrome/Android',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') && ! str_contains($ua, 'Chrome') => 'Safari',
            str_contains($ua, 'Mozilla/') => 'Other',
            default => 'Bot/Unknown',
        };
    }

    /**
     * Execute the job.
     */
    public function handle(PostHogClient $posthog): void
    {
        $link = ShortLink::find($this->shortLinkId);

        if ($link === null) {
            Log::warning('short_link.hit.link_not_found', [
                'short_link_id' => $this->shortLinkId,
            ]);

            return;
        }

        // Referer was sanitized to hostname-only in constructor, so the domain
        // is simply the referer value itself. No secondary parse_url needed.
        $refererDomain = $this->referer;

        // ── Record hit + update counters in a transaction ────────────
        DB::transaction(function () use ($link, $refererDomain): void {
            $link->hits()->create([
                'ip_address' => $this->hashedIpAddress,
                'referer' => $this->referer,
                'referer_domain' => $refererDomain,
                'user_agent' => $this->userAgent,
                'hit_at' => now(),
            ]);

            // Single UPDATE statement — avoids two separate DB round-trips per hit.
            $link->increment('hit_count', 1, ['last_hit_at' => now()]);
        });

        // Invalidate caches after commit to avoid stale-free entries on rollback.
        // Both cache keys for the same model must be invalidated together so
        // any consumer (controller, policy, workspace) sees fresh hit_count.
        Cache::forget("short_link_id:{$link->id}");
        Cache::forget("short_link:{$link->code}");

        // ── PostHog link.hit event (after transaction commits) ───────
        // Gated behind analytics consent. The first-party hit row above is
        // always recorded (link owner operational data); only the cross-user
        // analytics event requires consent.
        if ($this->hasConsent) {
            try {
                $posthog->capture([
                    'distinctId' => $this->visitorFingerprint,
                    'event' => 'link.hit',
                    'properties' => [
                        'link_id' => $link->id,
                        'link_code' => $link->code,
                        'link_label' => $link->label,
                        'linkable_type' => class_basename($link->linkable_type),
                        'linkable_id' => $link->linkable_id,
                        'referer_domain' => $refererDomain,
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::warning('short_link.hit.posthog_failed', [
                    'short_link_id' => $link->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::debug('short_link.hit.recorded', [
            'short_link_id' => $link->id,
            'code_prefix' => substr($link->code, 0, 3).'…',
        ]);
    }

    /**
     * Handle a job failure after all retries exhausted.
     */
    public function failed(?\Throwable $exception = null): void
    {
        Log::error('short_link.hit.job_failed', [
            'short_link_id' => $this->shortLinkId,
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception ? get_class($exception) : null,
        ]);
    }
}

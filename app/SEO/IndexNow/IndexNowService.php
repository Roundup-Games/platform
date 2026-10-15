<?php

namespace App\SEO\IndexNow;

use App\Jobs\IndexNowSweepJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * IndexNow submission chokepoint (instant indexing: Bing, Yandex, Seznam,
 * Naver). Entity saves hand their URLs to {@see queueFor()}, which parks
 * them in a pending set and schedules a single coalescing sweep — a bulk
 * BGG sync touching thousands of rows produces one delayed submission
 * run, not thousands of HTTP calls.
 *
 * Disabled by design until INDEXNOW_KEY is set: the observer no-ops and
 * the key file route 404s, so the pipeline lights up per environment
 * without code changes.
 */
final class IndexNowService
{
    private const PENDING_KEY = 'seo:indexnow:pending';

    private const LOCK_NAME = 'seo:indexnow:lock';

    /** Debounce window: submissions are delayed so bursts coalesce. */
    private const SWEEP_DELAY_SECONDS = 120;

    /** IndexNow accepts up to 10k URLs per request; small batches are polite. */
    private const BATCH_SIZE = 500;

    public function isEnabled(): bool
    {
        $key = config('services.indexnow.key');

        return is_string($key) && preg_match('/^[a-f0-9]{32}$/', $key) === 1;
    }

    /**
     * Park a model's public URLs for submission and schedule the sweep.
     */
    public function queueFor(object $model): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $urls = app(ResolveIndexNowUrls::class)->for($model);

        if ($urls === []) {
            return;
        }

        $this->withLock(function () use ($urls): void {
            $pending = $this->pendingUrls();
            Cache::put(self::PENDING_KEY, array_values(array_unique([...$pending, ...$urls])));
        });

        IndexNowSweepJob::dispatch()->delay(now()->addSeconds(self::SWEEP_DELAY_SECONDS));

        Log::debug('indexnow.queued', ['urls' => count($urls)]);
    }

    /**
     * Drain the pending set and submit in batches. Returns the number of
     * URLs submitted. URLs are removed BEFORE submission: the protocol is
     * advisory, engines re-check via sitemap anyway, and a lost ping costs
     * nothing while a duplicate storm costs quota.
     */
    public function sweep(): int
    {
        if (! $this->isEnabled()) {
            return 0;
        }

        $urls = [];

        $this->withLock(function () use (&$urls): void {
            $urls = $this->pendingUrls();
            Cache::put(self::PENDING_KEY, []);
        });

        if ($urls === []) {
            return 0;
        }

        $submitted = 0;

        foreach (array_chunk($urls, self::BATCH_SIZE) as $batch) {
            $submitted += $this->submitBatch($batch) ? count($batch) : 0;
        }

        return $submitted;
    }

    /**
     * @param  list<string>  $urls
     */
    private function submitBatch(array $urls): bool
    {
        $key = config('services.indexnow.key');
        $endpoint = config('services.indexnow.endpoint');
        $baseUrl = config('app.url');

        if (! is_string($key) || ! is_string($endpoint) || ! is_string($baseUrl)) {
            return false;
        }

        $host = parse_url($baseUrl, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            $host = 'roundup.games';
        }

        try {
            $response = Http::timeout(15)->post($endpoint, [
                'host' => $host,
                'key' => $key,
                'keyLocation' => rtrim($baseUrl, '/')."/{$key}.txt",
                'urlList' => $urls,
            ]);
        } catch (Throwable $e) {
            Log::warning('indexnow.request_failed', ['error' => $e->getMessage(), 'urls' => count($urls)]);

            return false;
        }

        if ($response->successful()) {
            Log::info('indexnow.submitted', ['urls' => count($urls), 'status' => $response->status()]);

            return true;
        }

        // 400/403/422: malformed payload or key mismatch — worth seeing,
        // never worth failing the queue over. 429/5xx: engines are busy.
        Log::warning('indexnow.rejected', [
            'status' => $response->status(),
            'body' => mb_substr((string) $response->body(), 0, 200),
            'urls' => count($urls),
        ]);

        return false;
    }

    /**
     * Snapshot of the parked URLs (test/inspection accessor).
     *
     * @return list<string>
     */
    public function pendingUrls(): array
    {
        $pending = Cache::get(self::PENDING_KEY, []);

        if (! is_array($pending)) {
            return [];
        }

        return array_values(array_filter($pending, is_string(...)));
    }

    /**
     * Whether URLs are parked waiting for a sweep (re-dispatch guard for
     * {@see IndexNowSweepJob}).
     */
    public function hasPendingUrls(): bool
    {
        return $this->pendingUrls() !== [];
    }

    /**
     * Serialized producer/consumer access to the pending set (Cache::lock
     * works on every store, including the array store used in tests).
     *
     * @param  callable(): void  $callback
     */
    private function withLock(callable $callback): void
    {
        $lock = Cache::lock(self::LOCK_NAME, 10);

        $lock->block(5);

        try {
            $callback();
        } finally {
            $lock->release();
        }
    }
}

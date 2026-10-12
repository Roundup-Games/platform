<?php

namespace App\Support;

use Illuminate\Support\Facades\Redis;

/**
 * The app's OWN cache effectiveness, measured — not Redis's server-global
 * keyspace counters, which on this deployment are dominated by structural
 * noise the app cannot influence: Laravel queue-worker sentinel GETs
 * (illuminate:queue:restart / queues:paused, missing by design on every
 * poll) and other applications sharing the Redis instance.
 *
 * CacheHit/CacheMissed events increment two counters via a direct Redis
 * INCR (bypassing the cache store so recording never recurses). Queue
 * sentinels (illuminate:*) and the metrics' own keys are excluded, so
 * the ratio reflects real application cache traffic only.
 *
 * Counters live since the last Redis flush/restart — same caveat as the
 * server stats, but scoped to traffic we control.
 */
final class CacheMetrics
{
    public const HITS_KEY = 'roundup-games:cache-metrics:hits';

    public const MISSES_KEY = 'roundup-games:cache-metrics:misses';

    /**
     * @return array{hits: int, misses: int, ratio: float|null}
     */
    public static function snapshot(): array
    {
        try {
            $hits = self::toInt(Redis::connection('cache')->get(self::HITS_KEY));
            $misses = self::toInt(Redis::connection('cache')->get(self::MISSES_KEY));
        } catch (\Throwable) {
            return ['hits' => 0, 'misses' => 0, 'ratio' => null];
        }

        $total = $hits + $misses;

        return [
            'hits' => $hits,
            'misses' => $misses,
            'ratio' => $total > 0 ? round($hits / $total * 100, 1) : null,
        ];
    }

    /**
     * Record one cache outcome. Called from the CacheHit/CacheMissed
     * listeners; only real cache keys count (see shouldCount).
     */
    public static function record(string $key, bool $hit): void
    {
        if (! self::shouldCount($key)) {
            return;
        }

        try {
            Redis::connection('cache')->incr($hit ? self::HITS_KEY : self::MISSES_KEY);
        } catch (\Throwable) {
            // Metrics must never break a request — Redis outages already
            // surface through /up and the dashboard widgets.
        }
    }

    /**
     * Exclude traffic that is structural, not cache usage:
     *  - illuminate:* — framework sentinels (queue restart/paused, schedule
     *    mutexes): missing-by-design worker polling.
     *  - this metric's own keys.
     */
    public static function shouldCount(string $key): bool
    {
        return ! str_starts_with($key, 'illuminate:')
            && ! str_starts_with($key, 'roundup-games:cache-metrics');
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}

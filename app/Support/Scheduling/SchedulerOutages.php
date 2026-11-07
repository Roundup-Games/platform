<?php

namespace App\Support\Scheduling;

use App\Models\ScheduledTaskRun;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduler outage windows derived from the recorded run grid (M074,
 * 2026-10-31 outage follow-up).
 *
 * Any scheduled_task_runs row — whatever its task or status — proves the
 * scheduler TICKED in that minute (the every-minute escalated automations
 * and the 5-minute horizon:snapshot make the grid dense on a live box).
 * A silence of MIN_GAP_MINUTES or more between consecutive ticks is a
 * scheduler outage: scheduler downtime, a wedged schedule:work behind a
 * green Horizon, or the whole box being down. Derived, not stored — the
 * grid already carries the truth, and Postgres rotation (30-day prune)
 * bounds how far back windows can exist.
 *
 * Consumers: the Scheduled Tasks page annotates missed rows whose due
 * occurrence fell inside a window (the difference between "known outage
 * artifact, self-clears next occurrence" and "fresh regression"), and
 * renders the recent-windows panel. The health gate does NOT consume
 * this — a missed task is still a missed task, whatever the cause.
 *
 * Resilience rule (same as ScheduleHealth): an outage computation that
 * throws degrades to an empty list — the page loses context, never the
 * ability to render.
 */
final class SchedulerOutages
{
    /**
     * Cache key for the derived window list. Version the suffix whenever
     * the cached array's shape changes (ScheduleHealth precedent) — v2
     * stashes ISO-8601 strings, not Carbon objects, so any stale v1
     * entry (which real stores serve back as __PHP_Incomplete_Class)
     * simply expires unretrieved instead of crashing consumers.
     */
    private const CACHE_KEY = 'admin:scheduler-outages.v2';

    /**
     * How far back windows are derived. 7 days keeps the panel relevant
     * (recent outages) without scanning the full 30-day grid.
     */
    private const LOOKBACK_DAYS = 7;

    /**
     * Minutes of grid silence that constitute an outage. Deploy worker
     * rolls run ≤ ~6 minutes, so short deploy gaps DO surface as small
     * windows — deliberately: a due occurrence lost to a deploy roll is
     * still lost, and the window range is rendered so the admin can see
     * the outage was brief. Anything shorter than 5 minutes is scheduler
     * jitter (task start skew inside a tick), not an outage.
     */
    private const MIN_GAP_MINUTES = 5;

    /**
     * Minute-aligned bounds: start is the last minute that ticked, end
     * the first minute that ticked after the silence ("no ticks between
     * start and end").
     *
     * The cached payload is the SCALAR shape (ISO-8601 strings), never
     * Carbon objects: the app cache unserializes with
     * serializable_classes => false (config/cache.php), so cached
     * objects come back from any real store (redis, file) as
     * __PHP_Incomplete_Class and every consumer crashes on first touch —
     * while the array test store never serializes, which is why the
     * suites stayed green while the page 500'd. Cache scalars and
     * rehydrate — same lesson as Event::offeredSystems().
     *
     * @return list<array{start: Carbon, end: Carbon}> newest first
     */
    public static function windows(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            $windows = self::hydrate($cached);

            if ($windows !== null) {
                return $windows;
            }

            // Foreign/corrupt payload (an object-shaped entry served
            // back as __PHP_Incomplete_Class, or any non-string bound):
            // fall through and recompute — self-heal, never crash.
        }

        $windows = self::compute();

        Cache::put(self::CACHE_KEY, self::stash($windows), now()->addMinutes(5));

        return $windows;
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The window a missed due occurrence fell inside, if any.
     *
     * Strictly-inside semantics: an occurrence AT the window's start
     * minute had its tick (that minute recorded a run), and an occurrence
     * AT the window's end minute was serviced by the resumed tick — both
     * had their chance and are regressions, not outage casualties.
     *
     * @return array{start: Carbon, end: Carbon}|null
     */
    public static function covers(Carbon $occurrence): ?array
    {
        foreach (self::windows() as $window) {
            // Exclusive bounds (third arg) — see the docblock.
            if ($occurrence->isBetween($window['start'], $window['end'], false)) {
                return $window;
            }
        }

        return null;
    }

    /**
     * Scalar cache shape: ISO-8601 strings survive any store's
     * serialization round-trip (allowed_classes=false strips objects;
     * strings pass untouched).
     *
     * @param  list<array{start: Carbon, end: Carbon}>  $windows
     * @return list<array{start: string, end: string}>
     */
    private static function stash(array $windows): array
    {
        return array_map(
            fn (array $window): array => [
                'start' => $window['start']->toIso8601String(),
                'end' => $window['end']->toIso8601String(),
            ],
            $windows,
        );
    }

    /**
     * Rebuild window bounds from the cached scalar shape. All-or-nothing:
     * any entry that fails to rehydrate (or any non-string bound) marks
     * the whole payload foreign and the caller recomputes.
     *
     * @param  array<mixed>  $cached
     * @return list<array{start: Carbon, end: Carbon}>|null
     */
    private static function hydrate(array $cached): ?array
    {
        try {
            $windows = [];

            foreach ($cached as $window) {
                if (! is_array($window)
                    || ! is_string($window['start'] ?? null)
                    || ! is_string($window['end'] ?? null)) {
                    return null;
                }

                $windows[] = [
                    'start' => Carbon::parse($window['start']),
                    'end' => Carbon::parse($window['end']),
                ];
            }

            return $windows;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<array{start: Carbon, end: Carbon}>
     */
    private static function compute(): array
    {
        try {
            $rows = ScheduledTaskRun::query()
                ->where('started_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
                ->orderBy('started_at')
                ->pluck('started_at');
        } catch (Throwable $e) {
            Log::warning('schedule.outages.unavailable', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $windows = [];
        $lastMinute = null;

        foreach ($rows as $startedAt) {
            // Pluck items arrive as Carbon instances (model casts) or raw
            // datetime strings depending on driver path — normalize both.
            $tick = match (true) {
                $startedAt instanceof CarbonInterface => Carbon::instance($startedAt),
                $startedAt instanceof \Stringable, is_string($startedAt) => Carbon::parse((string) $startedAt),
                default => null,
            };

            if ($tick === null) {
                continue;
            }

            $minute = $tick->copy()->startOfMinute();

            if ($lastMinute !== null) {
                $gapMinutes = $lastMinute->diffInMinutes($minute);

                if ($gapMinutes >= self::MIN_GAP_MINUTES) {
                    // Minute-aligned bounds: start is the last minute that
                    // ticked, end the first minute that ticked after the
                    // silence — "no ticks between start and end".
                    $windows[] = ['start' => $lastMinute, 'end' => $minute];
                }
            }

            $lastMinute = $minute;
        }

        return array_reverse($windows);
    }
}

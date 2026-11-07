<?php

namespace App\Console\Commands;

use App\Models\ScheduledTaskRun;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduler-tick liveness probe (M074, 2026-10-31 outage follow-up).
 *
 * schedule:health-check judges whether scheduled WORK happened, but it
 * is itself scheduled — when the scheduler dies, the gate cannot run to
 * raise the alarm (chicken-and-egg; the Oct 31 19:28 → Nov 1 11:09
 * outage ran ~15.7h with Horizon green and nothing alerted). This
 * command closes that blind spot with the cheapest possible signal:
 * freshness of the recorded run grid itself. The every-minute escalated
 * automations and the 5-minute horizon:snapshot guarantee new
 * scheduled_task_runs rows every few minutes on a live scheduler, so
 * "newest row is old" IS scheduler death — judged independent of
 * Horizon and independent of the schedule inventory.
 *
 * Consumed by the worker's s6 watchdog (docker/s6-worker/watchdog/run)
 * as a strike source next to horizon:status, and by operators:
 *
 *   schedule:liveness               # 10-minute default threshold
 *   schedule:liveness --max-age=5   # tighter window
 *
 * Deliberate non-strikes — a restart this probe cannot heal must never
 * request one:
 *  - EMPTY table → exit SUCCESS: a fresh install has no rows yet;
 *    striking would restart-loop a healthy box during its first ticks.
 *  - DATABASE error → exit SUCCESS + warning log: a Postgres outage
 *    belongs to site uptime monitoring; recycling the worker heals
 *    nothing and churns Horizon jobs mid-run.
 * Only a REACHABLE, STALE grid exits FAILURE — the one state a worker
 * container restart genuinely fixes.
 *
 * Skipped rows count as ticks: a skip still proves the scheduler fired
 * (the freshness signal is the tick, not the work — ScheduleHealth owns
 * the work-quality judgments and deliberately excludes Skipped there).
 */
final class ScheduleLiveness extends Command
{
    /**
     * Strike threshold in minutes. Observed deploy worker-roll gaps run
     * ≤ ~6 minutes (the watchdog additionally ignores strikes until the
     * init marker has settled), so 10 tolerates every legitimate gap
     * while still catching a wedged scheduler inside roughly one
     * Horizon snapshot cadence past the threshold.
     */
    private const DEFAULT_MAX_AGE_MINUTES = 10;

    protected $signature = 'schedule:liveness
        {--max-age= : Maximum age in minutes of the newest recorded run before the scheduler is considered dead (default: 10)}';

    protected $description = 'Exit FAILURE when the newest recorded scheduled run is stale — the watchdog probe for a dead scheduler behind a healthy Horizon';

    public function handle(): int
    {
        $maxAge = $this->option('max-age');

        // An operator tightening the threshold with a typo (--max-age=1O,
        // --max-age=0) must not silently probe at the default — the
        // warning line lands in the same container log as the verdict.
        if (is_string($maxAge) && $maxAge !== '' && (! is_numeric($maxAge) || (int) $maxAge < 1)) {
            $this->warn(sprintf(
                'Ignoring unusable --max-age (%s) — falling back to the %d-minute default.',
                $maxAge,
                self::DEFAULT_MAX_AGE_MINUTES,
            ));
        }

        $maxAgeMinutes = is_numeric($maxAge) && (int) $maxAge >= 1
            ? (int) $maxAge
            : self::DEFAULT_MAX_AGE_MINUTES;

        try {
            $newestStartedAt = ScheduledTaskRun::query()->max('started_at');
        } catch (Throwable $e) {
            Log::warning('schedule.liveness.probe_failed', [
                'error' => $e->getMessage(),
            ]);

            $this->warn('Liveness not judgeable: cannot read the run grid — not striking, a container restart cannot heal this.');

            return self::SUCCESS;
        }

        // Same shape narrowing as ScheduleHealth::recordingSince(): the
        // aggregate comes back as a plain datetime string (Postgres,
        // MySQL) or null — anything else is not a grid we can judge.
        if (! is_string($newestStartedAt)) {
            $this->info('Liveness not judgeable yet: no runs recorded — not striking.');

            return self::SUCCESS;
        }

        // Timestamp math (not Carbon diff helpers) so the comparison is
        // exact and Carbon-version agnostic; started_at is UTC (the app
        // timezone), as is now().
        $ageSeconds = now()->getTimestamp() - Carbon::parse($newestStartedAt)->getTimestamp();

        if ($ageSeconds < $maxAgeMinutes * 60) {
            $this->info(sprintf(
                'Scheduler alive: newest recorded run %d minute(s) ago (threshold: %d).',
                intdiv(max(0, $ageSeconds), 60),
                $maxAgeMinutes,
            ));

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'Scheduler appears DEAD: newest recorded run %d minute(s) ago (threshold: %d). Horizon may still be green — this is the blind spot schedule:health-check cannot cover.',
            intdiv($ageSeconds, 60),
            $maxAgeMinutes,
        ));

        return self::FAILURE;
    }
}

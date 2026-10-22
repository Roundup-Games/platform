<?php

namespace App\Jobs;

use App\Services\Badges\BadgesSweep;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Queued full badge sweep (badges:sweep's twin) for admin-triggered runs.
 *
 * The Filament "Run sweep now" action MUST NOT execute the sweep inline:
 * the orchestrator is badges:sync plus every evaluator's chunked cohort
 * pass over the users table — minutes of work that would pin a PHP-FPM
 * worker until the request times out. Dispatching here moves the work to
 * Horizon.
 *
 * Concurrency with the nightly 06:30 run (or another manual dispatch) is
 * safe by design: every write flows through BadgeService's audited,
 * unique-constraint-backed pipeline, so overlapping runs converge
 * idempotently (the BadgesSweep contract) — the same guarantee the
 * nightly schedule relies on.
 *
 * Outcome observability: BadgesSweep logs badges.sweep.completed (per-
 * evaluator durations, failures) and this job's failed() hook escalates
 * an orchestration-level crash; Horizon shows the run. No per-admin
 * completion notification exists by design — admin surfaces have no
 * database-notification channel, and inventing one is out of scope here.
 */
class RunBadgeSweep implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Idempotent end-to-end (the BadgesSweep contract), so a transient
     * failure on the manual run costs a retry, not the run — attempts
     * converge on the same awards either way.
     */
    public int $tries = 3;

    public int $backoff = 60;

    /**
     * 300s = the Horizon production supervisor cap (config/horizon.php),
     * which the redis connection's retry_after (360s = cap + buffer)
     * deliberately exceeds — the queue.php contract. A timeout above
     * that budget would have Redis re-release the reserved job to a
     * second worker while the first still runs, duplicating the sweep
     * and mis-booking attempts. A sweep that legitimately outgrows the
     * budget is killed and retried idempotently ($tries, per-evaluator
     * chunking converges on the same awards), never duplicated.
     */
    public int $timeout = 300;

    public function handle(BadgesSweep $sweep): void
    {
        $result = $sweep->sweep();

        if ($result['failed'] > 0) {
            Log::error('badges.sweep.manual_failed', [
                'evaluators' => $result['evaluators'],
                'failed' => $result['failed'],
                'failures' => $result['failures'],
            ]);
        }
    }

    public function failed(?\Throwable $exception = null): void
    {
        Log::error('badges.sweep.job_failed', [
            'exception' => $exception?->getMessage(),
        ]);
    }
}

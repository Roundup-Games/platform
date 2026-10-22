<?php

namespace App\Listeners;

use App\Enums\ScheduledTaskRunStatus;
use App\Models\ScheduledTaskRun;
use App\Support\Scheduling\TaskIdentity;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Support\Str;
use Throwable;

/**
 * Persists scheduled-task run outcomes (M068, 2026-10-17).
 *
 * The schedule answers "what is due"; these rows answer "did it run and
 * how did it end". One listener class with per-event public methods,
 * registered explicitly in AppServiceProvider::boot — the framework fires
 * each event from the scheduler process (schedule:work), which is the only
 * place they originate.
 *
 * Lifecycle mapping (verified against framework dispatch sites):
 *  - ScheduledTaskStarting          → row created, status=running (fires for
 *                                     foreground AND background dispatch)
 *  - ScheduledTaskFinished          → foreground ONLY: completed + runtime_ms
 *                                     (the framework also fires it at spawn
 *                                     time for background tasks, which
 *                                     finished() deliberately ignores).
 *                                     Two special foreground shapes:
 *                                     overlap-blocked ticks (the task's
 *                                     withoutOverlapping mutex was held, so
 *                                     Event::run() executed nothing — the
 *                                     framework fires no Skipped event for
 *                                     this) close their row as Skipped, and
 *                                     non-zero exits record as Failed — the
 *                                     framework dispatches Finished BEFORE
 *                                     its exit-code throw, so without this
 *                                     branch a failed exit would record as
 *                                     Completed and ScheduledTaskFailed would
 *                                     synthesize a duplicate row.
 *  - ScheduledTaskFailed            → failed + truncated exception message
 *                                     (never fires for background tasks —
 *                                     ScheduleRunCommand's throw is guarded
 *                                     by !runInBackground)
 *  - ScheduledTaskSkipped           → skipped row (paused task / filter
 *                                     skip — the only skips that dispatch
 *                                     this event; overlap blocks do not)
 *  - ScheduledBackgroundTaskFinished→ the REAL outcome of a background
 *                                     task: schedule:finish stamps the
 *                                     task's exit code onto the event
 *                                     before dispatching, so a non-zero
 *                                     exit records as failed — this is
 *                                     the ONLY failure signal background
 *                                     entries have.
 *
 * Failure isolation: ScheduledTaskStarting fires BEFORE the task runs — an
 * exception thrown here would abort the scheduled work it observes. Every
 * mutation goes through record(), which swallows and logs recording
 * failures; the worst case is a missing history row, never a missed task.
 */
class RecordScheduledTaskRun
{
    public function starting(ScheduledTaskStarting $event): void
    {
        $taskKey = TaskIdentity::command($event->task);

        $this->record($taskKey, fn () => ScheduledTaskRun::query()->create([
            'task_key' => $taskKey,
            'status' => ScheduledTaskRunStatus::Running,
            'started_at' => now(),
        ]));
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        $taskKey = TaskIdentity::command($event->task);

        // Overlap-blocked tick (foreground OR background): the task's
        // withoutOverlapping mutex was held by a still-running occurrence,
        // so Event::run() returned without executing anything — no command,
        // no background process, no exit code, and crucially NO
        // ScheduledTaskSkipped (the framework only dispatches that for
        // paused/filter skips). This tick's Starting row must close as
        // Skipped so it cannot pose as a ~0ms success, and it must not
        // disturb the row of the occurrence that legitimately holds the
        // mutex — that row's honesty is what lets ScheduleHealth age a
        // hung run into Missed.
        if ($event->task->skippedBecauseOverlapping) {
            $this->record($taskKey, fn () => $this->completeRunningRow(
                $taskKey,
                ScheduledTaskRunStatus::Skipped,
            ));

            return;
        }

        // Background tasks dispatch Finished at SPAWN time:
        // ScheduleRunCommand fires it unconditionally after Event::run()
        // returns, and run() returns immediately for background dispatch
        // (the command keeps running in its own process). Completing the
        // row here would record a phantom ~0s run and force
        // backgroundFinished to synthesize a second row —
        // ScheduledBackgroundTaskFinished owns the transition.
        if ($event->task->runInBackground) {
            return;
        }

        $runtimeMs = (int) round($event->runtime * 1000);

        // Non-zero exit: the framework dispatches Finished BEFORE its
        // exit-code throw (ScheduledTaskFailed follows milliseconds
        // later). Record the failure ONCE, here, with the real runtime —
        // the recent-outcome guard in completeRunningRow absorbs the
        // duplicate Failed event.
        $exitCode = $event->task->exitCode ?? 0;

        if ($exitCode !== 0) {
            $this->record(
                $taskKey,
                fn () => $this->completeRunningRow(
                    $taskKey,
                    ScheduledTaskRunStatus::Failed,
                    $runtimeMs,
                    error: "Foreground command exited with code {$exitCode}.",
                ),
            );

            return;
        }

        $this->record(
            $taskKey,
            fn () => $this->completeRunningRow(
                $taskKey,
                ScheduledTaskRunStatus::Completed,
                $runtimeMs,
            ),
        );
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $taskKey = TaskIdentity::command($event->task);

        $this->record($taskKey, fn () => $this->completeRunningRow(
            $taskKey,
            ScheduledTaskRunStatus::Failed,
            error: str_replace("\0", '', Str::limit($event->exception->getMessage(), 500, '')),
        ));
    }

    public function backgroundFinished(ScheduledBackgroundTaskFinished $event): void
    {
        $taskKey = TaskIdentity::command($event->task);

        // schedule:finish stamps the real exit code onto the task (via
        // Event::finish) before dispatching this event — a non-zero exit
        // is a failed run even though ScheduledTaskFailed never fires for
        // background tasks.
        $exitCode = $event->task->exitCode ?? 0;

        $this->record($taskKey, fn () => $this->completeRunningRow(
            $taskKey,
            $exitCode === 0 ? ScheduledTaskRunStatus::Completed : ScheduledTaskRunStatus::Failed,
            error: $exitCode === 0 ? null : "Background command exited with code {$exitCode}.",
            wallClockRuntime: true,
        ));
    }

    public function skipped(ScheduledTaskSkipped $event): void
    {
        $taskKey = TaskIdentity::command($event->task);

        $this->record($taskKey, fn () => ScheduledTaskRun::query()->create([
            'task_key' => $taskKey,
            'status' => ScheduledTaskRunStatus::Skipped,
            'started_at' => now(),
            'finished_at' => now(),
        ]));
    }

    /**
     * Run one recording mutation, absorbing its failures. Recording runs in
     * the scheduler process on every tick; a transient DB hiccup (or an
     * exception payload the database rejects) must not take scheduled work
     * down with it. Logged as warnings — if recording is persistently
     * broken, the dashboard's own missed counts are the alarm.
     */
    private function record(string $taskKey, callable $mutation): void
    {
        try {
            $mutation();
        } catch (Throwable $e) {
            logger()->warning('Scheduled-task run recording failed', [
                'task' => $taskKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Transition one Running row to a terminal status. If the Starting row
     * is missing (pruned mid-run, listener added between events),
     * synthesize the outcome row so history never shows a run that started
     * and vanished — unless a sibling event already recorded this tick's
     * outcome (the framework's Finished-then-Failed dispatch order for
     * non-zero exits), in which case the duplicate is absorbed.
     *
     * Rows whose finish never arrives are deliberately left Running — an
     * honest "we never heard back" that ages out via Prunable (24h) and
     * never manufactures a false Failed for an occurrence that may still
     * be live.
     *
     * $wallClockRuntime marks background completions, where the framework
     * carries no timed runtime: the wall clock from the Starting row
     * (process spawn) to schedule:finish's report is the honest
     * approximation — it includes the background process boot, so it
     * overstates short tasks, but never reports a phantom ~0s and never
     * stays null for minutes-long work.
     */
    private function completeRunningRow(
        string $taskKey,
        ScheduledTaskRunStatus $status,
        ?int $runtimeMs = null,
        ?string $error = null,
        bool $wallClockRuntime = false,
    ): void {
        $run = $this->resolveRunningRow($taskKey, $runtimeMs);

        if ($run === null) {
            if (
                $status === ScheduledTaskRunStatus::Failed
                && ScheduledTaskRun::query()
                    ->whereTask($taskKey)
                    ->where('status', ScheduledTaskRunStatus::Failed)
                    ->where('finished_at', '>=', now()->subSeconds(90))
                    ->exists()
            ) {
                return;
            }

            ScheduledTaskRun::query()->create([
                'task_key' => $taskKey,
                'status' => $status,
                'started_at' => now(),
                'finished_at' => now(),
                'runtime_ms' => $runtimeMs,
                'error' => $error,
            ]);

            return;
        }

        if ($wallClockRuntime && $runtimeMs === null) {
            $runtimeMs = (int) round($run->started_at->diffInMilliseconds(now()));
        }

        $run->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'runtime_ms' => $runtimeMs ?? $run->runtime_ms,
            'error' => $error ?? $run->error,
        ])->save();
    }

    /**
     * The Running row this outcome belongs to. A single candidate is
     * trivially it. Several candidates mean a previous occurrence of an
     * unguarded (no withoutOverlapping) task is still in flight or was
     * abandoned: the finished event's runtime identifies its own start
     * time (started_at ≈ now - runtime), so the closest match is the
     * honest pairing; outcomes without a runtime (failures, skips,
     * background reports) belong to the tick that just started — the
     * newest row.
     */
    private function resolveRunningRow(string $taskKey, ?int $runtimeMs): ?ScheduledTaskRun
    {
        $candidates = ScheduledTaskRun::query()
            ->whereTask($taskKey)
            ->where('status', ScheduledTaskRunStatus::Running)
            ->orderByDesc('started_at')
            ->get();

        if ($candidates->count() <= 1 || $runtimeMs === null) {
            return $candidates->first();
        }

        $expectedStart = now()->subMilliseconds($runtimeMs);

        return $candidates
            ->sortBy(fn (ScheduledTaskRun $row): int => (int) round(abs($row->started_at->diffInMilliseconds($expectedStart))))
            ->first();
    }
}

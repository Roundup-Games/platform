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
 *  - ScheduledTaskFinished          → completed + runtime_ms (foreground)
 *  - ScheduledTaskFailed            → failed + truncated exception message
 *  - ScheduledTaskSkipped           → skipped row (filters/overlap guards)
 *  - ScheduledBackgroundTaskFinished→ completed, runtime unknown (framework
 *                                     does not time background exits)
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
        $this->record(
            TaskIdentity::command($event->task),
            fn () => $this->completeRunningRow(
                TaskIdentity::command($event->task),
                ScheduledTaskRunStatus::Completed,
                (int) round($event->runtime * 1000),
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

        $this->record($taskKey, fn () => $this->completeRunningRow(
            $taskKey,
            ScheduledTaskRunStatus::Completed,
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
     * Transition the latest running row to a terminal status. If the
     * Starting row is missing (pruned mid-run, listener added between
     * events), synthesize the outcome row so history never shows a run
     * that started and vanished.
     */
    private function completeRunningRow(
        string $taskKey,
        ScheduledTaskRunStatus $status,
        ?int $runtimeMs = null,
        ?string $error = null,
    ): void {
        $run = ScheduledTaskRun::query()
            ->whereTask($taskKey)
            ->where('status', ScheduledTaskRunStatus::Running)
            ->orderByDesc('started_at')
            ->first();

        if ($run === null) {
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

        $run->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'runtime_ms' => $runtimeMs ?? $run->runtime_ms,
            'error' => $error ?? $run->error,
        ])->save();
    }
}

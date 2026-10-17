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
 */
class RecordScheduledTaskRun
{
    public function starting(ScheduledTaskStarting $event): void
    {
        ScheduledTaskRun::query()->create([
            'task_key' => TaskIdentity::command($event->task),
            'status' => ScheduledTaskRunStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        $this->completeRunningRow(
            TaskIdentity::command($event->task),
            ScheduledTaskRunStatus::Completed,
            (int) round($event->runtime * 1000),
        );
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $this->completeRunningRow(
            TaskIdentity::command($event->task),
            ScheduledTaskRunStatus::Failed,
            error: Str::limit($event->exception->getMessage(), 500, ''),
        );
    }

    public function backgroundFinished(ScheduledBackgroundTaskFinished $event): void
    {
        $this->completeRunningRow(
            TaskIdentity::command($event->task),
            ScheduledTaskRunStatus::Completed,
        );
    }

    public function skipped(ScheduledTaskSkipped $event): void
    {
        ScheduledTaskRun::query()->create([
            'task_key' => TaskIdentity::command($event->task),
            'status' => ScheduledTaskRunStatus::Skipped,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
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

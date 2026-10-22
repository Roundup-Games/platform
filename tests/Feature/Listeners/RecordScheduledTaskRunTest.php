<?php

use App\Enums\ScheduledTaskRunStatus;
use App\Models\ScheduledTaskRun;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

//
// RecordScheduledTaskRun: framework scheduling events must produce a
// trustworthy per-task run history. Tests dispatch the REAL event objects
// (same classes the scheduler fires) with a Schedule::command event, so
// identity resolution (binary-prefix stripping) is exercised too.
beforeEach(function () {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);
    $this->event = $schedule->command('demo:observability-check');
});

it('records a running row on task start', function () {
    event(new ScheduledTaskStarting($this->event));

    $run = ScheduledTaskRun::query()->whereTask('demo:observability-check')->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(ScheduledTaskRunStatus::Running)
        ->and($run->finished_at)->toBeNull();
});

it('completes the run with runtime on finish', function () {
    event(new ScheduledTaskStarting($this->event));
    event(new ScheduledTaskFinished($this->event, 1.234));

    $run = ScheduledTaskRun::query()->whereTask('demo:observability-check')->sole();

    expect($run->status)->toBe(ScheduledTaskRunStatus::Completed)
        ->and($run->runtime_ms)->toBe(1234)
        ->and($run->finished_at)->not->toBeNull();
});

it('records failure with the exception message', function () {
    event(new ScheduledTaskStarting($this->event));
    event(new ScheduledTaskFailed($this->event, new RuntimeException('BGG sync exploded')));

    $run = ScheduledTaskRun::query()->whereTask('demo:observability-check')->sole();

    expect($run->status)->toBe(ScheduledTaskRunStatus::Failed)
        ->and($run->error)->toBe('BGG sync exploded')
        ->and($run->finished_at)->not->toBeNull();
});

it('records skipped runs (paused tasks / filters)', function () {
    event(new ScheduledTaskSkipped($this->event));

    $run = ScheduledTaskRun::query()->whereTask('demo:observability-check')->sole();

    expect($run->status)->toBe(ScheduledTaskRunStatus::Skipped);
});

it('closes an overlap-blocked foreground tick as Skipped — no phantom ~0ms success, mutex holder untouched', function () {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);
    $guarded = $schedule->command('badges:sweep')->withoutOverlapping();

    // The hung occurrence that holds the mutex: started an hour ago.
    event(new ScheduledTaskStarting($guarded));
    $holder = ScheduledTaskRun::query()->whereTask('badges:sweep')->sole();

    $this->travel(60)->minutes();

    // The blocked tick's real sequence: Starting fires, Event::run()
    // returns without executing (skippedBecauseOverlapping), Finished
    // dispatches with ~0 runtime, and NO ScheduledTaskSkipped exists for
    // overlap blocks. Mirror Event::run()'s marker faithfully.
    $guarded->skippedBecauseOverlapping = true;
    event(new ScheduledTaskStarting($guarded));
    event(new ScheduledTaskFinished($guarded, 0.0));

    $runs = ScheduledTaskRun::query()->whereTask('badges:sweep')->get();

    expect($runs)->toHaveCount(2)
        ->and($runs[1]->status)->toBe(ScheduledTaskRunStatus::Skipped)
        ->and($runs[1]->runtime_ms)->toBeNull()
        // The mutex holder's row is NOT force-failed mid-flight — it stays
        // honestly Running so ScheduleHealth can age it into Missed.
        ->and($holder->fresh()->status)->toBe(ScheduledTaskRunStatus::Running)
        ->and($runs->where('status', ScheduledTaskRunStatus::Failed)->count())->toBe(0);
});

it('closes an overlap-blocked background tick as Skipped instead of stranding a zombie Running row', function () {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);
    $guarded = $schedule->command('badges:sweep')->runInBackground()->withoutOverlapping();

    $guarded->skippedBecauseOverlapping = true;
    event(new ScheduledTaskStarting($guarded));
    event(new ScheduledTaskFinished($guarded, 0.0));

    $run = ScheduledTaskRun::query()->whereTask('badges:sweep')->sole();

    expect($run->status)->toBe(ScheduledTaskRunStatus::Skipped)
        ->and($run->finished_at)->not->toBeNull();
});

it('records a non-zero foreground exit once — as Failed with the real runtime, before the framework throws', function () {
    // Framework order (ScheduleRunCommand::runEvent): Finished dispatches
    // BEFORE the exit-code check throws. Mirror it faithfully, including
    // the follow-up ScheduledTaskFailed, and assert ONE honest row.
    event(new ScheduledTaskStarting($this->event));
    $this->event->finish(app(), 2);

    event(new ScheduledTaskFinished($this->event, 1.5));
    event(new ScheduledTaskFailed($this->event, new RuntimeException(
        'Scheduled command [demo:observability-check] failed with exit code [2].',
    )));

    $runs = ScheduledTaskRun::query()->whereTask('demo:observability-check')->get();

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->status)->toBe(ScheduledTaskRunStatus::Failed)
        ->and($runs[0]->runtime_ms)->toBe(1500)
        ->and($runs[0]->error)->toBe('Foreground command exited with code 2.');
});

it('records the real background outcome — spawn-time finished is ignored, one row survives', function () {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);
    $background = $schedule->command('badges:sweep')->runInBackground();

    // The exact sequence the framework fires for a background task:
    // Starting (spawn) → Finished (spawn, ~0s) → [command runs] →
    // schedule:finish stamps the exit code → BackgroundTaskFinished.
    event(new ScheduledTaskStarting($background));
    event(new ScheduledTaskFinished($background, 0.01));

    $this->travel(90)->seconds();

    // schedule:finish's Event::finish() stamps the real exit code before
    // dispatching (ScheduleFinishCommand) — mirror it faithfully.
    $background->finish(app(), 0);
    event(new ScheduledBackgroundTaskFinished($background));

    $runs = ScheduledTaskRun::query()->whereTask('badges:sweep')->get();

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->status)->toBe(ScheduledTaskRunStatus::Completed)
        // Wall-clock runtime from spawn to the exit report — never the
        // phantom ~0s of the spawn-time Finished event.
        ->and($runs[0]->runtime_ms)->toBeGreaterThanOrEqual(89_000)
        ->and($runs[0]->runtime_ms)->toBeLessThanOrEqual(120_000)
        ->and($runs[0]->error)->toBeNull();
});

it('records background failures from the exit code — scheduledtaskfailed never fires for them', function () {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);
    $background = $schedule->command('badges:sweep')->runInBackground();

    event(new ScheduledTaskStarting($background));
    event(new ScheduledTaskFinished($background, 0.01));

    $background->finish(app(), 1);
    event(new ScheduledBackgroundTaskFinished($background));

    $runs = ScheduledTaskRun::query()->whereTask('badges:sweep')->get();

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->status)->toBe(ScheduledTaskRunStatus::Failed)
        ->and($runs[0]->error)->toBe('Background command exited with code 1.');
});

it('synthesizes an outcome row when the starting row is missing', function () {
    // Listener deployed between start and finish, or starting row pruned —
    // history must never show a run that started and silently vanished.
    event(new ScheduledTaskFinished($this->event, 2.5));

    $run = ScheduledTaskRun::query()->whereTask('demo:observability-check')->sole();

    expect($run->status)->toBe(ScheduledTaskRunStatus::Completed)
        ->and($run->runtime_ms)->toBe(2500);
});

it('synthesizes a Failed row when ScheduledTaskFailed fires with no starting row', function () {
    // The failed() twin of the finished() synthesis: a crash on a run the
    // listener never saw start must still land as a Failed history row.
    event(new ScheduledTaskFailed($this->event, new RuntimeException('exploded mid-run')));

    $run = ScheduledTaskRun::query()->whereTask('demo:observability-check')->sole();

    expect($run->status)->toBe(ScheduledTaskRunStatus::Failed)
        ->and($run->error)->toBe('exploded mid-run');
});

it('pairs a finish with its own Running row by runtime and never force-fails a live overlapping occurrence', function () {
    // An unguarded every-minute task whose previous occurrence is still
    // legitimately running (no withoutOverlapping): the quick second run
    // must complete ITS OWN row — the finished event's runtime identifies
    // its start time — and the still-running first row stays Running.
    event(new ScheduledTaskStarting($this->event));
    $first = ScheduledTaskRun::query()->whereTask('demo:observability-check')->sole();

    $this->travel(1)->minutes();
    event(new ScheduledTaskStarting($this->event));
    $second = ScheduledTaskRun::query()->whereTask('demo:observability-check')->latest('started_at')->first();

    $this->travel(5)->seconds();
    // The second occurrence ran 5s: started ≈ now-5s → its own row.
    event(new ScheduledTaskFinished($this->event, 5.0));

    expect($second->fresh()->status)->toBe(ScheduledTaskRunStatus::Completed)
        ->and($second->fresh()->runtime_ms)->toBe(5000)
        // Not force-failed mid-flight: manufacturing a Failed row for a
        // possibly-live occurrence is worse than an honest Running one
        // that ages out via Prunable.
        ->and($first->fresh()->status)->toBe(ScheduledTaskRunStatus::Running)
        ->and(ScheduledTaskRun::query()->whereTask('demo:observability-check')->where('status', ScheduledTaskRunStatus::Failed)->count())->toBe(0);
});

it('scrubs null bytes from exception messages before persisting', function () {
    // Postgres rejects \0 in text values — a raw exception payload would
    // fail the insert (and, pre-isolation, abort the scheduler tick).
    event(new ScheduledTaskStarting($this->event));
    event(new ScheduledTaskFailed($this->event, new RuntimeException("boom\0null-byte payload")));

    $run = ScheduledTaskRun::query()->whereTask('demo:observability-check')->sole();

    expect($run->error)->toBe('boomnull-byte payload')
        ->and(strpos($run->error ?? '', "\0"))->toBeFalse();
});

it('never breaks the scheduler when recording itself fails', function () {
    Log::shouldReceive('warning')->once()->withArgs(
        fn (string $message, array $context) => $message === 'Scheduled-task run recording failed'
    );

    // Simulate a DB-layer failure on insert. ScheduledTaskStarting fires
    // BEFORE the task runs — without isolation this aborts the run.
    ScheduledTaskRun::creating(fn () => throw new RuntimeException('connection refused'));

    try {
        event(new ScheduledTaskStarting($this->event));

        expect(ScheduledTaskRun::query()->count())->toBe(0);
    } finally {
        // Model events live on a process-static dispatcher: without the
        // flush, every later test in this process would throw on insert.
        ScheduledTaskRun::flushEventListeners();
    }
});

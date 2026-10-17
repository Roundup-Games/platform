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

it('records skipped runs (filters / overlap guards)', function () {
    event(new ScheduledTaskSkipped($this->event));

    $run = ScheduledTaskRun::query()->whereTask('demo:observability-check')->sole();

    expect($run->status)->toBe(ScheduledTaskRunStatus::Skipped);
});

it('completes background runs without fabricating a runtime', function () {
    event(new ScheduledTaskStarting($this->event));
    event(new ScheduledBackgroundTaskFinished($this->event));

    $run = ScheduledTaskRun::query()->whereTask('demo:observability-check')->sole();

    expect($run->status)->toBe(ScheduledTaskRunStatus::Completed)
        ->and($run->runtime_ms)->toBeNull();
});

it('synthesizes an outcome row when the starting row is missing', function () {
    // Listener deployed between start and finish, or starting row pruned —
    // history must never show a run that started and silently vanished.
    event(new ScheduledTaskFinished($this->event, 2.5));

    $run = ScheduledTaskRun::query()->whereTask('demo:observability-check')->sole();

    expect($run->status)->toBe(ScheduledTaskRunStatus::Completed)
        ->and($run->runtime_ms)->toBe(2500);
});

it('always transitions the latest running row only', function () {
    event(new ScheduledTaskStarting($this->event));
    $first = ScheduledTaskRun::query()->whereTask('demo:observability-check')->sole();

    $this->travel(1)->minutes();
    event(new ScheduledTaskStarting($this->event));
    event(new ScheduledTaskFinished($this->event, 0.5));

    expect(ScheduledTaskRun::query()->whereTask('demo:observability-check')->count())->toBe(2)
        ->and($first->fresh()->status)->toBe(ScheduledTaskRunStatus::Running);
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

    event(new ScheduledTaskStarting($this->event));

    expect(ScheduledTaskRun::query()->count())->toBe(0);
});

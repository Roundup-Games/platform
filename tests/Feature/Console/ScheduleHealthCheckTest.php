<?php

use App\Console\Commands\ScheduleHealthCheck;
use App\Enums\ScheduledTaskRunStatus;
use App\Models\ScheduledTaskRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Tests\Traits\CapturesLogRecords;

uses(CapturesLogRecords::class);

/*
 * schedule:health-check (2026-10-23 incident follow-up): the command is
 * the queue-health tile's active alert — SUCCESS when scheduled work is
 * clean, FAILURE (red failed_last on the tile + a structured error log)
 * when occurrences are missed, tasks fail, or the inventory breaks.
 *
 * The two deterministic states: a fresh install (no run rows at all —
 * everything awaits its first run, nothing missed) is healthy; a recent
 * FAILED run row for any tracked task fails the gate. The summary is
 * cache-bound, so each case clears the cache key first.
 */

beforeEach(function () {
    Cache::forget('admin:schedule-health.v2');
});

it('exits SUCCESS on a fresh install with nothing missed and no failures', function () {
    expect(ScheduledTaskRun::query()->count())->toBe(0);

    $this->artisan(ScheduleHealthCheck::class)
        ->expectsOutputToContain('Scheduled work healthy')
        ->assertSuccessful();
});

it('exits FAILURE and logs when a task failed its last run', function () {
    $this->captureLogRecords();

    ScheduledTaskRun::factory()->create([
        'task_key' => 'horizon:snapshot',
        'status' => ScheduledTaskRunStatus::Failed,
        'error' => 'simulated failure',
    ]);

    $this->artisan(ScheduleHealthCheck::class)
        ->expectsOutputToContain('Scheduled work degraded')
        ->assertFailed();

    $record = $this->logRecord('schedule.health.degraded');
    expect($record['context']['failed_last'])->toBe(1);
});

it('exits FAILURE when the schedule inventory cannot load', function () {
    // ScheduleHealthTest's degraded idiom: an exception while resolving
    // schedule events degrades the summary — zeros must read as unknown.
    $this->mock(Schedule::class)
        ->shouldReceive('events')
        ->andThrow(new RuntimeException('schedule definitions exploded'));

    $this->artisan(ScheduleHealthCheck::class)
        ->expectsOutputToContain('schedule inventory failed to load')
        ->assertFailed();
});

<?php

use App\Console\Commands\ScheduleHealthCheck;
use App\Enums\ScheduledTaskRunStatus;
use App\Models\ScheduledTaskRun;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Tests\Traits\CapturesLogRecords;

uses(CapturesLogRecords::class);

/*
 * schedule:health-check (2026-10-23 incident follow-up): the command is
 * the queue-health tile's active alert. It fails on REGRESSIONS — a task
 * that has run before missing its due time, a failed latest run, a
 * broken inventory — and stays quiet on never-ran misses (deploy-timing
 * artifacts: a task shipped after its daily due time reads missed until
 * its first occurrence; the Scheduled Tasks page shows those rows
 * regardless, and one occurrence separates artifact from broken).
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

it('exits FAILURE and logs the regressed task when a previously-run task missed its due time', function () {
    $this->captureLogRecords();

    // A COMPLETED horizon:snapshot run older than the cron's occurrence
    // window makes the latest due occurrence unrecorded — a task with
    // history regressing to missed (the genuine 02:00-incident shape).
    ScheduledTaskRun::factory()->create([
        'task_key' => 'horizon:snapshot',
        'status' => ScheduledTaskRunStatus::Completed,
        'started_at' => now()->subHours(3),
        'finished_at' => now()->subHours(3),
    ]);

    $this->artisan(ScheduleHealthCheck::class)
        ->expectsOutputToContain('horizon:snapshot')
        ->assertFailed();

    $record = $this->logRecord('schedule.health.degraded');
    expect($record['context']['regressed_tasks'])->toContain('horizon:snapshot');
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

it('stays quiet on never-ran misses — deploy-timing artifacts page nobody', function () {
    // The 2026-10-23 evening shape: three tasks deployed after their daily
    // due times read "missed" with zero run rows. Pinned time makes the
    // math deterministic: horizon:snapshot (every 5 min) healthy with a
    // fresh covering run; schedule:health-check (hourly, on the hour)
    // last due 18:00, never ran, epoch 05:00 → missed-by-artifact.
    Carbon::setTestNow(Carbon::parse('2026-10-23 18:37:00'));

    ScheduledTaskRun::factory()->create([
        'task_key' => 'horizon:snapshot',
        'status' => ScheduledTaskRunStatus::Completed,
        'started_at' => Carbon::parse('2026-10-23 05:00:00'),
        'finished_at' => Carbon::parse('2026-10-23 05:00:00'),
    ]);
    ScheduledTaskRun::factory()->create([
        'task_key' => 'horizon:snapshot',
        'status' => ScheduledTaskRunStatus::Completed,
        'started_at' => Carbon::parse('2026-10-23 18:35:00'),
        'finished_at' => Carbon::parse('2026-10-23 18:35:00'),
    ]);
    Cache::forget('admin:schedule-health.v2');

    try {
        $this->artisan(ScheduleHealthCheck::class)
            ->expectsOutputToContain('never-ran task(s) awaiting their first occurrence')
            ->assertSuccessful();
    } finally {
        Carbon::setTestNow();
    }
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

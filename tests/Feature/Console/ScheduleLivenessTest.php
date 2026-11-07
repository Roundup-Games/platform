<?php

use App\Console\Commands\ScheduleLiveness;
use App\Models\ScheduledTaskRun;
use Illuminate\Support\Facades\Schema;
use Tests\Traits\CapturesLogRecords;

uses(CapturesLogRecords::class);

/*
 * schedule:liveness (M074, 2026-10-31 outage follow-up): the watchdog's
 * scheduler-death probe. The every-minute automations and 5-minute
 * horizon:snapshot write run rows on every tick, so a stale newest row
 * IS a dead scheduler — the outage class Horizon-green monitoring could
 * never see. The command must strike ONLY on a reachable-but-stale
 * grid: empty tables and database errors are non-strikes (a restart
 * cannot heal either, and a fresh box must not restart-loop).
 */

it('exits SUCCESS when the newest recorded run is fresh', function () {
    ScheduledTaskRun::factory()->create([
        'started_at' => now()->subMinutes(2),
        'finished_at' => now()->subMinutes(2),
    ]);

    $this->artisan(ScheduleLiveness::class)
        ->expectsOutputToContain('Scheduler alive')
        ->assertSuccessful();
});

it('exits FAILURE when the newest recorded run is stale past the threshold', function () {
    // The Oct 31 shape: Horizon healthy, nothing has ticked for ~15.7h.
    ScheduledTaskRun::factory()->create([
        'started_at' => now()->subMinutes(947),
        'finished_at' => now()->subMinutes(947),
    ]);

    $this->artisan(ScheduleLiveness::class)
        ->expectsOutputToContain('Scheduler appears DEAD')
        ->assertFailed();
});

it('treats the exact threshold as dead — the boundary is inclusive', function () {
    ScheduledTaskRun::factory()->create([
        'started_at' => now()->subMinutes(10)->subSeconds(2),
        'finished_at' => now()->subMinutes(10)->subSeconds(2),
    ]);

    $this->artisan(ScheduleLiveness::class)
        ->expectsOutputToContain('Scheduler appears DEAD')
        ->assertFailed();
});

it('honors --max-age so tighter windows can probe without code changes', function () {
    ScheduledTaskRun::factory()->create([
        'started_at' => now()->subMinutes(12),
        'finished_at' => now()->subMinutes(12),
    ]);

    // Stale at the 10-minute default, alive under an explicit 30-minute
    // window — the watchdog threshold stays tunable from the script.
    $this->artisan(ScheduleLiveness::class)->assertFailed();

    $this->artisan(ScheduleLiveness::class, ['--max-age' => 30])
        ->expectsOutputToContain('Scheduler alive')
        ->assertSuccessful();
});

it('counts a Skipped row as a tick — skips prove the scheduler fired', function () {
    // withoutOverlapping skips are excluded from ScheduleHealth's
    // latest-run resolution on purpose (work quality), but liveness is
    // about the TICK: a fresh Skipped row means the scheduler is alive.
    ScheduledTaskRun::factory()->skipped()->create([
        'started_at' => now()->subMinutes(1),
    ]);

    $this->artisan(ScheduleLiveness::class)
        ->expectsOutputToContain('Scheduler alive')
        ->assertSuccessful();
});

it('does not strike on an empty grid — a fresh install must not restart-loop', function () {
    expect(ScheduledTaskRun::query()->count())->toBe(0);

    $this->artisan(ScheduleLiveness::class)
        ->expectsOutputToContain('no runs recorded')
        ->assertSuccessful();
});

it('does not strike when the run grid cannot be read — a restart cannot heal the database', function () {
    $this->captureLogRecords();

    Schema::drop('scheduled_task_runs');

    $this->artisan(ScheduleLiveness::class)
        ->expectsOutputToContain('not striking')
        ->assertSuccessful();

    expect($this->logRecord('schedule.liveness.probe_failed'))->not->toBeNull();
});

it('warns and falls back to the default when --max-age is unusable', function () {
    // An operator typo (--max-age=1O, --max-age=0) must not silently
    // probe at the default — the warning lands beside the verdict in
    // the container log the watchdog strikes from.
    ScheduledTaskRun::factory()->create([
        'started_at' => now()->subMinutes(2),
        'finished_at' => now()->subMinutes(2),
    ]);

    $this->artisan(ScheduleLiveness::class, ['--max-age' => 'banana'])
        ->expectsOutputToContain('Ignoring unusable --max-age (banana)')
        ->expectsOutputToContain('Scheduler alive')
        ->assertSuccessful();
});

it('strikes on stale even when the newest row failed — liveness is orthogonal to run health', function () {
    ScheduledTaskRun::factory()->failed()->create([
        'started_at' => now()->subMinutes(45),
        'finished_at' => now()->subMinutes(45),
    ]);

    $this->artisan(ScheduleLiveness::class)->assertFailed();
});

<?php

use App\Enums\ScheduledTaskHealth;
use App\Models\ScheduledTaskRun;
use App\Support\Scheduling\ScheduleHealth;
use App\Support\Scheduling\TaskIdentity;
use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;

//
// ScheduleHealth: the dashboard's scheduled-work signal (missed
// occurrences + tasks whose latest run failed), computed from the same
// rule the Scheduled Tasks page renders per row.
beforeEach(function () {
    Cache::flush();
});

it('counts missed occurrences and failed latest runs', function () {
    // Freeze at :07 — for */5 tasks the previous due occurrence is :05.
    $this->travelTo(now()->startOfHour()->setMinute(7));

    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    // Every task healthy except the two under test.
    foreach ($schedule->events() as $event) {
        $key = TaskIdentity::command($event);

        if ($key === 'horizon:snapshot') {
            // Latest run an hour old -> the :05 occurrence is missed.
            ScheduledTaskRun::factory()->startedAt(now()->subHour())->create(['task_key' => $key]);

            continue;
        }

        ScheduledTaskRun::factory()->startedAt(now()->subMinute())->create(['task_key' => $key]);
    }

    // Latest attendance:resolve run failed (30s ago — newer than the
    // healthy row, so it is the latest; recent enough to not be missed).
    ScheduledTaskRun::factory()->failed()->startedAt(now()->subSeconds(30))->create([
        'task_key' => 'attendance:resolve',
    ]);

    $summary = ScheduleHealth::summary();

    expect($summary['missed'])->toBe(1)
        ->and($summary['failed_last'])->toBe(1)
        ->and($summary['awaiting_first'])->toBe(0);
});

it('reports zero when everything ran on time', function () {
    $this->travelTo(now()->startOfHour()->setMinute(7));

    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    foreach ($schedule->events() as $event) {
        ScheduledTaskRun::factory()->startedAt(now()->subMinute())->create([
            'task_key' => TaskIdentity::command($event),
        ]);
    }

    $summary = ScheduleHealth::summary();

    expect($summary['missed'])->toBe(0)
        ->and($summary['failed_last'])->toBe(0)
        ->and($summary['awaiting_first'])->toBe(0);
});

it('classifies pre-epoch due times as awaiting first run, not missed', function () {
    // Freeze at :08. Seeded rows at :06 define the recording epoch — the
    // hourly cityhubs task was due at :00, BEFORE recording started, so it
    // is unobserved (awaiting), not missed. Nothing else is missed.
    $this->travelTo(now()->startOfHour()->setMinute(8));

    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    foreach ($schedule->events() as $event) {
        $key = TaskIdentity::command($event);

        if ($key === 'cityhubs:recompute') {
            continue; // due :00, pre-epoch -> awaiting
        }

        // Minute 7: at the frozen 12:08 clock, the every-minute task's
        // grace walk bottoms out at 12:06 — a 12:07 run stays healthy.
        ScheduledTaskRun::factory()->startedAt(now()->setMinute(7))->create(['task_key' => $key]);
    }

    $summary = ScheduleHealth::summary();

    expect($summary['awaiting_first'])->toBe(1)
        ->and($summary['missed'])->toBe(0)
        ->and($summary['failed_last'])->toBe(0);
});

it('classifies a post-epoch due time without a run as a genuine miss', function () {
    $now = Carbon::parse('2026-10-17 12:00:00');
    $cron = new CronExpression('0 3 * * *'); // daily 03:00 -> previously due 03:00 today

    // Recording started at 02:00; the 03:00 occurrence passed with no run.
    expect(ScheduleHealth::classify($cron, $now, null, Carbon::parse('2026-10-17 02:00')))
        ->toBe(ScheduledTaskHealth::Missed);

    // Recording started at 04:00; the 03:00 occurrence was never observed.
    expect(ScheduleHealth::classify($cron, $now, null, Carbon::parse('2026-10-17 04:00')))
        ->toBe(ScheduledTaskHealth::AwaitingFirstRun);
});

it('derives the recording epoch from the database aggregate, not an in-memory Carbon', function () {
    // Regression guard (MEM1255): min(started_at) returns a plain datetime
    // string on Postgres — the old instanceof-Carbon guard nulled the
    // epoch on every install, so the zero-row missed branch was
    // unreachable. Freezing at a whole second keeps the round-trip exact.
    $this->travelTo(now()->startOfHour()->setMinute(7));

    ScheduledTaskRun::factory()->startedAt(now()->subHour())->create(['task_key' => 'horizon:snapshot']);

    $since = ScheduleHealth::recordingSince();

    expect($since)->toBeInstanceOf(Carbon::class)
        ->and($since->format('Y-m-d H:i:s'))->toBe(now()->subHour()->format('Y-m-d H:i:s'));
});

it('treats a completely fresh install as all-awaiting, never missed', function () {
    $this->travelTo(now()->startOfHour()->setMinute(7));

    $summary = ScheduleHealth::summary();

    expect($summary['missed'])->toBe(0)
        ->and($summary['failed_last'])->toBe(0)
        ->and($summary['awaiting_first'])->toBeGreaterThan(0);
});

it('caches the summary for five minutes', function () {
    ScheduleHealth::summary();

    expect(Cache::has('admin:schedule-health.v2'))->toBeTrue();
});

it('marks the summary degraded — not all-clear — when the schedule inventory cannot load', function () {
    // A schedule definition that fatal-errors while resolving means the
    // safety signal itself is broken; zeros must read as "unknown", never
    // as "on time".
    $this->mock(Schedule::class)
        ->shouldReceive('events')
        ->andThrow(new RuntimeException('schedule definitions exploded'));

    $summary = ScheduleHealth::summary();

    expect($summary['degraded'])->toBeTrue()
        ->and($summary['missed'])->toBe(0)
        ->and($summary['failed_last'])->toBe(0)
        ->and($summary['awaiting_first'])->toBe(0);
});

it('flags sub-grace cadences once two occurrences are unrecorded, not one', function () {
    // Every-minute task: the most recent due time is always inside the
    // 2-minute grace, so the judgment falls back to the occurrence before
    // it. A single unrecorded occurrence stays healthy (tick race), two
    // consecutive ones are a genuine miss.
    $now = Carbon::parse('2026-10-17 12:00:30');
    $cron = new CronExpression('* * * * *');

    expect(ScheduleHealth::classify($cron, $now, Carbon::parse('2026-10-17 11:59:30'), null))
        ->toBe(ScheduledTaskHealth::Healthy)          // 12:00 occurrence mid-tick — race-safe
        ->and(ScheduleHealth::classify($cron, $now, Carbon::parse('2026-10-17 11:56:00'), null))
        ->toBe(ScheduledTaskHealth::Missed)           // 11:59 AND 12:00 unrecorded
        // No history: two occurrences passed since recording started
        ->and(ScheduleHealth::classify($cron, $now, null, Carbon::parse('2026-10-17 11:00:00')))
        ->toBe(ScheduledTaskHealth::Missed)
        // Recording started after the fallback occurrence — unobserved
        ->and(ScheduleHealth::classify($cron, $now, null, Carbon::parse('2026-10-17 11:59:30')))
        ->toBe(ScheduledTaskHealth::AwaitingFirstRun);
});

it('does not let skipped rows mask a missed occurrence (an overlap-stuck task surfaces as Missed)', function () {
    $this->travelTo(now()->startOfHour()->setMinute(7));

    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    foreach ($schedule->events() as $event) {
        $key = TaskIdentity::command($event);

        if ($key === 'horizon:snapshot') {
            // The mutex holder hung an hour ago (last EXECUTED run), and
            // every occurrence since has been Skipped — fresh rows that
            // would satisfy the run check if skipped rows counted.
            ScheduledTaskRun::factory()->startedAt(now()->subHour())->create(['task_key' => $key]);
            ScheduledTaskRun::factory()->skipped()->startedAt(now()->subMinute())->create(['task_key' => $key]);

            continue;
        }

        ScheduledTaskRun::factory()->startedAt(now()->subMinute())->create(['task_key' => $key]);
    }

    $summary = ScheduleHealth::summary();

    expect($summary['missed'])->toBe(1)
        ->and($summary['failed_last'])->toBe(0)
        ->and($summary['awaiting_first'])->toBe(0);
});

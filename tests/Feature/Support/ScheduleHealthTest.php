<?php

use App\Models\ScheduledTaskRun;
use App\Support\Scheduling\ScheduleHealth;
use App\Support\Scheduling\TaskIdentity;
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
        ->and($summary['failed_last'])->toBe(1);
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
        ->and($summary['failed_last'])->toBe(0);
});

it('caches the summary for five minutes', function () {
    ScheduleHealth::summary();

    expect(Cache::has('admin:schedule-health'))->toBeTrue();
});

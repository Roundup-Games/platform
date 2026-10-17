<?php

use App\Enums\ScheduledTaskRunStatus;
use App\Models\ScheduledTaskRun;
use Carbon\Carbon;

//
// ScheduledTaskRun data model: enum cast, latest-per-task resolution, and
// the prunable retention contract (30 days of history, 24h for zombie
// running rows that will never see a Finished/Failed event).
it('casts status to the enum and keeps timestamps as datetimes', function () {
    $run = ScheduledTaskRun::factory()->create();

    expect($run->status)->toBeInstanceOf(ScheduledTaskRunStatus::class)
        ->and($run->status)->toBe(ScheduledTaskRunStatus::Completed)
        ->and($run->started_at)->toBeInstanceOf(Carbon::class);
});

it('resolves the latest run per task key regardless of insertion order', function () {
    ScheduledTaskRun::factory()->startedAt(now()->subHours(2))->create(['task_key' => 'attendance:resolve']);
    $latestAttendance = ScheduledTaskRun::factory()->startedAt(now()->subMinutes(5))->create(['task_key' => 'attendance:resolve']);
    ScheduledTaskRun::factory()->startedAt(now()->subMinutes(1))->create(['task_key' => 'cityhubs:recompute']);

    $latest = ScheduledTaskRun::latestPerTask();

    expect($latest)->toHaveCount(2)
        ->and($latest['attendance:resolve']->id)->toBe($latestAttendance->id)
        ->and(array_key_exists('cityhubs:recompute', $latest))->toBeTrue();
});

it('prunes rows older than 30 days', function () {
    $ancient = ScheduledTaskRun::factory()->create(['created_at' => now()->subDays(31), 'started_at' => now()->subDays(31)]);
    $recent = ScheduledTaskRun::factory()->create(['created_at' => now()->subDays(2)]);

    (new ScheduledTaskRun)->prunable()->get()->each->delete();

    expect(ScheduledTaskRun::find($recent->id))->not->toBeNull()
        ->and(ScheduledTaskRun::find($ancient->id))->toBeNull();
});

it('prunes zombie running rows older than a day', function () {
    $zombie = ScheduledTaskRun::factory()->running()->startedAt(now()->subDays(2))->create(['created_at' => now()->subDays(2)]);
    $freshRunning = ScheduledTaskRun::factory()->running()->startedAt(now()->subMinutes(2))->create();

    (new ScheduledTaskRun)->prunable()->get()->each->delete();

    expect(ScheduledTaskRun::find($zombie->id))->toBeNull()
        ->and(ScheduledTaskRun::find($freshRunning->id))->not->toBeNull();
});

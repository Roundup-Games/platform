<?php

use App\Support\Scheduling\ScheduleInventory;
use App\Support\Scheduling\TaskIdentity;
use Illuminate\Console\Scheduling\Schedule;

//
// ScheduleInventory: the web-context schedule loader. routes/console.php
// is only loaded after the Console kernel resolves — web workers (FPM)
// never do, so the admin schedule page and the health summary saw an
// empty schedule in production while console-run tests saw the full one.
// This is the regression guard for exactly that split-brain.
beforeEach(function () {
    app()->forgetInstance(Schedule::class);
});

it('loads the schedule definitions in web context where the console kernel never ran', function () {
    // Fresh Schedule singleton = exactly what an FPM request starts with.
    expect(app(Schedule::class)->events())->toBeEmpty();

    $_ENV['APP_RUNNING_IN_CONSOLE'] = 'false';

    try {
        $events = ScheduleInventory::events();

        expect($events)->not->toBeEmpty()
            ->and(collect($events)->map(fn ($event) => TaskIdentity::command($event)))
            ->toContain('horizon:snapshot', 'bgg:weekly-sync', 'attendance:resolve')
            // The registrations landed on the container singleton — later
            // consumers see them without re-loading.
            ->and(app(Schedule::class)->events())->not->toBeEmpty();
    } finally {
        unset($_ENV['APP_RUNNING_IN_CONSOLE']);
    }
});

it('does not double-load when events are already registered', function () {
    $events = ScheduleInventory::events();

    expect($events)->not->toBeEmpty()
        ->and(ScheduleInventory::events())->toBe($events);
});

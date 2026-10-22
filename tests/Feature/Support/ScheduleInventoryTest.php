<?php

use App\Support\Scheduling\ScheduleInventory;
use App\Support\Scheduling\TaskIdentity;
use Illuminate\Console\Scheduling\Event;
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

// ── Shipped schedule-flag pin (the overlap contract, executable) ──

it('pins the overlap-guard flags the concurrency comments promise', function () {
    // The hardening contract — e.g. subscriptions:expire's 10-minute
    // overlap mutex (a re-dispatched MembershipEnded cascade) — lives
    // in route comments unless something executable reads the REAL
    // schedule. A copy-paste casualty that drops a chain call must
    // fail here, not in production.
    $events = collect(ScheduleInventory::events());

    $byCommand = static fn (string $needle) => $events->first(
        fn (Event $event): bool => str_contains(TaskIdentity::command($event), $needle)
    );

    // subscriptions:expire — the 10-minute overlap mutex (the TTL
    // lands on expiresAt; withoutOverlapping itself is just the bool).
    $expire = $byCommand('subscriptions:expire');
    expect($expire)->not->toBeNull()
        ->and($expire->withoutOverlapping)->toBeTrue()
        ->and($expire->expiresAt)->toBe(10)
        ->and($expire->onOneServer)->toBeTrue();

    // Every other shipped entry is single-server.
    $multiServer = $events->filter(fn (Event $event): bool => ! $event->onOneServer);
    expect($multiServer)->toBeEmpty();

    // The heavy nightly jobs the queue-trust contract names run in the
    // background with an overlap guard of their own.
    foreach (['badges:sweep', 'bgg:weekly-sync'] as $needle) {
        $event = $byCommand($needle);
        expect($event)->not->toBeNull("no scheduled entry matches [{$needle}]")
            ->and($event->runInBackground)->toBeTrue()
            ->and($event->withoutOverlapping)->toBeTrue();
    }
});

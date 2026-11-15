<?php

use App\Models\NotificationEngagementCounter;
use App\Services\NotificationEngagementCounters;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * NotificationEngagementCounters (M080/S03, D284): the daily-aggregate
 * upsert behind notification engagement telemetry. These tests pin the
 * counter semantics only — the capture points (webhook, unsubscribe,
 * push, discord) get their own per-path tests in later tasks of the slice.
 */

it('creates one counter row on first increment', function () {
    $this->travelTo(Carbon::parse('2026-11-14 12:00:00'));

    app(NotificationEngagementCounters::class)->incrementFor('waitlist_promoted', 'mail', 'delivered');

    $row = NotificationEngagementCounter::query()->first();

    expect($row)->not->toBeNull()
        ->and($row->date->toDateString())->toBe('2026-11-14')
        ->and($row->category)->toBe('waitlist_promoted')
        ->and($row->channel)->toBe('mail')
        ->and($row->event)->toBe('delivered')
        ->and($row->count)->toBe(1);
});

it('accumulates repeated increments on a single row, never per-event rows', function () {
    $counters = app(NotificationEngagementCounters::class);

    $counters->incrementFor('game_invitation', 'mail', 'opened');
    $counters->incrementFor('game_invitation', 'mail', 'opened');
    $counters->incrementFor('game_invitation', 'mail', 'opened');

    expect(NotificationEngagementCounter::query()->count())->toBe(1)
        ->and(NotificationEngagementCounter::query()->first()->count)->toBe(3);
});

it('isolates counters per category, channel, and event', function () {
    $counters = app(NotificationEngagementCounters::class);

    $counters->incrementFor('game_invitation', 'mail', 'delivered');
    $counters->incrementFor('game_invitation', 'mail', 'delivered');
    $counters->incrementFor('waitlist_promoted', 'mail', 'delivered');
    $counters->incrementFor('game_invitation', 'push', 'delivered');
    $counters->incrementFor('game_invitation', 'mail', 'opened');

    $countFor = function (string $category, string $channel, string $event): int {
        return (int) NotificationEngagementCounter::query()
            ->where('category', $category)
            ->where('channel', $channel)
            ->where('event', $event)
            ->value('count');
    };

    expect(NotificationEngagementCounter::query()->count())->toBe(4)
        ->and($countFor('game_invitation', 'mail', 'delivered'))->toBe(2)
        ->and($countFor('waitlist_promoted', 'mail', 'delivered'))->toBe(1)
        ->and($countFor('game_invitation', 'push', 'delivered'))->toBe(1)
        ->and($countFor('game_invitation', 'mail', 'opened'))->toBe(1);
});

it('buckets by the event day across the midnight boundary', function () {
    $counters = app(NotificationEngagementCounters::class);

    // 23:59:59 and 00:00:01 one second apart land in different daily rows.
    $counters->incrementFor('session_reminder', 'mail', 'delivered', Carbon::parse('2026-11-14 23:59:59'));
    $counters->incrementFor('session_reminder', 'mail', 'delivered', Carbon::parse('2026-11-15 00:00:01'));
    // Two events on the same day accumulate into that day's row.
    $counters->incrementFor('session_reminder', 'mail', 'delivered', Carbon::parse('2026-11-15 09:15:00'));

    $rows = NotificationEngagementCounter::query()->orderBy('date')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->date->toDateString())->toBe('2026-11-14')
        ->and($rows[0]->count)->toBe(1)
        ->and($rows[1]->date->toDateString())->toBe('2026-11-15')
        ->and($rows[1]->count)->toBe(2);
});

it('buckets by the UTC calendar day even when the instant carries a non-UTC timezone', function () {
    $counters = app(NotificationEngagementCounters::class);

    // 2026-11-14 20:00 America/New_York is 2026-11-15 01:00 UTC — the UTC
    // calendar day must win over the instance's own zone. Guards the
    // copy()->utc() normalization: toDateString() alone would format the
    // instance's local date (2026-11-14) and silently shift the bucket.
    $instant = Carbon::parse('2026-11-14 20:00:00', 'America/New_York');

    $counters->incrementFor('digest', 'mail', 'delivered', $instant);

    expect(NotificationEngagementCounter::query()->count())->toBe(1)
        ->and(NotificationEngagementCounter::query()->first()->date->toDateString())->toBe('2026-11-15')
        // The caller-owned Carbon instance must not be mutated (the shared
        // model-attribute contract the service docblock pins).
        ->and($instant->timezoneName)->toBe('America/New_York')
        ->and($instant->toDateTimeString())->toBe('2026-11-14 20:00:00');
});

it('defaults the bucket day to the current day', function () {
    $this->travelTo(Carbon::parse('2026-11-14 18:30:00'));

    app(NotificationEngagementCounters::class)->incrementFor('digest', 'mail', 'delivered');

    $row = NotificationEngagementCounter::query()->first();

    expect($row->date->toDateString())->toBe('2026-11-14')
        ->and($row->category)->toBe('digest');
});

it('rolls back to a savepoint so a failed increment never aborts the caller transaction', function () {
    $this->travelTo(Carbon::parse('2026-11-14 12:00:00'));

    // Pins the savepoint contract in the service docblock: a failed upsert
    // inside a caller's transaction rolls back to its own savepoint before
    // rethrowing, so the caller's log-and-continue never inherits an
    // aborted transaction (Postgres 25P02 — every later write in the
    // request would otherwise fail). Without the DB::transaction() wrapper
    // a "simplification" to a bare upsert keeps every other test green
    // (they all run outside a transaction) while silently breaking every
    // in-transaction capture point.
    $counters = app(NotificationEngagementCounters::class);

    DB::transaction(function () use ($counters): void {
        try {
            // A category longer than the varchar(64) column forces a
            // QueryException inside the upsert (Postgres enforces
            // varchar length deterministically; the test DB is pgsql).
            $counters->incrementFor(str_repeat('x', 300), 'mail', 'delivered');
            $this->fail('incrementFor must rethrow the failed write.');
        } catch (QueryException) {
            // The service intentionally rethrows — the caller owns the
            // graceful-degradation decision (log-and-continue).
        }

        // The SAME transaction must still be usable: if the failed upsert
        // had aborted the whole transaction (25P02 without the savepoint),
        // this insert would fail with "current transaction is aborted".
        DB::table('notification_engagement_counters')->insert([
            'date' => '2026-11-14',
            'category' => 'waitlist_promoted',
            'channel' => 'mail',
            'event' => 'delivered',
            'count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    // The outer transaction committed: the post-failure write persisted,
    // and the failed increment left no row (rolled back to its savepoint).
    expect(NotificationEngagementCounter::query()->count())->toBe(1)
        ->and(NotificationEngagementCounter::query()->value('category'))->toBe('waitlist_promoted');
});

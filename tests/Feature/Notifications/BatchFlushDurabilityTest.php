<?php

use App\Jobs\FlushNotificationBatch;
use App\Models\NotificationBatch;
use App\Models\User;
use App\Notifications\BatchedNotification;
use App\Services\NotificationEngagementCounters;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * Dual-path flush durability (M082/S01/T03, D296): the delayed
 * FlushNotificationBatch job plus the notifications:flush-batches sweep,
 * joined by processed_at idempotency. Redis is ephemeral — the delayed job
 * may run zero times (lost scheduled job) or several times (sweep raced a
 * slow worker, redelivery) — and the observable effect must be
 * exactly-once: ONE BatchedNotification per batch, ever.
 *
 * Clock frozen throughout (K014): every window/arithmetic assertion is
 * deterministic against 2026-11-19 12:00:00.
 */

/**
 * One buffered event snapshot — the item payload contract documented on
 * BatchedNotification (written by the buffer path in T04).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function batchFlushItemPayload(array $overrides = []): array
{
    return array_merge([
        'actor_id' => '01900000-0000-7000-8000-00000000000a',
        'actor_name' => 'Actor One',
        'entity_type' => 'game',
        'entity_id' => '01900000-0000-7000-8000-0000000000e1',
        'entity_name' => 'Durability Game',
        'event' => 'participant_joined',
        'context' => [],
        'action_url' => 'https://roundup.test/en/games/01900000-0000-7000-8000-0000000000e1',
        'occurred_at' => '2026-11-19T11:58:00+00:00',
    ], $overrides);
}

/**
 * Seed a batch row for a recipient (morph columns via getMorphClass(),
 * INV-11). Defaults to a CLOSED unprocessed window — the sweep's target.
 */
function seedFlushBatch(User $recipient, array $attributes = []): NotificationBatch
{
    return NotificationBatch::query()->create(array_merge([
        'recipient_type' => $recipient->getMorphClass(),
        'recipient_id' => $recipient->id,
        'category' => 'participant_joined',
        'batch_key' => 'participant_joined:01900000-0000-7000-8000-0000000000e1',
        'closes_at' => now()->subMinute(),
    ], $attributes));
}

/**
 * Append item snapshots, one named actor each. uuidv7 ids are time-ordered,
 * so each item is created a wall-clock second apart to guarantee the
 * append order the items() relation's id ordering exposes. Each actor
 * also carries its OWN actor id — distinct actors have distinct ids in
 * production, and the batched actor derivation dedupes by id first.
 *
 * @param  list<string>  $actorNames
 */
function seedFlushItems(NotificationBatch $batch, array $actorNames): void
{
    foreach ($actorNames as $index => $name) {
        if ($index > 0) {
            Carbon::setTestNow(now()->addSecond());
        }

        $batch->items()->create([
            'payload' => batchFlushItemPayload([
                'actor_name' => $name,
                'actor_id' => sprintf('01900000-0000-7000-8000-%012d', $index + 1),
            ]),
        ]);
    }
}

/**
 * Count of flushed events recorded in the D284 batch-channel aggregate.
 */
function flushedCounterCount(): int
{
    return (int) DB::table('notification_engagement_counters')
        ->where('category', 'participant_joined')
        ->where('channel', 'batch')
        ->where('event', 'flushed')
        ->value('count');
}

it('flushes one batched notification carrying the underlying category and marks the batch processed', function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));

    $recipient = User::factory()->create();
    $batch = seedFlushBatch($recipient);
    seedFlushItems($batch, ['Actor A', 'Actor B', 'Actor C']);

    FlushNotificationBatch::dispatch($batch->id);

    $rows = $recipient->notifications()->where('type', BatchedNotification::class)->get();
    expect($rows)->toHaveCount(1);

    $data = $rows->first()->data;
    expect($data['type'])
        ->toBe('participant_joined') // the UNDERLYING category — bell grouping + counters stay first-class
        ->and($data['entity_type'])->toBe('game')
        ->and($data['entity_id'])->toBe(batchFlushItemPayload()['entity_id'])
        ->and($data['game_name'])->toBe('Durability Game') // conventional {entity_type}_name key
        ->and($data['actor_names'])->toBe(['Actor A', 'Actor B', 'Actor C']) // append order, deduplicated
        ->and($data['action_url'])->toBe(batchFlushItemPayload()['action_url'])
        ->and($data['batched'])->toBeTrue()
        ->and($data['batch_count'])->toBe(3);

    expect($batch->refresh()->processed_at)->not->toBeNull()
        ->and($batch->flush_attempts)->toBe(0); // the job itself never counts attempts — only the sweep does

    expect(flushedCounterCount())->toBe(1);
});

it('recovers a lost delayed flush via the every-minute sweep', function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));

    $recipient = User::factory()->create();
    $batch = seedFlushBatch($recipient);
    seedFlushItems($batch, ['Actor A', 'Actor B']);

    // The delayed job never ran — redis dropped the scheduled job (the
    // exact ephemerality the sweep exists for). No direct dispatch here.

    $this->artisan('notifications:flush-batches')->assertSuccessful();

    expect($recipient->notifications()->where('type', BatchedNotification::class)->count())->toBe(1)
        ->and($batch->refresh()->processed_at)->not->toBeNull()
        ->and($batch->flush_attempts)->toBe(1) // the sweep counted its dispatch before handing off
        ->and(flushedCounterCount())->toBe(1);
});

it('keeps a double flush idempotent — one notification, original processed_at preserved', function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));

    $recipient = User::factory()->create();
    $batch = seedFlushBatch($recipient);
    seedFlushItems($batch, ['Actor A']);

    FlushNotificationBatch::dispatch($batch->id);
    $firstProcessedAt = $batch->refresh()->processed_at?->toIso8601String();
    expect($firstProcessedAt)->not->toBeNull();

    // Five minutes later the delayed job and a sweep dispatch race, or a
    // redelivery replays the job — either way it must be a no-op.
    $this->travelTo(Carbon::parse('2026-11-19 12:05:00'));
    FlushNotificationBatch::dispatch($batch->id);

    expect($recipient->notifications()->where('type', BatchedNotification::class)->count())->toBe(1)
        ->and($batch->refresh()->processed_at?->toIso8601String())->toBe($firstProcessedAt) // original mark survives
        ->and(flushedCounterCount())->toBe(1);
});

it('sweeps only closed unprocessed batches', function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));
    Queue::fake();

    $recipient = User::factory()->create();

    // Still inside its 5-minute window — must NOT be touched.
    $open = seedFlushBatch($recipient, [
        'batch_key' => 'participant_joined:01900000-0000-7000-8000-0000000000f1',
        'closes_at' => now()->addMinutes(5),
    ]);
    seedFlushItems($open, ['Actor A']);

    // Already flushed — no redispatch, or the sweep would fight the
    // idempotency guard every minute forever.
    $processed = seedFlushBatch($recipient, [
        'batch_key' => 'participant_joined:01900000-0000-7000-8000-0000000000f2',
        'closes_at' => now()->subMinutes(10),
    ]);
    $processed->markProcessed();

    // The sweep's one legitimate target.
    $closed = seedFlushBatch($recipient, [
        'batch_key' => 'participant_joined:01900000-0000-7000-8000-0000000000f3',
    ]);
    seedFlushItems($closed, ['Actor A']);

    $this->artisan('notifications:flush-batches')->assertSuccessful();

    Queue::assertPushed(FlushNotificationBatch::class, 1);
    Queue::assertPushed(
        FlushNotificationBatch::class,
        fn (FlushNotificationBatch $job): bool => $job->batchId === $closed->id,
    );

    expect($open->refresh()->flush_attempts)->toBe(0)
        ->and($processed->refresh()->flush_attempts)->toBe(0)
        ->and($closed->refresh()->flush_attempts)->toBe(1);
});

it('bounds sweep redispatches at five attempts', function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));
    Queue::fake();

    $recipient = User::factory()->create();
    $batch = seedFlushBatch($recipient, ['flush_attempts' => 4]);
    seedFlushItems($batch, ['Actor A']);

    // Fifth sweep dispatch: allowed (and counted)…
    $this->artisan('notifications:flush-batches')->assertSuccessful();
    Queue::assertPushed(FlushNotificationBatch::class, 1);
    expect($batch->refresh()->flush_attempts)->toBe(5);

    // …the sixth minute must never dispatch again — the batch is
    // dead-lettered rather than retried forever.
    $this->artisan('notifications:flush-batches')->assertSuccessful();
    Queue::assertPushed(FlushNotificationBatch::class, 1);
    expect($batch->refresh()->flush_attempts)->toBe(5);
});

it('burns no flush attempt when the sweep dispatch itself fails', function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));

    $recipient = User::factory()->create();
    $batch = seedFlushBatch($recipient);
    seedFlushItems($batch, ['Actor A']);

    // Queue backend down: the dispatch throws before any job is handed
    // off (the redis-outage case the M082 post-review hardening covers).
    // The attempt is counted only AFTER a successful dispatch, so the
    // sweep aborting must leave the budget intact for the backend's
    // recovery — instead of dead-lettering the batch on burns that never
    // delivered a job.
    Queue::shouldReceive('connection')->andThrow(new RuntimeException('queue unavailable'));

    try {
        $this->artisan('notifications:flush-batches')->run();
    } catch (RuntimeException) {
        // The sweep aborts loudly — the queue backend is down. What must
        // NOT happen is the batch burning its attempt budget on the way
        // out.
    }

    expect($batch->refresh()->flush_attempts)->toBe(0)
        ->and($batch->processed_at)->toBeNull();
});

it('marks an empty batch processed without sending anything', function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));

    $recipient = User::factory()->create();

    // Cannot occur through the buffer path (batch + first item share one
    // transaction) — but if it ever does, the flush must release the key
    // without rendering an empty notification.
    $batch = seedFlushBatch($recipient);

    FlushNotificationBatch::dispatch($batch->id);

    expect($recipient->notifications()->count())->toBe(0)
        ->and($batch->refresh()->processed_at)->not->toBeNull()
        ->and(flushedCounterCount())->toBe(0);
});

it('fails loudly on a broken item payload and leaves the batch unprocessed for real retries', function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));

    $recipient = User::factory()->create();
    $batch = seedFlushBatch($recipient);

    // A snapshot violating the buffer-path contract (empty entity_name —
    // only reachable by DB corruption or a future writer bug, but the
    // flush must still fail HONESTLY): the mark rolls back with the
    // transaction, so the job's tries and the sweep's attempt bound see a
    // real failure and dead-letter visibly, never a processed batch whose
    // events vanished behind a no-op skip (M082 review reordering).
    $batch->items()->create([
        'payload' => batchFlushItemPayload(['entity_name' => '']),
    ]);

    FlushNotificationBatch::dispatch($batch->id);

    expect(true)->toBeTrue(); // PHPUnit::ExpectError stops the test on throw
})->throws(UnexpectedValueException::class, 'entity_name');

it('verifies the broken-payload batch survives unprocessed', function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));

    $recipient = User::factory()->create();
    $batch = seedFlushBatch($recipient);
    $batch->items()->create([
        'payload' => batchFlushItemPayload(['entity_name' => '']),
    ]);

    try {
        (new FlushNotificationBatch($batch->id))
            ->handle(app(NotificationService::class), app(NotificationEngagementCounters::class));
        $this->fail('The flush must throw on a broken payload contract.');
    } catch (UnexpectedValueException) {
        // The honest failure.
    }

    expect($batch->refresh()->processed_at)->toBeNull()
        ->and($recipient->notifications()->count())->toBe(0)
        ->and(flushedCounterCount())->toBe(0);
});

it('marks the batch processed and sends nothing when the recipient was deleted mid-window', function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));

    $recipient = User::factory()->create();
    $batch = seedFlushBatch($recipient);
    seedFlushItems($batch, ['Actor A']);

    $recipient->forceDelete();

    FlushNotificationBatch::dispatch($batch->id);

    expect($batch->refresh()->processed_at)->not->toBeNull()
        ->and(flushedCounterCount())->toBe(0);
});

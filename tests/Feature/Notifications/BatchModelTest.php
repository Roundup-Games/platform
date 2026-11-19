<?php

use App\Models\NotificationBatch;
use App\Models\NotificationBatchItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/*
 * NotificationBatch / NotificationBatchItem storage semantics (M082/S01,
 * D296): the model primitives the buffer interception (T04) and the dual
 * flush paths (T03) build on — scope predicates, processed_at
 * idempotency, and the open-batch uniqueness of the partial index.
 */

/**
 * Create a batch row for a recipient under a batch key. Morph columns
 * are written through the recipient's morph alias (INV-11 convention —
 * getMorphClass(), never an FQCN).
 */
function createNotificationBatch(User $recipient, ?string $batchKey = null, array $attributes = []): NotificationBatch
{
    return NotificationBatch::query()->create(array_merge([
        'recipient_type' => $recipient->getMorphClass(),
        'recipient_id' => $recipient->id,
        'category' => 'participant_joined',
        'batch_key' => $batchKey ?? ('participant_joined:'.Str::uuid()),
        'closes_at' => now()->addMinutes(5),
    ], $attributes));
}

it('collects every unprocessed batch in the open scope regardless of closes_at', function () {
    $user = User::factory()->create();

    $openFuture = createNotificationBatch($user, 'participant_joined:game-1');
    $openPast = createNotificationBatch($user, 'participant_joined:game-2', [
        'closes_at' => now()->subMinute(),
    ]);
    $processed = createNotificationBatch($user, 'participant_joined:game-3');
    $processed->markProcessed();

    $open = NotificationBatch::query()->open()->get();

    // The past-closing batch MUST match: an event arriving after
    // closes_at but before the flush ran appends to it (T04 relies on
    // the scope ignoring closes_at).
    expect($open->pluck('id'))->toContain($openFuture->id)
        ->and($open->pluck('id'))->toContain($openPast->id)
        ->and($open)->toHaveCount(2)
        ->and($open->first()->flush_attempts)->toBe(0)
        ->and($open->first()->closes_at)->toBeInstanceOf(Carbon::class);
});

it('restricts the closed-unprocessed scope to elapsed unflushed windows', function () {
    $user = User::factory()->create();

    $futureOpen = createNotificationBatch($user, 'participant_joined:game-1', [
        'closes_at' => now()->addMinutes(5),
    ]);
    $pastOpen = createNotificationBatch($user, 'participant_joined:game-2', [
        'closes_at' => now()->subMinute(),
    ]);
    $pastProcessed = createNotificationBatch($user, 'participant_joined:game-3', [
        'closes_at' => now()->subMinute(),
    ]);
    $pastProcessed->markProcessed();

    $closed = NotificationBatch::query()->closedUnprocessed()->get();

    expect($closed)->toHaveCount(1)
        ->and($closed->first()->id)->toBe($pastOpen->id)
        ->and($closed->pluck('id'))->not->toContain($futureOpen->id)
        ->and($closed->pluck('id'))->not->toContain($pastProcessed->id);
});

it('marks a batch processed exactly once, preserving the original stamp', function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));

    $batch = createNotificationBatch(User::factory()->create());

    expect($batch->markProcessed())->toBeTrue()
        ->and($batch->processed_at)->toBeInstanceOf(Carbon::class)
        ->and($batch->processed_at->toDateTimeString())->toBe('2026-11-19 12:00:00')
        // The persisted row carries the stamp, not just the instance.
        ->and($batch->fresh()->processed_at?->toDateTimeString())->toBe('2026-11-19 12:00:00');

    $this->travel(5)->minutes();

    // A second flush attempt (sweep recovering a lost delayed job) must
    // be a no-op — processed_at is the idempotency marker.
    expect($batch->markProcessed())->toBeFalse()
        ->and($batch->fresh()->processed_at?->toDateTimeString())->toBe('2026-11-19 12:00:00');
});

it('rejects a second open batch for the same recipient and key', function () {
    $user = User::factory()->create();
    createNotificationBatch($user, 'participant_joined:game-1');

    createNotificationBatch($user, 'participant_joined:game-1');
})->throws(QueryException::class, 'notification_batches_open_batch_unique');

it('releases a key for a new batch once the previous one is processed', function () {
    $user = User::factory()->create();

    $first = createNotificationBatch($user, 'participant_joined:game-1');
    $first->markProcessed();

    // Same recipient + key now opens a fresh window — the partial
    // predicate (processed_at IS NULL) no longer matches the old row.
    $second = createNotificationBatch($user, 'participant_joined:game-1');

    expect($second->exists)->toBeTrue()
        ->and(NotificationBatch::query()->open()->get())->toHaveCount(1)
        ->and(NotificationBatch::query()->open()->first()->id)->toBe($second->id)
        ->and(NotificationBatch::query()->count())->toBe(2);
});

it('isolates open batches per recipient and per key', function () {
    $host = User::factory()->create();
    $otherHost = User::factory()->create();

    createNotificationBatch($host, 'participant_joined:game-1');

    // Same key, different recipient — coexists.
    createNotificationBatch($otherHost, 'participant_joined:game-1');

    // Same recipient, different key — coexists.
    createNotificationBatch($host, 'participant_joined:game-2');

    // Same recipient uuid AND key, different morph type — coexists:
    // recipient_type is part of the unique index precisely so two morph
    // types sharing a uuid can never collide.
    NotificationBatch::query()->create([
        'recipient_type' => 'campaign',
        'recipient_id' => $host->id,
        'category' => 'session_added_to_campaign',
        'batch_key' => 'participant_joined:game-1',
        'closes_at' => now()->addMinutes(5),
    ]);

    expect(NotificationBatch::query()->open()->count())->toBe(4);
});

it('appends immutable payload snapshots in deterministic append order', function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));

    $batch = createNotificationBatch(User::factory()->create());

    foreach (['Sarah', 'Alex', 'Mira'] as $actorName) {
        $batch->items()->create([
            'payload' => [
                'actor_name' => $actorName,
                'actor_id' => (string) Str::uuid(),
                'entity_name' => 'Thursday Runde',
                'entity_type' => 'game',
                'event_context' => ['slot' => 3],
                'created_at' => now()->toISOString(),
            ],
        ]);
        $this->travel(1)->seconds();
    }

    // Eager load: lazy loading is disabled for loaded models in the
    // test environment — relation queries via with() are the sanctioned
    // shape (the relation's built-in orderBy keeps append order).
    $items = $batch->items()->with('batch')->get();

    expect($items)->toHaveCount(3)
        ->and($items->pluck('payload.actor_name')->all())->toBe(['Sarah', 'Alex', 'Mira'])
        ->and($items->pluck('payload.event_context.slot')->all())->toBe([3, 3, 3]);

    $first = $items->first();
    expect($first->id)->toBeString()
        ->and(Str::isUuid($first->id))->toBeTrue()
        ->and($first->created_at)->toBeInstanceOf(Carbon::class)
        ->and($first->created_at?->toDateTimeString())->toBe('2026-11-19 12:00:00')
        // Snapshot rows are create-only: no updated_at column exists.
        ->and($first->batch_id)->toBe($batch->id)
        ->and($first->batch->id)->toBe($batch->id);
});

it('deletes a batch items with the batch', function () {
    $batch = createNotificationBatch(User::factory()->create());
    $batch->items()->create(['payload' => ['actor_name' => 'Sarah']]);
    $batch->items()->create(['payload' => ['actor_name' => 'Alex']]);

    expect(NotificationBatchItem::query()->count())->toBe(2);

    $batch->delete();

    expect(NotificationBatch::query()->count())->toBe(0)
        ->and(NotificationBatchItem::query()->count())->toBe(0);
});

it('prunes processed batches past the retention horizon and cascades their items', function () {
    $expired = createNotificationBatch(User::factory()->create(), 'participant_joined:game-1', [
        'processed_at' => now()->subDays(NotificationBatch::PRUNE_AFTER_DAYS + 1),
    ]);
    $expired->items()->create(['payload' => ['actor_name' => 'Sarah']]);

    $kept = createNotificationBatch(User::factory()->create(), 'participant_joined:game-2', [
        'processed_at' => now()->subDays(NotificationBatch::PRUNE_AFTER_DAYS - 1),
    ]);
    $kept->items()->create(['payload' => ['actor_name' => 'Alex']]);

    (new NotificationBatch)->prunable()->get()->each->delete();

    // The buffer's lifecycle end-state: a flushed batch is internal
    // state — the user artifact lives in notifications, the analytics in
    // the D284 counters — so the daily model:prune sweep reclaims it
    // (items cascade) once the retention horizon passes.
    expect(NotificationBatch::find($expired->id))->toBeNull()
        ->and(NotificationBatchItem::query()->where('batch_id', $expired->id)->exists())->toBeFalse()
        ->and(NotificationBatch::find($kept->id))->not->toBeNull()
        ->and(NotificationBatchItem::query()->where('batch_id', $kept->id)->exists())->toBeTrue();
});

it('never prunes unprocessed batches, however old — dead-letters stay forensic', function () {
    $ancient = now()->subDays(NotificationBatch::PRUNE_AFTER_DAYS + 30);

    // A sweep dead-letter: closed, unprocessed, past the attempt bound.
    $deadLetter = createNotificationBatch(User::factory()->create(), 'participant_joined:game-1', [
        'closes_at' => $ancient,
        'flush_attempts' => 5,
    ]);
    // An ancient batch that never even closed (operator-made rows in
    // tests/debugging share the same protection).
    $open = createNotificationBatch(User::factory()->create(), 'participant_joined:game-2', [
        'closes_at' => $ancient,
    ]);

    (new NotificationBatch)->prunable()->get()->each->delete();

    // Unprocessed rows are outside retention by design: an open row is
    // the live window, a closed unprocessed row is the forensic record
    // for the notification_batch.* logs and the alerting follow-up —
    // pruned rows cannot be investigated.
    expect(NotificationBatch::find($deadLetter->id))->not->toBeNull()
        ->and(NotificationBatch::find($open->id))->not->toBeNull()
        ->and((new NotificationBatch)->prunable()->count())->toBe(0);
});

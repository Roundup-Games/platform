<?php

use App\Enums\NotificationCategory;
use App\Jobs\FlushNotificationBatch;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\NotificationBatch;
use App\Models\User;
use App\Models\UserRelationship;
use App\Notifications\BatchedNotification;
use App\Notifications\ParticipantJoined;
use App\Notifications\SessionAddedToCampaign;
use App\Notifications\WaitlistPromoted;
use App\Services\NotificationEngagementCounters;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Buffer-or-dispatch interception (M082/S01/T04, D296): the write half of
 * the batch-on-write defer window living in NotificationService::send().
 *
 * The clock is frozen throughout (K014): window arithmetic (closes_at,
 * dispatch delay) asserts exact values against 2026-11-19 12:00:00. Queue::fake
 * suppresses the sync connection's inline execution of the delayed flush, so
 * buffer-state assertions observe the window mid-flight; where a flush must
 * actually run, the job's handle() is invoked directly (the BatchFlushDurability
 * suite owns the flush side's own durability matrix).
 */

/**
 * The D284 batch-channel counter for participant_joined (0 when no row).
 */
function deferWindowCounterCount(string $event): int
{
    return (int) DB::table('notification_engagement_counters')
        ->where('category', 'participant_joined')
        ->where('channel', 'batch')
        ->where('event', $event)
        ->value('count');
}

/**
 * The host's open-or-recent batch for a game's burst (null when none).
 */
function deferWindowBatch(User $host, Game $game): ?NotificationBatch
{
    return NotificationBatch::query()
        ->where('recipient_type', $host->getMorphClass())
        ->where('recipient_id', $host->id)
        ->where('batch_key', 'participant_joined:'.$game->id)
        ->first();
}

/**
 * Send one join event to the host (the exact dispatch call the production
 * flows make — ParticipantLifecycle::acceptInvitation et al.).
 */
function deferWindowSendJoin(User $host, Game $game, User $joiner): void
{
    app(NotificationService::class)->send(
        $host,
        new ParticipantJoined($joiner, $game, 'game'),
        NotificationCategory::ParticipantJoined,
    );
}

it('opens one fixed window per burst: first event creates the batch, later events append without extending it', function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));
    Queue::fake();

    $host = User::factory()->create();
    $joinerA = User::factory()->create(['name' => 'Joiner A']);
    $joinerB = User::factory()->create(['name' => 'Joiner B']);
    $game = Game::factory()->create(['owner_id' => $host->id, 'name' => ['en' => 'Defer Window Game']]);

    deferWindowSendJoin($host, $game, $joinerA);

    // Nothing dispatched yet — the window is open.
    expect($host->notifications()->count())->toBe(0);

    $batch = deferWindowBatch($host, $game);
    expect($batch)->not->toBeNull()
        ->and($batch->category)->toBe('participant_joined')
        ->and($batch->batch_key)->toBe('participant_joined:'.$game->id)
        ->and($batch->closes_at->format('Y-m-d H:i:s'))->toBe('2026-11-19 12:05:00') // 5-minute fixed window (D296)
        ->and($batch->processed_at)->toBeNull();

    // The serialized snapshot is the BatchedNotification item contract.
    $items = $batch->items()->get();
    expect($items)->toHaveCount(1);
    $payload = $items->first()->payload;
    expect($payload['actor_id'])->toBe($joinerA->id)
        ->and($payload['actor_name'])->toBe('Joiner A')
        ->and($payload['entity_type'])->toBe('game')
        ->and($payload['entity_id'])->toBe($game->id)
        ->and($payload['entity_name'])->toBe('Defer Window Game')
        ->and($payload['event'])->toBe('participant_joined')
        ->and($payload['context'])->toBe([])
        ->and($payload['action_url'])->toBe(route('games.show', ['locale' => 'en', 'id' => $game]))
        ->and($payload['occurred_at'])->toBe('2026-11-19T12:00:00+00:00');

    // One delayed flush job rides closes_at (the fast path; the sweep is
    // the durable second path — BatchFlushDurabilityTest owns that side).
    Queue::assertPushed(FlushNotificationBatch::class, 1);
    Queue::assertPushed(
        FlushNotificationBatch::class,
        fn (FlushNotificationBatch $job): bool => $job->batchId === $batch->id
            && $job->delay?->eq(Carbon::parse('2026-11-19 12:05:00')) === true,
    );

    expect(deferWindowCounterCount('event_buffered'))->toBe(1);

    // Second event inside the window joins the SAME batch: fixed window
    // (closes_at unchanged), no second dispatch, no notification yet.
    $this->travelTo(Carbon::parse('2026-11-19 12:02:00'));
    deferWindowSendJoin($host, $game, $joinerB);

    $same = deferWindowBatch($host, $game);
    expect($same->id)->toBe($batch->id)
        ->and($same->closes_at->format('Y-m-d H:i:s'))->toBe('2026-11-19 12:05:00')
        ->and($same->items()->count())->toBe(2)
        ->and($host->notifications()->count())->toBe(0)
        ->and(deferWindowCounterCount('event_buffered'))->toBe(2);

    Queue::assertPushed(FlushNotificationBatch::class, 1);
});

it('snapshots the campaign-hosted arm with the session identity — session bursts batch on the campaign, items carry the session', function () {
    // M082 review: the campaign arm of deferWindowPayload (campaign_id /
    // campaign_name under the campaign-keyed batch) had its CONTENTS
    // asserted nowhere — a wrong-field resolution would only surface at
    // flush time. The session context snapshot (session_id/session_name)
    // is what the batched mail's session-named lines and per-session ICS
    // invitations render from, so it is pinned here at the write side.
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));
    Queue::fake();

    $member = User::factory()->create([
        'notification_settings' => ['session_added_to_campaign' => ['database' => true, 'mail' => true]],
    ]);
    $campaign = Campaign::factory()->create(['name' => ['en' => 'Campaign Arm Probe']]);
    $session = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $campaign->owner_id,
        'name' => ['en' => 'Probe Session'],
    ]);

    app(NotificationService::class)->send(
        $member,
        new SessionAddedToCampaign($session, $campaign),
        NotificationCategory::SessionAddedToCampaign,
    );

    $batch = NotificationBatch::query()
        ->where('batch_key', 'session_added_to_campaign:'.$campaign->id)
        ->first();
    expect($batch)->not->toBeNull()
        ->and($batch->category)->toBe('session_added_to_campaign');

    /** @var array<string, mixed> $payload */
    $payload = $batch->items()->first()->payload;
    expect($payload['entity_type'])->toBe('campaign')
        ->and($payload['entity_id'])->toBe($campaign->id)
        ->and($payload['entity_name'])->toBe('Campaign Arm Probe')
        ->and($payload['event'])->toBe('session_added_to_campaign')
        ->and($payload['action_url'])->toBe(route('games.show', ['locale' => 'en', 'id' => $session]))
        ->and($payload['context'])->toBe(['session_id' => $session->id, 'session_name' => 'Probe Session']);
});

it('flushes early once the batch holds ten items (MaxSize guardrail)', function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));
    Queue::fake();

    $host = User::factory()->create();
    $game = Game::factory()->create(['owner_id' => $host->id]);

    // Nine events: still one batch, one delayed dispatch (the window one).
    $joiners = [];
    for ($i = 1; $i <= 9; $i++) {
        $joiners[] = User::factory()->create(['name' => "Joiner {$i}"]);
        $this->travelTo(now()->addSecond());
        deferWindowSendJoin($host, $game, $joiners[$i - 1]);
    }

    $batch = deferWindowBatch($host, $game);
    expect($batch->items()->count())->toBe(9);
    Queue::assertPushed(FlushNotificationBatch::class, 1);

    // The tenth event trips the guardrail: an UNDELAYED flush dispatch
    // joins the creation-time delayed one (which later no-ops on the
    // processed batch — processed_at idempotency).
    $this->travelTo(now()->addSecond());
    deferWindowSendJoin($host, $game, User::factory()->create(['name' => 'Joiner 10']));

    expect($batch->refresh()->items()->count())->toBe(10)
        ->and(NotificationBatch::count())->toBe(1)
        ->and(deferWindowCounterCount('event_buffered'))->toBe(10);

    /** @var array<int, mixed> $delays */
    $delays = [];
    Queue::assertPushed(
        FlushNotificationBatch::class,
        function (FlushNotificationBatch $job) use ($batch, &$delays): bool {
            if ($job->batchId !== $batch->id) {
                return false;
            }
            $delays[] = $job->delay;

            return true;
        },
    );
    expect($delays)->toHaveCount(2)
        ->and($delays[0]?->eq(Carbon::parse('2026-11-19 12:05:01')))->toBeTrue() // closes_at set at the first event (12:00:01 — the clock advances before each send)
        ->and($delays[1])->toBeNull(); // the MaxSize flush dispatches immediately
});

it('never re-buffers a flushed BatchedNotification (re-entry guard)', function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));

    $host = User::factory()->create();
    $game = Game::factory()->create(['owner_id' => $host->id, 'name' => ['en' => 'Re-entry Game']]);

    // Exactly what FlushNotificationBatch hands back through send() at
    // flush time: a batchable UNDERLYING category on a BatchedNotification.
    $flushed = new BatchedNotification(
        category: NotificationCategory::ParticipantJoined,
        entityType: 'game',
        entityId: $game->id,
        entityName: 'Re-entry Game',
        actionUrl: route('games.show', ['locale' => 'en', 'id' => $game]),
        items: [[
            'actor_id' => $host->id,
            'actor_name' => 'Someone',
            'entity_type' => 'game',
            'entity_id' => $game->id,
            'entity_name' => 'Re-entry Game',
            'event' => 'participant_joined',
            'context' => [],
            'action_url' => route('games.show', ['locale' => 'en', 'id' => $game]),
            'occurred_at' => '2026-11-19T11:58:00+00:00',
        ]],
    );

    app(NotificationService::class)->send($host, $flushed, NotificationCategory::ParticipantJoined);

    // Delivered as a first-class bell row (no Queue::fake here — the fake
    // would swallow Laravel's own SendQueuedNotifications channel job),
    // but NO batch opened and no batch-channel counter double-counting
    // (the flush path's own counter is 'flushed', recorded by the job).
    expect(NotificationBatch::count())->toBe(0)
        ->and($host->notifications()->where('type', BatchedNotification::class)->count())->toBe(1)
        ->and($host->notifications()->first()->data['type'])->toBe('participant_joined')
        ->and(deferWindowCounterCount('event_buffered'))->toBe(0)
        ->and(deferWindowCounterCount('dispatch_immediate'))->toBe(0);
});

it('joins a late event to the closed-unflushed window instead of losing it', function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));

    // Fake ONLY the flush job: the buffer path's inline sync dispatch is
    // suppressed (the "lost delayed job" fixture), while Laravel's own
    // SendQueuedNotifications channel job below still runs on the real
    // sync connection so the bell row actually lands.
    Queue::fake([FlushNotificationBatch::class]);

    $host = User::factory()->create();
    $joinerA = User::factory()->create(['name' => 'Early Joiner']);
    $joinerB = User::factory()->create(['name' => 'Late Joiner']);
    $game = Game::factory()->create(['owner_id' => $host->id]);

    deferWindowSendJoin($host, $game, $joinerA);

    // The delayed flush was lost (redis ephemerality — Queue::fake holds
    // it). The window has CLOSED but the batch is unprocessed: the open()
    // predicate is processed_at IS NULL only, so the late arrival must
    // append rather than violate the open-batch unique index (whose
    // violation send()'s catch would swallow — a silently lost event).
    $this->travelTo(Carbon::parse('2026-11-19 12:06:00'));
    deferWindowSendJoin($host, $game, $joinerB);

    $batch = deferWindowBatch($host, $game);
    expect(NotificationBatch::count())->toBe(1)
        ->and($batch->items()->count())->toBe(2)
        ->and($batch->processed_at)->toBeNull()
        ->and($host->notifications()->count())->toBe(0)
        ->and(deferWindowCounterCount('event_buffered'))->toBe(2);

    // And when the flush finally runs, BOTH events land — no loss.
    (new FlushNotificationBatch($batch->id))
        ->handle(app(NotificationService::class), app(NotificationEngagementCounters::class));

    $rows = $host->notifications()->where('type', BatchedNotification::class)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->data['actor_names'])->toBe(['Early Joiner', 'Late Joiner'])
        ->and($rows->first()->data['batch_count'])->toBe(2);
});

it('dispatches non-batchable categories immediately and counts them', function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));

    $host = User::factory()->create();
    $game = Game::factory()->create(['owner_id' => $host->id]);

    app(NotificationService::class)->send(
        $host,
        new WaitlistPromoted($game, now()->addDays(2)),
        NotificationCategory::WaitlistPromoted,
    );

    expect($host->notifications()->where('type', WaitlistPromoted::class)->count())->toBe(1)
        ->and(NotificationBatch::count())->toBe(0);

    // dispatch_immediate rides the batch channel of the D284 aggregate.
    expect((int) DB::table('notification_engagement_counters')
        ->where('category', 'waitlist_promoted')
        ->where('channel', 'batch')
        ->where('event', 'dispatch_immediate')
        ->value('count'))->toBe(1);
});

it('buffers nothing for a blocked actor — the block-list gate runs first', function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));
    Queue::fake();

    $host = User::factory()->create();
    $joiner = User::factory()->create();
    UserRelationship::block($host, $joiner);
    $game = Game::factory()->create(['owner_id' => $host->id]);

    deferWindowSendJoin($host, $game, $joiner);

    expect(NotificationBatch::count())->toBe(0)
        ->and($host->notifications()->count())->toBe(0)
        ->and(deferWindowCounterCount('event_buffered'))->toBe(0);
});

it('buffers nothing when the recipient has every channel disabled — channel resolution runs first', function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));
    Queue::fake();

    $host = User::factory()->create([
        'notification_settings' => array_merge(
            NotificationCategory::defaultSettings(),
            ['participant_joined' => ['database' => false, 'mail' => false, 'push' => false, 'discord' => false]],
        ),
    ]);
    $joiner = User::factory()->create();
    $game = Game::factory()->create(['owner_id' => $host->id]);

    deferWindowSendJoin($host, $game, $joiner);

    expect(NotificationBatch::count())->toBe(0)
        ->and($host->notifications()->count())->toBe(0)
        ->and(deferWindowCounterCount('event_buffered'))->toBe(0);
});

// ── Concurrent append race (the "silently lost event" guard) ───────────────

it('recovers a lost append race against the open-batch unique index — the event joins the winner', function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));
    Queue::fake([FlushNotificationBatch::class]);

    $host = User::factory()->create();
    $joiner = User::factory()->create(['name' => 'Race Joiner']);
    $game = Game::factory()->create(['owner_id' => $host->id, 'name' => ['en' => 'Race Game']]);
    $batchKey = 'participant_joined:'.$game->id;

    // A raw PDO connection to the same database: it commits OUTSIDE
    // Laravel's DatabaseTransactions wrapper, so its rows persist
    // immediately exactly as a concurrent worker's commit would — the
    // only faithful way to interleave with the append transaction under
    // test (a second Laravel connection would be swallowed by the
    // per-test transaction wrapper).
    $pgsql = config('database.connections.pgsql');
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $pgsql['host'], $pgsql['port'], $pgsql['database']),
        $pgsql['username'],
        $pgsql['password'],
        [PDO::ERRMODE_EXCEPTION => true],
    );

    // Watchdog: any PDO statement that blocks longer than 3s aborts loudly
    // instead of hanging the run (the append's FOR UPDATE lock on the
    // winner row is held by the test transaction until teardown — a
    // row-level DELETE from this side would deadlock on it).
    $pdo->exec("SET statement_timeout = '3s'");

    // Register the key AND the live PDO handle for the afterAll sweep:
    // the PDO-committed winner row outlives the test (its locks — not its
    // data — die with the teardown rollback), so residue cleanup happens
    // once every test in this file has rolled back, before any sibling
    // file runs. The handle is stashed because the app (and config())
    // may already be torn down when afterAll fires.
    $GLOBALS['__batchDeferRaceBatchKeys'][] = $batchKey;
    $GLOBALS['__batchDeferRacePdo'] = $pdo;

    try {
        $raced = false;

        // The race: fire ONCE, between the append transaction's empty
        // lockForUpdate read and its batch INSERT — the exact window in
        // which a concurrent worker commits the winning open row. The
        // model INSERT then hits the partial unique index, the retry
        // loop sees the winner (a fresh READ COMMITTED snapshot), and
        // the event APPENDS instead of being lost to send()'s catch.
        DB::beforeExecuting(function (string $query) use (&$raced, $pdo, $batchKey, $host, $joiner, $game): void {
            if ($raced || ! str_contains($query, 'insert into "notification_batches"')) {
                return;
            }
            $raced = true;

            $winnerId = (string) Str::uuid();
            $pdo->prepare(
                'INSERT INTO notification_batches (id, recipient_type, recipient_id, category, batch_key, closes_at, flush_attempts, created_at, updated_at)
                 VALUES (:id, :rtype, :rid, :category, :bkey, :closes, 0, :now, :now)'
            )->execute([
                'id' => $winnerId,
                'rtype' => $host->getMorphClass(),
                'rid' => $host->id,
                'category' => 'participant_joined',
                'bkey' => $batchKey,
                'closes' => '2026-11-19 12:05:00',
                'now' => '2026-11-19 12:00:30',
            ]);
            $pdo->prepare(
                'INSERT INTO notification_batch_items (id, batch_id, payload, created_at)
                 VALUES (:id, :bid, :payload, :now)'
            )->execute([
                // items() orders by id as append order (time-ordered
                // uuidv7) — the winner's item carries a fixed
                // lexicographically-earliest id so the arrival order is
                // deterministic against the retry's uuidv7.
                'id' => '00000000-0000-7000-8000-000000000001',
                'bid' => $winnerId,
                'payload' => json_encode([
                    'actor_id' => $joiner->id,
                    'actor_name' => 'Concurrent Worker',
                    'entity_type' => 'game',
                    'entity_id' => $game->id,
                    'entity_name' => 'Race Game',
                    'event' => 'participant_joined',
                    'context' => [],
                    'action_url' => 'https://roundup.test/en/games/'.$game->id,
                    'occurred_at' => '2026-11-19T12:00:20+00:00',
                ]),
                'now' => '2026-11-19 12:00:30',
            ]);
        });

        deferWindowSendJoin($host, $game, $joiner);

        // The unique-index violation was retried into an append: ONE open
        // batch (the winner), TWO items, and no dispatch for the joiner's
        // event (only batch CREATION dispatches — this batch was created
        // by the "concurrent worker", whose flush is its own concern).
        $batches = NotificationBatch::query()->where('batch_key', $batchKey)->get();
        expect($batches)->toHaveCount(1)
            ->and($batches->first()->items()->count())->toBe(2)
            ->and($host->notifications()->count())->toBe(0);

        // The loser's item landed with its OWN actor identity — the event
        // joined the winner's batch, it was never dropped.
        $names = $batches->first()->items()->orderBy('id')->get()
            ->map(fn ($item) => $item->payload['actor_name'])->all();
        expect($names)->toBe(['Concurrent Worker', 'Race Joiner']);

        Queue::assertNotPushed(FlushNotificationBatch::class);
    } finally {
        // NO row-level cleanup from this side: the retry's FOR UPDATE
        // lock on the winner row is held inside the test's transaction
        // until teardown, so a PDO DELETE would block on it (the very
        // hang this test must never cause). The winner row is scoped by
        // the game's per-run uuid — collision-proof under --parallel's
        // shared container — and the whole Testcontainers database dies
        // with the pest process.
        $pdo = null;
    }
});

/*
 * Race-test residue sweep: the append-race test commits its "concurrent
 * winner" rows through raw PDO — OUTSIDE the per-test DatabaseTransactions
 * wrapper — and the test's own FOR UPDATE lock on the winner batch row is
 * held until teardown, so no mid-test DELETE from the PDO side can run
 * without deadlocking. afterAll fires after every test in this file has
 * rolled back (locks released) and before the next file's tests execute,
 * which is the only safe moment to clear the residue that would otherwise
 * break sibling suites' sole() assertions.
 */
afterAll(function (): void {
    $keys = $GLOBALS['__batchDeferRaceBatchKeys'] ?? [];
    $pdo = $GLOBALS['__batchDeferRacePdo'] ?? null;
    unset($GLOBALS['__batchDeferRaceBatchKeys'], $GLOBALS['__batchDeferRacePdo']);

    if ($keys === [] || ! $pdo instanceof PDO) {
        return;
    }

    try {
        foreach ($keys as $key) {
            $pdo->prepare(
                'DELETE FROM notification_batch_items WHERE batch_id IN (SELECT id FROM notification_batches WHERE batch_key = :k)'
            )->execute(['k' => $key]);
            $pdo->prepare('DELETE FROM notification_batches WHERE batch_key = :k')->execute(['k' => $key]);
        }
    } catch (Throwable) {
        // Belt: the Testcontainers database dies with the pest process,
        // and the key is per-run uuid-scoped — worst case is an orphan
        // row in an ephemeral database, never a sibling-test collision.
    }
});

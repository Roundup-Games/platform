<?php

use App\Enums\NotificationCategory;
use App\Jobs\FlushNotificationBatch;
use App\Models\Game;
use App\Models\NotificationBatch;
use App\Models\User;
use App\Notifications\BatchedNotification;
use App\Notifications\ParticipantJoined;
use App\Notifications\WaitlistPromoted;
use App\Services\NotificationEngagementCounters;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Queue;

/*
 * Slice demo gate (M082/S01/T05, D296): the end-to-end burst story.
 *
 * Five joins land on one game within the defer window → the host receives
 * exactly ONE BatchedNotification after the window closes (zero individual
 * ParticipantJoined pings), while a waitlist promotion fired mid-window
 * bypasses the window and arrives immediately: five burst events plus one
 * deadline-critical event = two pings, not six.
 *
 * Clock frozen throughout (K014): the burst spans 12:00–12:04 against a
 * 12:05:00 close, so every window assertion is deterministic.
 *
 * Queue::fake([FlushNotificationBatch::class]) emulates redis holding the
 * creation-time delayed flush until closes_at (the sync test driver would
 * otherwise fire it inline at buffer time), while everything else —
 * notably Laravel's own SendQueuedNotifications channel job — still runs
 * on the real sync connection so bell rows land. At window close the held
 * job runs at its scheduled instant, exactly as the redis worker would
 * deliver it (the every-minute sweep path is owned by
 * BatchFlushDurabilityTest).
 */

/**
 * The D284 batch-channel counter for one category (0 when no row).
 */
function burstBatchingCounterCount(string $category, string $event): int
{
    return (int) DB::table('notification_engagement_counters')
        ->where('category', $category)
        ->where('channel', 'batch')
        ->where('event', $event)
        ->value('count');
}

/**
 * Send one join event to the host (the exact dispatch call the production
 * join flows make — ParticipantLifecycle et al.).
 */
function burstBatchingSendJoin(User $host, Game $game, User $joiner): void
{
    app(NotificationService::class)->send(
        $host,
        new ParticipantJoined($joiner, $game, 'game'),
        NotificationCategory::ParticipantJoined,
    );
}

it('lands five burst joins as one batched notification at window close while a mid-window waitlist promotion arrives immediately', function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));

    // Fake ONLY the flush job: the creation-time delayed dispatch is held
    // until closes_at (redis semantics on the test sync driver), while the
    // bell rows themselves still deliver on the real sync connection.
    Queue::fake([FlushNotificationBatch::class]);

    $host = User::factory()->create();
    $game = Game::factory()->create(['owner_id' => $host->id, 'name' => ['en' => 'Demo Gate Game']]);

    // ── The burst: five joins, one per minute, all inside the window ──
    // Events at 12:00–12:04 (60s apart, K014); closes_at pins to the FIRST
    // event: 12:05:00 (fixed window, D296).
    $joiners = [];
    for ($i = 1; $i <= 5; $i++) {
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00')->addMinutes($i - 1));
        $joiners[] = User::factory()->create(['name' => "Joiner {$i}"]);
        burstBatchingSendJoin($host, $game, $joiners[$i - 1]);

        // Mid-window, after the third join: a waitlist promotion for the
        // SAME host on the SAME game is deadline-critical — it must bypass
        // the join burst's window and land NOW, not at 12:05.
        if ($i === 3) {
            $this->travelTo(Carbon::parse('2026-11-19 12:02:30')); // window still open
            app(NotificationService::class)->send(
                $host,
                new WaitlistPromoted($game, now()->addDays(2)),
                NotificationCategory::WaitlistPromoted,
            );

            expect($host->notifications()->where('type', WaitlistPromoted::class)->count())->toBe(1)
                ->and($host->notifications()->where('type', BatchedNotification::class)->count())->toBe(0)
                ->and($host->notifications()->where('type', ParticipantJoined::class)->count())->toBe(0)
                ->and(burstBatchingCounterCount('waitlist_promoted', 'dispatch_immediate'))->toBe(1);
        }
    }

    // The burst held: one batch, five items, nothing dispatched yet.
    // Scoped to the fixture's key: under --parallel, sibling suites may
    // hold committed residue rows outside this test's transaction (see
    // BatchDeferWindowTest's PDO race guard) — a table-wide sole() is a
    // cross-worker race (MultipleRecordsFoundException, CI 2026-11-20).
    $batch = NotificationBatch::query()
        ->where('batch_key', 'participant_joined:'.$game->id)
        ->sole();
    expect($batch->batch_key)->toBe('participant_joined:'.$game->id)
        ->and($batch->items()->count())->toBe(5)
        ->and($batch->processed_at)->toBeNull()
        ->and(burstBatchingCounterCount('participant_joined', 'event_buffered'))->toBe(5);

    // Exactly one flush dispatch rode the window close — no MaxSize flush
    // at five items.
    Queue::assertPushed(FlushNotificationBatch::class, 1);
    Queue::assertPushed(
        FlushNotificationBatch::class,
        fn (FlushNotificationBatch $job): bool => $job->batchId === $batch->id
            && $job->delay?->eq(Carbon::parse('2026-11-19 12:05:00')) === true,
    );

    // ── Window close: the delayed flush fires at its scheduled instant ──
    $this->travelTo(Carbon::parse('2026-11-19 12:05:30'));
    (new FlushNotificationBatch($batch->id))
        ->handle(app(NotificationService::class), app(NotificationEngagementCounters::class));

    // ONE batched notification, zero individual joins: five burst events
    // plus one promotion = two pings total, not six.
    expect($host->notifications()->count())->toBe(2)
        ->and($host->notifications()->where('type', BatchedNotification::class)->count())->toBe(1)
        ->and($host->notifications()->where('type', ParticipantJoined::class)->count())->toBe(0)
        ->and($host->notifications()->where('type', WaitlistPromoted::class)->count())->toBe(1);

    // The batched row is first-class: the UNDERLYING category rides
    // data.type (bell grouping + counters), the five joiners appear in
    // append order, and the batch is marked processed (idempotency for
    // any redelivery — a replayed job or a raced sweep is a no-op).
    /** @var array<string, mixed> $data */
    $data = $host->notifications()->where('type', BatchedNotification::class)->first()->data;
    expect($data['type'])->toBe('participant_joined')
        ->and($data['entity_type'])->toBe('game')
        ->and($data['entity_id'])->toBe($game->id)
        ->and($data['game_name'])->toBe('Demo Gate Game')
        ->and($data['actor_names'])->toBe(['Joiner 1', 'Joiner 2', 'Joiner 3', 'Joiner 4', 'Joiner 5'])
        ->and($data['batched'])->toBeTrue()
        ->and($data['batch_count'])->toBe(5);

    expect($batch->refresh()->processed_at)->not->toBeNull();

    // The D284 batch-channel counters tell the same story: five buffered
    // events, one immediate bypass, one flush — and the batched dispatch
    // itself never counts as immediate (the flush path's own counter is
    // 'flushed'; counting both would corrupt the batched-vs-immediate
    // ratio the S03 matrix page reads).
    expect(burstBatchingCounterCount('participant_joined', 'event_buffered'))->toBe(5)
        ->and(burstBatchingCounterCount('participant_joined', 'flushed'))->toBe(1)
        ->and(burstBatchingCounterCount('participant_joined', 'dispatch_immediate'))->toBe(0)
        ->and(burstBatchingCounterCount('waitlist_promoted', 'dispatch_immediate'))->toBe(1);

    // ── The recurring-digest story (M082 review): the next burst on the
    // SAME entity opens a FRESH window through the production dispatch
    // path — markProcessed released the partial unique index, so the
    // same batch_key lives again without violating it. A popular game
    // produces repeated 5-minute windows; this is the normal operating
    // mode, not an edge.
    $this->travelTo(Carbon::parse('2026-11-19 12:10:00'));
    $lateJoiners = [
        User::factory()->create(['name' => 'Late Joiner 1']),
        User::factory()->create(['name' => 'Late Joiner 2']),
    ];
    burstBatchingSendJoin($host, $game, $lateJoiners[0]);
    burstBatchingSendJoin($host, $game, $lateJoiners[1]);

    $secondBatch = NotificationBatch::query()->whereNull('processed_at')->sole();
    expect($secondBatch->id)->not->toBe($batch->id)
        ->and($secondBatch->items()->count())->toBe(2)
        ->and($secondBatch->closes_at->eq(Carbon::parse('2026-11-19 12:15:00')))->toBeTrue(
            'the second window pins to ITS first event, not the first window\'s close',
        );

    $this->travelTo(Carbon::parse('2026-11-19 12:15:30'));
    (new FlushNotificationBatch($secondBatch->id))
        ->handle(app(NotificationService::class), app(NotificationEngagementCounters::class));

    // Two batches → two batched rows; the second row lists ONLY the new
    // joiners (per-burst grouping, never merged).
    $batchedRows = $host->notifications()->where('type', BatchedNotification::class)->get();
    expect($batchedRows)->toHaveCount(2);

    /** @var array<string, mixed> $secondData */
    $secondData = $batchedRows->sortByDesc('created_at')->first()->data;
    expect($secondData['actor_names'])->toBe(['Late Joiner 1', 'Late Joiner 2'])
        ->and($secondData['batch_count'])->toBe(2);
});

it('delivers exactly one batched mail across the flush for a mail-enabled recipient', function () {
    // M082 review: "exactly one notification per channel" was only pinned
    // end-to-end for the database channel. This gate runs the same burst
    // with mail enabled and captures the rendered messages — the flush
    // must yield ONE batched mail (and no individual join mails), the
    // exact multiplication M082 exists to prevent.
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));

    Queue::fake([FlushNotificationBatch::class]);

    $capturedMails = collect();
    EventFacade::listen(MessageSending::class, fn (MessageSending $event): bool => $capturedMails->push($event->message) ?? true);

    $host = User::factory()->create([
        'notification_settings' => ['participant_joined' => ['mail' => true]],
    ]);
    $game = Game::factory()->create(['owner_id' => $host->id, 'name' => ['en' => 'Mail Gate Game']]);

    for ($i = 1; $i <= 3; $i++) {
        burstBatchingSendJoin($host, $game, User::factory()->create(['name' => "Mailer {$i}"]));
    }

    expect($capturedMails)->toHaveCount(0)
        ->and($host->notifications)->toHaveCount(0);

    $batch = NotificationBatch::query()->sole();
    $this->travelTo(Carbon::parse('2026-11-19 12:05:30'));
    (new FlushNotificationBatch($batch->id))
        ->handle(app(NotificationService::class), app(NotificationEngagementCounters::class));

    // ONE mail for three events — the digest, never three (and never a
    // fourth "immediate" mail from the flush's own dispatch).
    expect($capturedMails)->toHaveCount(1)
        ->and($capturedMails->first()->getSubject())->toBe(trans_choice('notifications.subject_batched', 3, [
            'count' => 3,
            'entity' => 'Mail Gate Game',
        ], 'en'))
        ->and($host->notifications()->where('type', BatchedNotification::class)->count())->toBe(1);
});

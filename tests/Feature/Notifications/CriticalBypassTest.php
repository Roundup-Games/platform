<?php

use App\Enums\NotificationCategory;
use App\Jobs\FlushNotificationBatch;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\NotificationBatch;
use App\Models\Team;
use App\Models\User;
use App\Notifications\AccountSuspended;
use App\Notifications\BatchedNotification;
use App\Notifications\ContentRemoved;
use App\Notifications\ContentReportWarning;
use App\Notifications\EntityCancelled;
use App\Notifications\EntityInvitation;
use App\Notifications\EventCancelled;
use App\Notifications\ParticipantJoined;
use App\Notifications\SessionAddedToCampaign;
use App\Notifications\TeamInvitation;
use App\Notifications\WaitlistPromoted;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * Critical-bypass hardening (M082/S03/T02, D296): the never-batch half of
 * the burst policy. isBatchable()'s v1 whitelist is tiny, but the safety
 * story is its complement — the deadline- and plan-critical families
 * (waitlist promotions, entity/event cancellations, invitations, the
 * moderation cluster) NEVER buffer, even while an open defer-window batch
 * already holds the SAME recipient and entity a critical event targets.
 * Each case below opens that collision batch, fires the critical event
 * mid-window through the production dispatch surface
 * (NotificationService::send, exactly as the flows call it), and pins the
 * triple bypass invariant:
 *
 *   1. the bell row lands NOW (immediate dispatch, never buffered),
 *   2. the open batch is untouched (same row, same items, same window,
 *      still unprocessed), and
 *   3. the D284 counter ledger says dispatch_immediate for the critical
 *      category and event_buffered ONLY for the burst.
 *
 * Clock frozen throughout (K014): the window spans 12:00 → 12:05 and the
 * critical event fires at 12:02, mid-window. Queue::fake fakes ONLY
 * FlushNotificationBatch — the open batch stays open (the sync driver
 * would fire its delayed flush inline) while Laravel's own
 * SendQueuedNotifications job still runs on the real connection, so the
 * critical bell rows land.
 */

/**
 * The D284 batch-channel counter for one category (0 when no row).
 */
function criticalBypassCounterCount(string $category, string $event): int
{
    return (int) DB::table('notification_engagement_counters')
        ->where('category', $category)
        ->where('channel', 'batch')
        ->where('event', $event)
        ->value('count');
}

/**
 * Open the collision fixture — the recipient's defer-window batch — and
 * return it holding exactly one buffered burst item, window pinned at
 * 12:00 → 12:05 (K014). Game batches ride the join burst
 * (participant_joined), campaign batches the session burst
 * (session_added_to_campaign): the two entity kinds the v1 batched set
 * covers, so "same recipient+entity" collisions are real for both.
 *
 * @param  'game'|'campaign'  $kind
 */
function criticalBypassOpenBatch(User $recipient, string $kind, Game $game, Campaign $campaign, Game $session, User $actor): NotificationBatch
{
    if ($kind === 'campaign') {
        app(NotificationService::class)->send(
            $recipient,
            new SessionAddedToCampaign($session, $campaign),
            NotificationCategory::SessionAddedToCampaign,
        );
    } else {
        app(NotificationService::class)->send(
            $recipient,
            new ParticipantJoined($actor, $game, 'game'),
            NotificationCategory::ParticipantJoined,
        );
    }

    /** @var NotificationBatch */
    return NotificationBatch::query()->sole();
}

/**
 * The buffered burst's category for one collision-batch kind (the ledger's
 * event_buffered row after the fixture runs).
 *
 * @param  'game'|'campaign'  $kind
 */
function criticalBypassBurstCategory(string $kind): string
{
    return $kind === 'campaign' ? 'session_added_to_campaign' : 'participant_joined';
}

it('dispatches deadline-critical categories immediately while an open batch holds the same recipient and entity', function (string $batchKind, string $expectedType, string $counterCategory, Closure $critical) {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));
    Queue::fake([FlushNotificationBatch::class]);

    $host = User::factory()->create();
    $actor = User::factory()->create(['name' => 'Burst Actor']);
    $game = Game::factory()->create(['owner_id' => $host->id, 'name' => ['en' => 'Bypass Game']]);
    $campaign = Campaign::factory()->create(['owner_id' => $host->id, 'name' => ['en' => 'Bypass Campaign']]);
    $session = Game::factory()->create(['owner_id' => $host->id, 'name' => ['en' => 'Bypass Session']]);
    $team = Team::factory()->create(['created_by' => $actor->id]);
    $event = Event::factory()->create(['organizer_id' => $actor->id]);

    $batch = criticalBypassOpenBatch($host, $batchKind, $game, $campaign, $session, $actor);
    $closesAt = $batch->closes_at->format('Y-m-d H:i:s');

    // Mid-window (12:02): the deadline-critical event fires while the
    // collision batch is open.
    $this->travelTo(Carbon::parse('2026-11-19 12:02:00'));
    [$notification, $category] = $critical($host, $actor, $game, $campaign, $team, $event);
    app(NotificationService::class)->send($host, $notification, $category);

    // 1. Bypassed: the bell row landed NOW with the critical type, and the
    //    ledger records dispatch_immediate for its category — never
    //    event_buffered.
    expect($host->notifications()->where('type', $expectedType)->count())->toBe(1)
        ->and(criticalBypassCounterCount($counterCategory, 'event_buffered'))->toBe(0)
        ->and(criticalBypassCounterCount($counterCategory, 'dispatch_immediate'))->toBe(1);

    // 2. The open batch is untouched: still the single batch row, still
    //    one burst item, same window, still unprocessed.
    expect(NotificationBatch::count())->toBe(1)
        ->and($batch->refresh()->items()->count())->toBe(1)
        ->and($batch->closes_at->format('Y-m-d H:i:s'))->toBe($closesAt)
        ->and($batch->processed_at)->toBeNull();

    // 3. The buffered side of the ledger is exactly the burst event — and
    //    no flush beyond the burst's own creation-time dispatch was pushed.
    expect(criticalBypassCounterCount(criticalBypassBurstCategory($batchKind), 'event_buffered'))->toBe(1);
    Queue::assertPushed(FlushNotificationBatch::class, 1);
})->with([
    'waitlist promotion bypasses the join burst on the same game' => [
        'game', WaitlistPromoted::class, 'waitlist_promoted',
        fn (User $host, User $actor, Game $game, Campaign $campaign, Team $team, Event $event) => [
            new WaitlistPromoted($game, now()->addDays(2)),
            NotificationCategory::WaitlistPromoted,
        ],
    ],
    'game cancellation bypasses the join burst on the same game' => [
        'game', EntityCancelled::class, 'game_cancelled',
        fn (User $host, User $actor, Game $game, Campaign $campaign, Team $team, Event $event) => [
            new EntityCancelled($game),
            NotificationCategory::GameCancelled,
        ],
    ],
    'campaign cancellation bypasses the session burst on the same campaign' => [
        'campaign', EntityCancelled::class, 'campaign_cancelled',
        fn (User $host, User $actor, Game $game, Campaign $campaign, Team $team, Event $event) => [
            new EntityCancelled($campaign),
            NotificationCategory::CampaignCancelled,
        ],
    ],
    'game invitation bypasses the join burst on the same game' => [
        'game', EntityInvitation::class, 'game_invitation',
        fn (User $host, User $actor, Game $game, Campaign $campaign, Team $team, Event $event) => [
            new EntityInvitation($game, $actor),
            NotificationCategory::GameInvitation,
        ],
    ],
    'team invitation bypasses the recipient\'s open join batch' => [
        'game', TeamInvitation::class, 'team_invitation',
        fn (User $host, User $actor, Game $game, Campaign $campaign, Team $team, Event $event) => [
            new TeamInvitation($team, $actor),
            NotificationCategory::TeamInvitation,
        ],
    ],
    'event cancellation bypasses the recipient\'s open join batch' => [
        'game', EventCancelled::class, 'event_registration',
        fn (User $host, User $actor, Game $game, Campaign $campaign, Team $team, Event $event) => [
            new EventCancelled($event),
            NotificationCategory::EventRegistration,
        ],
    ],
    'moderation warning bypasses the recipient\'s open join batch' => [
        'game', ContentReportWarning::class, 'moderation_notice',
        fn (User $host, User $actor, Game $game, Campaign $campaign, Team $team, Event $event) => [
            new ContentReportWarning('game', 'Bypass Game', 'spam'),
            NotificationCategory::ModerationNotice,
        ],
    ],
    'moderation content removal bypasses the recipient\'s open join batch' => [
        'game', ContentRemoved::class, 'moderation_notice',
        fn (User $host, User $actor, Game $game, Campaign $campaign, Team $team, Event $event) => [
            new ContentRemoved('game', 'Bypass Game', 'spam'),
            NotificationCategory::ModerationNotice,
        ],
    ],
    'account suspension bypasses the recipient\'s open join batch' => [
        'game', AccountSuspended::class, 'moderation_notice',
        fn (User $host, User $actor, Game $game, Campaign $campaign, Team $team, Event $event) => [
            new AccountSuspended('spam'),
            NotificationCategory::ModerationNotice,
        ],
    ],
]);

it('never buffers a BatchedNotification, even while an open batch holds the same key (re-entry guard)', function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));
    Queue::fake([FlushNotificationBatch::class]);

    $host = User::factory()->create();
    $joiner = User::factory()->create(['name' => 'Burst Joiner']);
    $game = Game::factory()->create(['owner_id' => $host->id, 'name' => ['en' => 'Re-entry Collision Game']]);
    $session = Game::factory()->create(['owner_id' => $host->id, 'name' => ['en' => 'Re-entry Session']]);
    $campaign = Campaign::factory()->create(['owner_id' => $host->id, 'name' => ['en' => 'Re-entry Campaign']]);

    $batch = criticalBypassOpenBatch($host, 'game', $game, $campaign, $session, $joiner);
    $closesAt = $batch->closes_at->format('Y-m-d H:i:s');

    // Exactly what FlushNotificationBatch hands back through send() at
    // flush time — the SAME batchable category on the SAME entity — while
    // the recipient's current window is still open.
    $flushed = new BatchedNotification(
        category: NotificationCategory::ParticipantJoined,
        entityType: 'game',
        entityId: $game->id,
        entityName: 'Re-entry Collision Game',
        actionUrl: route('games.show', ['locale' => 'en', 'id' => $game]),
        items: [[
            'actor_id' => $joiner->id,
            'actor_name' => 'Burst Joiner',
            'entity_type' => 'game',
            'entity_id' => $game->id,
            'entity_name' => 'Re-entry Collision Game',
            'event' => 'participant_joined',
            'context' => [],
            'action_url' => route('games.show', ['locale' => 'en', 'id' => $game]),
            'occurred_at' => '2026-11-19T11:58:00+00:00',
        ]],
    );

    // The guard's second arm, pinned directly: batchKey() stays inherited
    // null — with no key there is nothing to look up even if the instanceof
    // arm were ever removed (the first arm is the instanceof exclusion in
    // send()).
    expect($flushed->batchKey($host))->toBeNull();

    app(NotificationService::class)->send($host, $flushed, NotificationCategory::ParticipantJoined);

    // Delivered as a first-class bell row (the flush path's terminal
    // event), never buffered: the open batch still holds exactly its one
    // burst item with the same window, no second batch exists, and the
    // ledger shows no immediate double-count for the batched dispatch
    // (the flush path's own counter is 'flushed').
    expect($host->notifications()->where('type', BatchedNotification::class)->count())->toBe(1)
        ->and($host->notifications()->first()->data['type'])->toBe('participant_joined')
        ->and(NotificationBatch::count())->toBe(1)
        ->and($batch->refresh()->items()->count())->toBe(1)
        ->and($batch->closes_at->format('Y-m-d H:i:s'))->toBe($closesAt)
        ->and($batch->processed_at)->toBeNull()
        ->and(criticalBypassCounterCount('participant_joined', 'event_buffered'))->toBe(1)
        ->and(criticalBypassCounterCount('participant_joined', 'dispatch_immediate'))->toBe(0);
});

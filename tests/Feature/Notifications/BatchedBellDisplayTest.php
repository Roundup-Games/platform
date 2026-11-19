<?php

use App\Enums\NotificationCategory;
use App\Jobs\FlushNotificationBatch;
use App\Livewire\Notifications\NotificationBell;
use App\Livewire\Notifications\NotificationsPage;
use App\Models\Game;
use App\Models\NotificationBatch;
use App\Models\User;
use App\Notifications\BatchedNotification;
use App\Notifications\NewApplication;
use App\Notifications\ParticipantJoined;
use App\Services\NotificationEngagementCounters;
use App\Services\NotificationQueryService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;

/*
 * Batched bell display (M082 post-milestone review): the grouped in-app
 * surface composes a flushed burst's row the way the S02 demo promised —
 * "one in-app group row with all five names" — with a truthful verb.
 *
 * Two gaps this file pins (both fixed in the same pass):
 *   1. buildGroups ignored the batched payload's actor_names/actor_ids, so
 *      rows rendered a subjectless verb ("joined: <game>").
 *   2. The plain "{shortType}_{date}" group key merged every same-day
 *      burst into one group whose verb came from the latest row — actors
 *      from a join burst would read under an application verb. Batched
 *      rows now group per burst (category + entity ride the key), and
 *      markAsRead/expandedGroupIndividuals scope through the same parts.
 *
 * Clock frozen throughout (K014). Queue::fake([FlushNotificationBatch])
 * holds the creation-time delayed dispatch the way redis would (the sync
 * driver would otherwise flush inline at buffer time), and the flush runs
 * through the production job handle at window close — the
 * BurstBatchingIntegrationTest pattern.
 */

/**
 * Send one join event to the host (the exact dispatch call the production
 * join flows make).
 */
function batchedBellSendJoin(User $host, Game $game, User $joiner): void
{
    app(NotificationService::class)->send(
        $host,
        new ParticipantJoined($joiner, $game, 'game'),
        NotificationCategory::ParticipantJoined,
    );
}

/**
 * Send one application event to the host (the exact dispatch call the
 * production apply flows make).
 */
function batchedBellSendApplication(User $host, Game $game, User $applicant): void
{
    app(NotificationService::class)->send(
        $host,
        new NewApplication($applicant, $game, 'game'),
        NotificationCategory::NewApplication,
    );
}

/**
 * Flush every unprocessed batch through the production job handle.
 */
function batchedBellFlushAll(): void
{
    NotificationBatch::query()
        ->whereNull('processed_at')
        ->get()
        ->each(fn (NotificationBatch $batch) => (new FlushNotificationBatch($batch->id))
            ->handle(app(NotificationService::class), app(NotificationEngagementCounters::class)));
}

it('composes the burst row with every actor name, aligned ids, and the underlying verb', function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));
    Queue::fake([FlushNotificationBatch::class]);

    $host = User::factory()->create();
    $game = Game::factory()->create(['owner_id' => $host->id, 'name' => ['en' => 'Bell Display Game']]);

    $joiners = [];
    for ($i = 1; $i <= 5; $i++) {
        $this->travelTo(now()->addSecond());
        $joiners[] = User::factory()->create(['name' => "Joiner {$i}"]);
        batchedBellSendJoin($host, $game, $joiners[$i - 1]);
    }

    // Window held by the fake; flush at close through the production job.
    $this->travelTo(Carbon::parse('2026-11-19 12:05:30'));
    batchedBellFlushAll();

    expect($host->notifications()->where('type', BatchedNotification::class)->count())->toBe(1);

    $groups = app(NotificationQueryService::class)->getGroupedForUser($host);
    expect($groups)->toHaveCount(1);

    $group = $groups->first();
    expect($group->type)->toBe('BatchedNotification')
        ->and($group->category)->toBe('participant_joined')
        ->and($group->actorNames)->toBe(['Joiner 1', 'Joiner 2', 'Joiner 3', 'Joiner 4', 'Joiner 5'])
        ->and($group->actorIds)->toBe(array_map(fn (User $joiner): string => $joiner->id, $joiners))
        ->and($group->groupKey)->toBe('BatchedNotification:participant_joined:'.$game->id.'_2026-11-19');

    // The composed sentence carries the names and the verb; the linked
    // entity rides the HTML composition (the accessibility twin omits
    // the entity for actor sentences by established display-copy design).
    // Names render as profile links because actor_ids ride the payload.
    expect($group->displayString)->toContain('Joiner 1')
        ->and($group->displayString)->toContain(__('notifications.verb_participant_joined'))
        ->and($group->displayHtml)->toContain('Joiner 1</a>')
        ->and($group->displayHtml)->toContain('Bell Display Game</a>');
});

it('agrees the German composed sentence for a multi-actor burst', function () {
    $this->travelTo(Carbon::parse('2026-11-19 12:00:00'));
    app()->setLocale('de');
    Queue::fake([FlushNotificationBatch::class]);

    $host = User::factory()->create();
    $game = Game::factory()->create(['owner_id' => $host->id]);

    batchedBellSendJoin($host, $game, User::factory()->create(['name' => 'Sarah']));
    $this->travelTo(now()->addSecond());
    batchedBellSendJoin($host, $game, User::factory()->create(['name' => 'Alex']));

    $this->travelTo(Carbon::parse('2026-11-19 12:05:30'));
    batchedBellFlushAll();

    $group = app(NotificationQueryService::class)->getGroupedForUser($host)->first();

    // Two distinct actors select the plural verb (K001: German verbs
    // agree). Actor display order is the group's alphabetical
    // stabilization, not arrival order.
    expect($group->displayString)->toContain(__('notifications.verb_participant_joined_plural'))
        ->and($group->actorNames)->toBe(['Alex', 'Sarah']);
});

it('keeps same-day bursts of different categories in separate groups with their own verbs', function () {
    $this->travelTo(Carbon::parse('2026-11-19 10:00:00'));
    Queue::fake([FlushNotificationBatch::class]);

    $host = User::factory()->create();
    $joinGame = Game::factory()->create(['owner_id' => $host->id, 'name' => ['en' => 'Join Game']]);
    $applyGame = Game::factory()->create(['owner_id' => $host->id, 'name' => ['en' => 'Apply Game']]);

    // A join burst on one game…
    batchedBellSendJoin($host, $joinGame, User::factory()->create(['name' => 'Sarah']));
    // …and an application burst on another, same day.
    $this->travelTo(Carbon::parse('2026-11-19 15:00:00'));
    batchedBellSendApplication($host, $applyGame, User::factory()->create(['name' => 'Alex']));

    batchedBellFlushAll();

    $groups = app(NotificationQueryService::class)->getGroupedForUser($host);
    expect($groups)->toHaveCount(2);

    $byCategory = $groups->keyBy(fn ($group): string => $group->category);

    // Each burst composes with ITS OWN actors and verb — Sarah never reads
    // under the application verb, Alex never under the join verb. The
    // linked entity rides the HTML composition of each group.
    expect($byCategory['participant_joined']->actorNames)->toBe(['Sarah'])
        ->and($byCategory['participant_joined']->displayString)->toContain(__('notifications.verb_participant_joined'))
        ->and($byCategory['participant_joined']->displayHtml)->toContain('Join Game</a>')
        ->and($byCategory['new_application']->actorNames)->toBe(['Alex'])
        ->and($byCategory['new_application']->displayString)->toContain(__('notifications.verb_new_application'))
        ->and($byCategory['new_application']->displayHtml)->toContain('Apply Game</a>')
        ->and($byCategory['participant_joined']->groupKey)->not->toBe($byCategory['new_application']->groupKey);
});

it('scopes bell mark-as-read to one burst — a sibling burst stays unread', function () {
    $this->travelTo(Carbon::parse('2026-11-19 10:00:00'));
    Queue::fake([FlushNotificationBatch::class]);

    $host = User::factory()->create();
    $joinGame = Game::factory()->create(['owner_id' => $host->id]);
    $applyGame = Game::factory()->create(['owner_id' => $host->id]);

    batchedBellSendJoin($host, $joinGame, User::factory()->create(['name' => 'Sarah']));
    $this->travelTo(Carbon::parse('2026-11-19 15:00:00'));
    batchedBellSendApplication($host, $applyGame, User::factory()->create(['name' => 'Alex']));

    batchedBellFlushAll();

    $joinRow = $host->notifications()
        ->where('type', BatchedNotification::class)
        ->where('data->type', 'participant_joined')
        ->first();
    expect($joinRow->read_at)->toBeNull();

    $bell = Livewire::actingAs($host)->test(NotificationBell::class);
    $bell->call('markAsRead', 'BatchedNotification:participant_joined:'.$joinGame->id.'_2026-11-19');

    expect($joinRow->fresh()->read_at)->not->toBeNull()
        ->and($host->notifications()->where('data->type', 'new_application')->first()->read_at)->toBeNull();
});

it('scopes page mark-as-read through the per-burst key and keeps legacy keys working', function () {
    $this->travelTo(Carbon::parse('2026-11-19 10:00:00'));
    Queue::fake([FlushNotificationBatch::class]);

    $host = User::factory()->create();
    $game = Game::factory()->create(['owner_id' => $host->id]);
    $otherGame = Game::factory()->create(['owner_id' => $host->id]);

    // Two SAME-category bursts on DIFFERENT games, same day: same verb, but
    // each burst must stay its own group with its own rows.
    batchedBellSendJoin($host, $game, User::factory()->create(['name' => 'Sarah']));
    $this->travelTo(Carbon::parse('2026-11-19 15:00:00'));
    batchedBellSendJoin($host, $otherGame, User::factory()->create(['name' => 'Alex']));

    batchedBellFlushAll();

    $page = Livewire::actingAs($host)->test(NotificationsPage::class);

    $joinKey = 'BatchedNotification:participant_joined:'.$game->id.'_2026-11-19';

    // Marking one game's burst read leaves the other game's unread.
    $page->call('markAsRead', $joinKey);
    expect($host->notifications()->where('data->entity_id', $game->id)->first()->read_at)->not->toBeNull()
        ->and($host->notifications()->where('data->entity_id', $otherGame->id)->first()->read_at)->toBeNull();

    // Legacy plain keys still parse (backward compatibility for groups
    // rendered before the per-burst split): the parser degrades to the
    // type+date scoping the old format carried.
    $page->call('markAsRead', 'BatchedNotification_2026-11-19');
    expect($host->unreadNotifications()->count())->toBe(0);
});

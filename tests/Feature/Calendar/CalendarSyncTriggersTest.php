<?php

use App\Enums\CalendarSyncOperation;
use App\Jobs\SyncCalendarEventForUser;
use App\Livewire\Campaigns\CampaignsPage;
use App\Livewire\Events\ManageEvent;
use App\Livewire\Events\ManageRegistrations;
use App\Livewire\Events\RegisterForEvent;
use App\Livewire\Games\ApplyToGame;
use App\Livewire\Games\GamesPage;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Services\Calendar\Google\GoogleCalendarSyncService;
use App\Services\EventLifecycleService;
use App\Services\ParticipantLifecycle;
use App\Services\WaitlistService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

//
// Pins the managed-sync trigger wiring (M083/S03-T03): the sanctioned
// write paths queue exactly one SyncCalendarEventForUser per affected
// connected user — with the feed's commitment semantics (approved seats
// and confirmed registrations are calendar commitments; waitlisted /
// benched / pending are not; campaign membership fans out to in-window
// sessions; a cancelled campaign keeps its sessions), zero dispatch for
// unconnected users and disabled source toggles, and the queued job's
// own convergence rules (revocation terminal skip, 404/410 tolerated on
// cancel/delete, last_synced stamping).
//

beforeEach(function () {
    Queue::fake();
});

// ── Helpers ────────────────────────────────────────────

/**
 * A user with a live managed-sync connection (all sources on unless
 * overridden).
 *
 * @param  array{games: bool, campaign_sessions: bool, events: bool}  $toggles
 */
function calendarTriggerUser(array $toggles = ['games' => true, 'campaign_sessions' => true, 'events' => true]): User
{
    $user = User::factory()->create(['profile_complete' => true]);

    GoogleCalendarConnection::factory()->create([
        'user_id' => $user->id,
        'source_toggles' => $toggles,
    ]);

    return $user;
}

/**
 * A scheduled, public, in-window game with room — the join-flow shape.
 *
 * @param  array<string, mixed>  $overrides
 */
function calendarTriggerGame(User $owner, array $overrides = []): Game
{
    return Game::factory()->create(array_merge([
        'owner_id' => $owner->id,
        'visibility' => 'public',
        'status' => 'scheduled',
        'date_time' => now()->addDays(7),
        'max_players' => 6,
        'min_players' => 1,
    ], $overrides));
}

function calendarTriggerAssertUpsert(User $user, Game|Event $entity): void
{
    Queue::assertPushed(SyncCalendarEventForUser::class, fn (SyncCalendarEventForUser $job) => $job->userId === $user->id
        && $job->entityUuid === $entity->id
        && $job->entityType === ($entity instanceof Event ? 'event' : 'game')
        && $job->operation === CalendarSyncOperation::Upsert);
}

function calendarTriggerAssertNoCalendarJob(): void
{
    Queue::assertNotPushed(SyncCalendarEventForUser::class);
}

// ── Join + approval triggers ───────────────────────────

describe('join and approval triggers', function () {
    it('joining a public game through the real apply flow queues an upsert for the joiner', function () {
        $owner = User::factory()->create();
        $game = calendarTriggerGame($owner);
        $player = calendarTriggerUser();

        Livewire::actingAs($player)
            ->test(ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Count me in!')
            ->call('submitApplication');

        expect(GameParticipant::where('game_id', $game->id)->where('user_id', $player->id)->first()?->status?->value)
            ->toBe('approved');

        calendarTriggerAssertUpsert($player, $game);
    });

    it('an unconnected joiner dispatches zero calendar jobs — zero overhead is the design requirement', function () {
        $owner = User::factory()->create();
        $game = calendarTriggerGame($owner);
        $player = User::factory()->create(['profile_complete' => true]);

        Livewire::actingAs($player)
            ->test(ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Count me in!')
            ->call('submitApplication');

        calendarTriggerAssertNoCalendarJob();
    });

    it('a disabled games source toggle dispatches no job for a game join', function () {
        $owner = User::factory()->create();
        $game = calendarTriggerGame($owner);
        $player = calendarTriggerUser(toggles: ['games' => false, 'campaign_sessions' => true, 'events' => true]);

        Livewire::actingAs($player)
            ->test(ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Count me in!')
            ->call('submitApplication');

        calendarTriggerAssertNoCalendarJob();
    });

    it('an overflow join (waitlisted) dispatches nothing — not a calendar commitment yet', function () {
        $owner = User::factory()->create();
        $game = calendarTriggerGame($owner, ['max_players' => 1]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'status' => 'approved',
        ]);
        $player = calendarTriggerUser();

        Livewire::actingAs($player)
            ->test(ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Count me in!')
            ->call('submitApplication');

        calendarTriggerAssertNoCalendarJob();
    });

    it('a confirmed waitlist promotion queues an upsert', function () {
        $owner = User::factory()->create();
        $game = calendarTriggerGame($owner);
        $player = calendarTriggerUser();

        $participant = GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'status' => 'pending',
            'confirmation_expires_at' => now()->addHour(),
        ]);

        app(WaitlistService::class)->confirmPromotion($participant);

        calendarTriggerAssertUpsert($player, $game);
    });

    it('an approved campaign membership fans out one upsert per in-window session — past sessions excluded', function () {
        $gm = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $gm->id]);
        $futureA = calendarTriggerGame($gm, ['campaign_id' => $campaign->id, 'date_time' => now()->addDays(10)]);
        $futureB = calendarTriggerGame($gm, ['campaign_id' => $campaign->id, 'date_time' => now()->addDays(30)]);
        calendarTriggerGame($gm, ['campaign_id' => $campaign->id, 'date_time' => now()->subDays(2)]);

        $member = calendarTriggerUser();

        $participant = CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
            'user_id' => $member->id,
            'status' => 'pending',
            'confirmation_expires_at' => now()->addHour(),
        ]);

        app(WaitlistService::class)->confirmPromotion($participant);

        $upserted = collect();
        Queue::assertPushed(SyncCalendarEventForUser::class, function (SyncCalendarEventForUser $job) use (&$upserted): bool {
            if ($job->operation === CalendarSyncOperation::Upsert) {
                $upserted->push($job->entityUuid);
            }

            return true;
        });

        expect($upserted->sort()->values()->all())->toBe(collect([$futureA->id, $futureB->id])->sort()->values()->all());
    });
});

// ── Departure + removal triggers ───────────────────────

describe('departure and removal triggers', function () {
    it('leaving a game through the real flow queues a delete for a previously approved participant', function () {
        $owner = User::factory()->create();
        $game = calendarTriggerGame($owner);
        $player = calendarTriggerUser();

        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'status' => 'approved',
        ]);

        Livewire::actingAs($player)
            ->test(GamesPage::class)
            ->call('leaveGame', $game->id);

        Queue::assertPushed(SyncCalendarEventForUser::class, fn (SyncCalendarEventForUser $job) => $job->userId === $player->id
            && $job->entityUuid === $game->id
            && $job->entityType === 'game'
            && $job->operation === CalendarSyncOperation::Delete);
    });

    it('a waitlisted participant leaving dispatches nothing — they never had the occurrence', function () {
        $owner = User::factory()->create();
        $game = calendarTriggerGame($owner);
        $player = calendarTriggerUser();

        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'status' => 'waitlisted',
        ]);

        Livewire::actingAs($player)
            ->test(GamesPage::class)
            ->call('leaveGame', $game->id);

        calendarTriggerAssertNoCalendarJob();
    });

    it('a host removing an approved participant queues a delete for them', function () {
        $owner = User::factory()->create();
        $game = calendarTriggerGame($owner);
        $player = calendarTriggerUser();

        $participant = GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'status' => 'approved',
        ]);

        app(ParticipantLifecycle::class)->removeParticipant($participant, $game, $owner);

        Queue::assertPushed(SyncCalendarEventForUser::class, fn (SyncCalendarEventForUser $job) => $job->userId === $player->id
            && $job->entityUuid === $game->id
            && $job->operation === CalendarSyncOperation::Delete);
    });

    it('a departing campaign member keeps sessions they still sit approved on', function () {
        $gm = User::factory()->create();
        $member = calendarTriggerUser();
        $campaign = Campaign::factory()->create(['owner_id' => $gm->id]);

        // One session the member still sits on as an approved game
        // participant, one that was membership-only. Sessions the GM hosts
        // are NOT the departing member's entitlement — they delete too.
        $seatedSession = calendarTriggerGame($gm, ['campaign_id' => $campaign->id]);
        GameParticipant::factory()->create([
            'game_id' => $seatedSession->id,
            'user_id' => $member->id,
            'status' => 'approved',
        ]);
        $membershipOnlySession = calendarTriggerGame($gm, ['campaign_id' => $campaign->id]);

        $participant = CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
            'user_id' => $member->id,
            'status' => 'approved',
        ]);

        app(ParticipantLifecycle::class)->depart($participant, $member);

        $deleted = [];
        Queue::assertPushed(SyncCalendarEventForUser::class, function (SyncCalendarEventForUser $job) use (&$deleted): bool {
            if ($job->operation === CalendarSyncOperation::Delete) {
                $deleted[] = $job->entityUuid;
            }

            return true;
        });

        expect($deleted)->toBe([$membershipOnlySession->id]);
    });
});

// ── Game edit fan-out ──────────────────────────────────

describe('game edit fan-out', function () {
    it('a host edit queues one upsert per connected calendar that carries the game — host and connected players only', function () {
        $owner = calendarTriggerUser();
        $game = calendarTriggerGame($owner);

        $connectedPlayer = calendarTriggerUser();
        $unconnectedPlayer = User::factory()->create();

        foreach ([$connectedPlayer, $unconnectedPlayer] as $player) {
            GameParticipant::factory()->create([
                'game_id' => $game->id,
                'user_id' => $player->id,
                'status' => 'approved',
            ]);
        }

        Livewire::actingAs($owner)
            ->test(GamesPage::class)
            ->call('editGame', $game->id)
            ->set('edit_name', 'Moved venue, new name')
            ->call('saveGameEdit');

        $upserted = [];
        Queue::assertPushed(SyncCalendarEventForUser::class, function (SyncCalendarEventForUser $job) use (&$upserted): bool {
            if ($job->operation === CalendarSyncOperation::Upsert) {
                $upserted[] = $job->userId;
            }

            return true;
        });

        expect($upserted)->toContain($owner->id)
            ->and($upserted)->toContain($connectedPlayer->id)
            ->and($upserted)->not->toContain($unconnectedPlayer->id)
            ->and(count($upserted))->toBe(2);
    });

    it('an edit to a game outside the feed window fans out nothing', function () {
        $owner = calendarTriggerUser();
        $game = calendarTriggerGame($owner, ['date_time' => now()->subDays(3)]);

        Livewire::actingAs($owner)
            ->test(GamesPage::class)
            ->call('editGame', $game->id)
            ->set('edit_name', 'Past game edit')
            ->call('saveGameEdit');

        calendarTriggerAssertNoCalendarJob();
    });
});

// ── Event edit fan-out ────────────────────────────────

describe('event edit fan-out', function () {
    it('an organizer edit queues one upsert per connected registrant — the content parity the sweep cannot heal', function () {
        // The reconcile sweep is a presence/status diff by design; content
        // drift (dates, venue, name) only propagates through this trigger —
        // without it an organizer's change never reaches any calendar.
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'slug' => 'cal-sync-edit-'.Str::random(8),
            'status' => 'published',
        ]);

        $connectedRegistrant = calendarTriggerUser();
        $unconnectedRegistrant = User::factory()->create();

        foreach ([$connectedRegistrant, $unconnectedRegistrant] as $registrant) {
            EventRegistration::factory()->confirmed()->create([
                'event_id' => $event->id,
                'user_id' => $registrant->id,
            ]);
        }

        Livewire::actingAs($organizer)
            ->test(ManageEvent::class, ['slug' => $event->slug])
            ->set('venue_name', 'The Bigger Hall')
            ->call('save');

        $upserted = [];
        Queue::assertPushed(SyncCalendarEventForUser::class, function (SyncCalendarEventForUser $job) use (&$upserted): bool {
            if ($job->operation === CalendarSyncOperation::Upsert) {
                $upserted[] = $job->userId;
            }

            return true;
        });

        expect($upserted)->toBe([$connectedRegistrant->id]);
    });

    it('an edit to an event outside the feed window fans out nothing', function () {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'slug' => 'cal-sync-past-edit-'.Str::random(8),
            'status' => 'published',
            'start_date' => now()->subDays(3)->toDateString(),
            'end_date' => now()->subDays(2)->toDateString(),
        ]);

        $registrant = calendarTriggerUser();
        EventRegistration::factory()->confirmed()->create([
            'event_id' => $event->id,
            'user_id' => $registrant->id,
        ]);

        Livewire::actingAs($organizer)
            ->test(ManageEvent::class, ['slug' => $event->slug])
            ->set('venue_name', 'Past venue edit')
            ->call('save');

        calendarTriggerAssertNoCalendarJob();
    });

    it('a cancel submitted through the manage form routes through the lifecycle service and never double-dispatches', function () {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'slug' => 'cal-sync-cancel-form-'.Str::random(8),
            'status' => 'published',
        ]);

        $registrant = calendarTriggerUser();
        EventRegistration::factory()->confirmed()->create([
            'event_id' => $event->id,
            'user_id' => $registrant->id,
        ]);

        Livewire::actingAs($organizer)
            ->test(ManageEvent::class, ['slug' => $event->slug])
            ->set('status', 'cancelled')
            ->call('save');

        // Exactly ONE job for the registrant — the cancel from the unified
        // lifecycle service; the edit fan-out must not stack an upsert on a
        // cancellation (the queued upsert would resurrect the cancel).
        Queue::assertPushed(SyncCalendarEventForUser::class, fn (SyncCalendarEventForUser $job) => $job->userId === $registrant->id
            && $job->entityUuid === $event->id
            && $job->operation === CalendarSyncOperation::Cancel);

        $jobs = Queue::pushedJobs()[SyncCalendarEventForUser::class] ?? [];

        expect(count($jobs))->toBe(1);
    });
});

// ── Cancellation triggers ──────────────────────────────

describe('cancellation triggers', function () {
    it('cancelling a game through the real flow queues cancels for the connected roster', function () {
        $owner = calendarTriggerUser();
        $game = calendarTriggerGame($owner);
        $player = calendarTriggerUser();

        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'status' => 'approved',
        ]);

        Livewire::actingAs($owner)
            ->test(GamesPage::class)
            ->call('cancelGame', $game->id);

        $cancelled = [];
        Queue::assertPushed(SyncCalendarEventForUser::class, function (SyncCalendarEventForUser $job) use (&$cancelled): bool {
            if ($job->operation === CalendarSyncOperation::Cancel) {
                $cancelled[] = $job->userId;
            }

            return true;
        });

        expect($cancelled)->toContain($owner->id)
            ->and($cancelled)->toContain($player->id)
            ->and(count($cancelled))->toBe(2);
    });

    it('cancelling a campaign queues no calendar jobs — the feed keeps publishing approved members\' sessions', function () {
        $gm = calendarTriggerUser();
        $campaign = Campaign::factory()->create(['owner_id' => $gm->id]);
        calendarTriggerGame($gm, ['campaign_id' => $campaign->id]);

        Livewire::actingAs($gm)
            ->test(CampaignsPage::class)
            ->call('cancelCampaign', $campaign->id);

        calendarTriggerAssertNoCalendarJob();
    });

    it('cancelling an event queues cancels for confirmed registrants with connections', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'slug' => 'cal-sync-event-'.Str::random(8),
            'status' => 'published',
        ]);

        $registrant = calendarTriggerUser();
        EventRegistration::factory()->confirmed()->create([
            'event_id' => $event->id,
            'user_id' => $registrant->id,
        ]);

        app(EventLifecycleService::class)->cancel($event);

        Queue::assertPushed(SyncCalendarEventForUser::class, fn (SyncCalendarEventForUser $job) => $job->userId === $registrant->id
            && $job->entityUuid === $event->id
            && $job->entityType === 'event'
            && $job->operation === CalendarSyncOperation::Cancel);
    });
});

// ── Event registration trigger ─────────────────────────

describe('event registration trigger', function () {
    it('registering for a free event through the real flow queues an upsert for the registrant', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'slug' => 'cal-sync-reg-'.Str::random(8),
            'individual_registration_fee' => 0,
        ]);

        $registrant = calendarTriggerUser();

        Livewire::actingAs($registrant)
            ->test(RegisterForEvent::class, ['slug' => $event->slug])
            ->set('notes', 'Excited!')
            ->call('register');

        calendarTriggerAssertUpsert($registrant, $event);
    });

    it('a paid registration stays pending and dispatches nothing — the webhook confirmation is sweep-healed', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'slug' => 'cal-sync-paid-'.Str::random(8),
            'individual_registration_fee' => 25,
        ]);

        $registrant = calendarTriggerUser();

        Livewire::actingAs($registrant)
            ->test(RegisterForEvent::class, ['slug' => $event->slug])
            ->set('notes', '')
            ->call('register');

        calendarTriggerAssertNoCalendarJob();
    });
});

describe('organizer-side registration management triggers', function () {
    it('approving a pending registration queues an upsert for the registrant — same instant mirror as the free path', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'slug' => 'cal-sync-mreg-'.Str::random(8),
        ]);

        $registrant = calendarTriggerUser();

        $registration = EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $registrant->id,
        ]);

        Livewire::actingAs($organizer)
            ->test(ManageRegistrations::class, ['slug' => $event->slug])
            ->call('approve', $registration->id);

        calendarTriggerAssertUpsert($registrant, $event);
    });

    it('confirming a payment on a pending registration queues an upsert — the manual twin of the webhook path', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'slug' => 'cal-sync-mpay-'.Str::random(8),
            'individual_registration_fee' => 25,
        ]);

        $registrant = calendarTriggerUser();

        $registration = EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $registrant->id,
        ]);

        Livewire::actingAs($organizer)
            ->test(ManageRegistrations::class, ['slug' => $event->slug])
            ->call('confirmPayment', $registration->id);

        calendarTriggerAssertUpsert($registrant, $event);
    });

    it('cancelling a confirmed registration queues a delete — the ghost must not linger a reconcile cadence', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'slug' => 'cal-sync-mcancel-'.Str::random(8),
        ]);

        $registrant = calendarTriggerUser();

        $registration = EventRegistration::factory()->confirmed()->create([
            'event_id' => $event->id,
            'user_id' => $registrant->id,
        ]);

        Livewire::actingAs($organizer)
            ->test(ManageRegistrations::class, ['slug' => $event->slug])
            ->call('cancelRegistration', $registration->id);

        Queue::assertPushed(SyncCalendarEventForUser::class, fn (SyncCalendarEventForUser $job) => $job->userId === $registrant->id
            && $job->entityUuid === $event->id
            && $job->entityType === 'event'
            && $job->operation === CalendarSyncOperation::Delete);
    });

    it('rejecting a pending registration dispatches nothing — the commitment never existed', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'slug' => 'cal-sync-mrej-'.Str::random(8),
        ]);

        $registrant = calendarTriggerUser();

        $registration = EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $registrant->id,
        ]);

        Livewire::actingAs($organizer)
            ->test(ManageRegistrations::class, ['slug' => $event->slug])
            ->call('reject', $registration->id);

        calendarTriggerAssertNoCalendarJob();
    });

    it('rejecting a confirmed registration queues a delete — findRegistration does not filter by status', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'slug' => 'cal-sync-mrejc-'.Str::random(8),
        ]);

        $registrant = calendarTriggerUser();

        $registration = EventRegistration::factory()->confirmed()->create([
            'event_id' => $event->id,
            'user_id' => $registrant->id,
        ]);

        Livewire::actingAs($organizer)
            ->test(ManageRegistrations::class, ['slug' => $event->slug])
            ->call('reject', $registration->id);

        Queue::assertPushed(SyncCalendarEventForUser::class, fn (SyncCalendarEventForUser $job) => $job->userId === $registrant->id
            && $job->entityUuid === $event->id
            && $job->entityType === 'event'
            && $job->operation === CalendarSyncOperation::Delete);
    });
});

describe('feed-window gate on upserts', function () {
    it('joining a game beyond the feed window queues nothing — the mirror never mints what the feed does not publish', function () {
        $owner = User::factory()->create();
        $game = calendarTriggerGame($owner, ['date_time' => now()->addMonths(6)]);
        $player = calendarTriggerUser();

        Livewire::actingAs($player)
            ->test(ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Planning ahead!')
            ->call('submitApplication');

        expect(GameParticipant::where('game_id', $game->id)->where('user_id', $player->id)->first()?->status?->value)
            ->toBe('approved');

        calendarTriggerAssertNoCalendarJob();
    });

    it('registering for an event beyond the feed window queues nothing', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'slug' => 'cal-sync-far-'.Str::random(8),
            'individual_registration_fee' => 0,
            'start_date' => now()->addMonths(6)->toDateString(),
            'end_date' => now()->addMonths(6)->toDateString(),
        ]);

        $registrant = calendarTriggerUser();

        Livewire::actingAs($registrant)
            ->test(RegisterForEvent::class, ['slug' => $event->slug])
            ->set('notes', '')
            ->call('register');

        calendarTriggerAssertNoCalendarJob();
    });

    it('cancellation of a far-future event still fans out — the cleanup path 404-tolerates never-minted events', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'slug' => 'cal-sync-farc-'.Str::random(8),
            'start_date' => now()->addMonths(6)->toDateString(),
            'end_date' => now()->addMonths(6)->toDateString(),
        ]);

        $registrant = calendarTriggerUser();

        EventRegistration::factory()->confirmed()->create([
            'event_id' => $event->id,
            'user_id' => $registrant->id,
        ]);

        app(EventLifecycleService::class)->cancel($event);

        Queue::assertPushed(SyncCalendarEventForUser::class, fn (SyncCalendarEventForUser $job) => $job->userId === $registrant->id
            && $job->entityUuid === $event->id
            && $job->operation === CalendarSyncOperation::Cancel);
    });
});

// ── Job convergence rules ──────────────────────────────

describe('job execution', function () {
    it('upserts through the sync service and stamps last_synced_at', function () {
        Http::fake(['googleapis.com/*' => Http::response(['id' => 'synced'], 200)]);

        $owner = User::factory()->create();
        $game = calendarTriggerGame($owner);
        $connection = GoogleCalendarConnection::factory()->create([
            'user_id' => $owner->id,
            'token' => 'trigger-access-token',
            'source_toggles' => ['games' => true, 'campaign_sessions' => true, 'events' => true],
        ]);

        (new SyncCalendarEventForUser($owner->id, 'game', $game->id, CalendarSyncOperation::Upsert))
            ->handle(app(GoogleCalendarSyncService::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'googleapis.com')
            && str_contains($request->url(), 'sendUpdates=none'));
        expect($connection->fresh()?->last_synced_at)->not->toBeNull();
    });

    it('skips without HTTP when the connection was revoked between dispatch and handle', function () {
        $owner = User::factory()->create();
        $game = calendarTriggerGame($owner);
        GoogleCalendarConnection::factory()->revoked()->create(['user_id' => $owner->id]);

        (new SyncCalendarEventForUser($owner->id, 'game', $game->id, CalendarSyncOperation::Upsert))
            ->handle(app(GoogleCalendarSyncService::class));

        Http::assertNothingSent();
    });

    it('treats a missing entity as a logged no-op for every operation', function () {
        $owner = User::factory()->create();
        GoogleCalendarConnection::factory()->create(['user_id' => $owner->id]);

        (new SyncCalendarEventForUser($owner->id, 'game', (string) Str::uuid(), CalendarSyncOperation::Delete))
            ->handle(app(GoogleCalendarSyncService::class));

        Http::assertNothingSent();
    });

    it('tolerates a 404 on a delete — the goal state already holds for events that were never on the calendar', function () {
        Http::fake(['googleapis.com/*' => Http::response(['error' => ['code' => 404]], 404)]);

        $owner = User::factory()->create();
        $game = calendarTriggerGame($owner);
        GoogleCalendarConnection::factory()->create(['user_id' => $owner->id]);

        (new SyncCalendarEventForUser($owner->id, 'game', $game->id, CalendarSyncOperation::Delete))
            ->handle(app(GoogleCalendarSyncService::class));

        Http::assertSent(fn ($request) => $request->method() === 'DELETE');
    });

    it('propagates revocation as a terminal skip instead of a retry storm', function () {
        Http::fake(['googleapis.com/*' => Http::response(['error' => ['code' => 401]], 401)]);

        $owner = User::factory()->create();
        $game = calendarTriggerGame($owner);
        $connection = GoogleCalendarConnection::factory()->create(['user_id' => $owner->id]);

        (new SyncCalendarEventForUser($owner->id, 'game', $game->id, CalendarSyncOperation::Upsert))
            ->handle(app(GoogleCalendarSyncService::class));

        expect($connection->fresh()?->status)->toBe(GoogleCalendarConnection::STATUS_REVOKED);
    });
});

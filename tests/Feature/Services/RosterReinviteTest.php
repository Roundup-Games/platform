<?php

namespace Tests\Feature\Services;

use App\Dto\RosterReinviteResult;
use App\Enums\GameStatus;
use App\Enums\GameType;
use App\Enums\JoinSource;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Notifications\RosterReinviteInvitation;
use App\Services\OwnerParticipantService;
use App\Services\ParticipantLifecycle;
use App\Services\ParticipantService;
use App\Services\PostHogAnalytics;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Mockery;
use Tests\Traits\CapturesLogRecords;

// M084/S06-T03 — roster re-invite (D320, D321).
//
// A game that replaces a cancelled one never carries players over: the host
// clicks, and every approved former player is invited and decides again.
// The link is the authority, seats cap the batch, and every outcome is counted.

uses(DatabaseTransactions::class, CapturesLogRecords::class);

// K014: the clock is frozen — approved_at stamps are what the seat order depends on.
beforeEach(function () {
    $this->travelTo(now());
});

afterEach(function () {
    $this->travelBack();
});

// ── Helpers ──────────────────────────────────────────────

function rosterReinviteHost(): User
{
    return User::factory()->create(['profile_complete' => true]);
}

/**
 * A one-shot and the Scheduled game that replaces it, linked the way the
 * recreate flow leaves them: same host, identical Tier-1 details, and the host
 * seated on the replacement from the start, exactly as SessionCreationService does.
 *
 * @param  array<string, mixed>  $predecessor
 * @param  array<string, mixed>  $replacement
 * @param  list<string>  $predecessorSystems  Offered systems of the cancelled game (ids).
 * @param  list<string>  $replacementSystems  Offered systems of the replacement (ids); defaults to the predecessor's.
 * @return array{predecessor: Game, replacement: Game}
 */
function rosterReinviteLinked(
    User $host,
    array $predecessor = [],
    array $replacement = [],
    array $predecessorSystems = [],
    array $replacementSystems = [],
): array {
    $before = $predecessorSystems;
    $after = $replacementSystems;

    if ($before === [] || $after === []) {
        $shared = GameSystem::factory()->create()->id;
        $before = $before ?: [$shared];
        $after = $after ?: [$before[0]];
    }

    $details = [
        'owner_id' => $host->id,
        'date_time' => now()->addDays(7),
        'expected_duration' => 3,
        'price' => 0,
        'min_players' => 2,
        'max_players' => 10,
        'language' => 'en',
    ];

    $old = Game::factory()->withGameSystems($before)->create(array_merge($details, [
        'name' => ['en' => 'Cancelled session'],
    ], $predecessor));

    $new = Game::factory()->withGameSystems($after)->create(array_merge($details, [
        'name' => ['en' => 'Replacement session'],
        'recreates_game_id' => $old->id,
    ], $replacement));

    app(OwnerParticipantService::class)->ensureOwnerParticipant($new);

    return ['predecessor' => $old, 'replacement' => $new];
}

/**
 * Seat a player the way a real invitation does: a Pending/Invited row the
 * player accepts through ParticipantLifecycle, so it carries a genuine approved_at.
 */
function rosterReinviteApprove(Game $game, User $player): void
{
    $pending = GameParticipant::factory()->create([
        'game_id' => $game->id,
        'user_id' => $player->id,
        'role' => ParticipantRole::Invited->value,
        'status' => ParticipantStatus::Pending->value,
        'join_source' => JoinSource::FriendInvite,
    ]);

    $result = app(ParticipantLifecycle::class)->acceptInvitation($pending, $game, $player);

    expect($result->success)->toBeTrue();
}

/**
 * Cancel the predecessor the way a host cancellation leaves its roster:
 * approved players stay approved — cancelling does not delete the roster.
 */
function rosterReinviteCancel(Game $predecessor): void
{
    $predecessor->update(['status' => GameStatus::Canceled->value]);
}

/**
 * The cancelled one-shot plus its replacement, with $approvedPlayers former
 * players seated on it.
 *
 * @param  array<string, mixed>  $predecessor
 * @param  array<string, mixed>  $replacement
 * @return array{predecessor: Game, replacement: Game, players: Collection<int, User>}
 */
function rosterReinvitePair(User $host, int $approvedPlayers = 3, array $predecessor = [], array $replacement = []): array
{
    ['predecessor' => $old, 'replacement' => $new] = rosterReinviteLinked($host, $predecessor, $replacement);

    $players = User::factory()->count($approvedPlayers)->create();

    foreach ($players as $player) {
        rosterReinviteApprove($old, $player);
    }

    rosterReinviteCancel($old);

    return ['predecessor' => $old, 'replacement' => $new, 'players' => $players];
}

/** Every roster-re-invite row on a game. */
function rosterReinviteRows(Game $game): Collection
{
    return GameParticipant::query()
        ->where('game_id', $game->id)
        ->where('join_source', JoinSource::RosterReinvite->value)
        ->get();
}

function rosterReinvite(Game $replacement, User $inviter): RosterReinviteResult
{
    return app(ParticipantService::class)->inviteFormerRoster($replacement, $inviter);
}

// ═══════════════════════════════════════════════════════════
// inviteFormerRoster — WHO is invited
// ═══════════════════════════════════════════════════════════

describe('inviteFormerRoster', function () {
    it('invites every approved former player with the diff-stating invitation, and never the host', function () {
        Notification::fake();
        $host = rosterReinviteHost();
        ['predecessor' => $old, 'replacement' => $new, 'players' => $players] = rosterReinvitePair($host);

        // Former players are not mutual followers of the host: the friendship
        // gate of inviteFriends() (R017) deliberately does not apply here.
        expect($host->isFriend($players->first()))->toBeFalse();

        $result = rosterReinvite($new, $host);

        expect($result->invited)->toBe(3)
            ->and($result->skippedCapacity)->toBe(0)
            ->and($result->skippedAlready)->toBe(0)
            ->and($result->skippedMissing)->toBe(0)
            ->and($result->changedFields)->toBe([]);

        $rows = rosterReinviteRows($new);

        expect($rows)->toHaveCount(3)
            ->and($rows->pluck('user_id')->sort()->values()->all())->toBe($players->pluck('id')->sort()->values()->all());

        foreach ($rows as $row) {
            expect($row->role)->toBe(ParticipantRole::Invited)
                ->and($row->status)->toBe(ParticipantStatus::Pending);
        }

        foreach ($players as $player) {
            Notification::assertSentTo($player, RosterReinviteInvitation::class, function (RosterReinviteInvitation $notification) use ($new, $host): bool {
                return $notification->game->is($new)
                    && $notification->inviter->is($host)
                    && $notification->replacedGameName === 'Cancelled session'
                    && $notification->changedFields === [];
            });
        }

        Notification::assertNotSentTo($host, RosterReinviteInvitation::class);
    });

    it('never invites a player the host removed before the cancel', function () {
        Notification::fake();
        $host = rosterReinviteHost();
        ['predecessor' => $old, 'replacement' => $new] = rosterReinviteLinked($host);
        $kept = User::factory()->create();
        $removed = User::factory()->create();

        rosterReinviteApprove($old, $kept);
        rosterReinviteApprove($old, $removed);

        $removal = app(ParticipantLifecycle::class)->removeParticipant(
            GameParticipant::query()->where('game_id', $old->id)->where('user_id', $removed->id)->sole(),
            $old,
            $host,
        );
        expect($removal->success)->toBeTrue();

        rosterReinviteCancel($old);

        $result = rosterReinvite($new, $host);

        expect($result->invited)->toBe(1)
            ->and($result->skipped())->toBe(0)
            ->and(rosterReinviteRows($new)->pluck('user_id')->all())->toBe([$kept->id]);

        Notification::assertSentTo($kept, RosterReinviteInvitation::class);
        Notification::assertNotSentTo($removed, RosterReinviteInvitation::class);
    });

    it('ignores former participants who were never approved', function (ParticipantStatus $status) {
        Notification::fake();
        $host = rosterReinviteHost();
        ['predecessor' => $old, 'replacement' => $new] = rosterReinviteLinked($host);
        $approved = User::factory()->create();
        $neverApproved = User::factory()->create();

        rosterReinviteApprove($old, $approved);

        GameParticipant::factory()->create([
            'game_id' => $old->id,
            'user_id' => $neverApproved->id,
            'role' => ParticipantRole::Player->value,
            'status' => $status->value,
        ]);

        rosterReinviteCancel($old);

        $result = rosterReinvite($new, $host);

        expect($result->invited)->toBe(1)
            ->and($result->skipped())->toBe(0)
            ->and(rosterReinviteRows($new)->pluck('user_id')->all())->toBe([$approved->id]);
    })->with([
        'rejected' => ParticipantStatus::Rejected,
        'waitlisted' => ParticipantStatus::Waitlisted,
        'benched' => ParticipantStatus::Benched,
        'pending' => ParticipantStatus::Pending,
    ]);

    it('skips a former player who is already part of the replacement, without a duplicate', function () {
        Notification::fake();
        $host = rosterReinviteHost();
        ['replacement' => $new, 'players' => $players] = rosterReinvitePair($host);

        // The player found the replacement on their own and applied before the host clicked.
        GameParticipant::factory()->create([
            'game_id' => $new->id,
            'user_id' => $players[0]->id,
            'role' => ParticipantRole::Applicant->value,
            'status' => ParticipantStatus::Pending->value,
            'join_source' => JoinSource::Application,
        ]);

        $result = rosterReinvite($new, $host);

        expect($result->invited)->toBe(2)
            ->and($result->skippedAlready)->toBe(1)
            ->and($result->skippedCapacity)->toBe(0)
            ->and(GameParticipant::query()->where('game_id', $new->id)->where('user_id', $players[0]->id)->count())->toBe(1);

        Notification::assertNotSentTo($players[0], RosterReinviteInvitation::class);
    });
});

// ═══════════════════════════════════════════════════════════
// inviteFormerRoster — CAPACITY
// ═══════════════════════════════════════════════════════════

describe('inviteFormerRoster capacity', function () {
    it('stops at the free seats and reports who was left out', function () {
        Notification::fake();
        $host = rosterReinviteHost();
        // The host holds one of three seats, so two are free.
        ['replacement' => $new, 'players' => $players] = rosterReinvitePair($host, replacement: ['max_players' => 3]);

        $result = rosterReinvite($new, $host);

        expect($result->invited)->toBe(2)
            ->and($result->skippedCapacity)->toBe(1)
            ->and($result->skipped())->toBe(1);

        // Exactly the two invited players hear from the host; the one left out does not.
        $invitedIds = rosterReinviteRows($new)->pluck('user_id')->all();

        expect($invitedIds)->toHaveCount(2);

        foreach ($players as $player) {
            if (in_array($player->id, $invitedIds, true)) {
                Notification::assertSentTo($player, RosterReinviteInvitation::class);
            } else {
                Notification::assertNotSentTo($player, RosterReinviteInvitation::class);
            }
        }
    });

    it('treats a max_players of 0 as unlimited', function () {
        Notification::fake();
        $host = rosterReinviteHost();
        ['replacement' => $new] = rosterReinvitePair($host, replacement: ['max_players' => 0]);

        $result = rosterReinvite($new, $host);

        expect($result->invited)->toBe(3)
            ->and($result->skippedCapacity)->toBe(0);
    });

    it('gives the free seats to the earliest approvals first', function () {
        Notification::fake();
        $host = rosterReinviteHost();
        ['predecessor' => $old, 'replacement' => $new] = rosterReinviteLinked($host, [], ['max_players' => 2]);

        [$laterApproved, $firstApproved] = User::factory()->count(2)->create()->all();

        rosterReinviteApprove($old, $firstApproved);
        $this->travel(5)->minutes();
        rosterReinviteApprove($old, $laterApproved);

        rosterReinviteCancel($old);

        $result = rosterReinvite($new, $host);

        expect($result->invited)->toBe(1)->and($result->skippedCapacity)->toBe(1);

        Notification::assertSentTo($firstApproved, RosterReinviteInvitation::class);
        Notification::assertNotSentTo($laterApproved, RosterReinviteInvitation::class);
    });
});

// ═══════════════════════════════════════════════════════════
// inviteFormerRoster — AUTHORITY
// ═══════════════════════════════════════════════════════════

describe('inviteFormerRoster authority', function () {
    it('throws a LogicException when the game replaces nothing', function () {
        $host = rosterReinviteHost();
        $unlinked = Game::factory()->create(['owner_id' => $host->id]);

        expect(fn () => rosterReinvite($unlinked, $host))->toThrow(LogicException::class);
    });

    it('throws a LogicException when the replacement is no longer Scheduled', function (GameStatus $status) {
        $host = rosterReinviteHost();
        ['replacement' => $new] = rosterReinvitePair($host, replacement: ['status' => $status->value]);

        expect(fn () => rosterReinvite($new, $host))->toThrow(LogicException::class)
            ->and(rosterReinviteRows($new))->toHaveCount(0);
    })->with([
        'cancelled' => GameStatus::Canceled,
        'completed' => GameStatus::Completed,
    ]);

    it('refuses a stranger', function () {
        $host = rosterReinviteHost();
        ['replacement' => $new] = rosterReinvitePair($host);

        expect(fn () => rosterReinvite($new, User::factory()->create()))->toThrow(AuthorizationException::class);
    });

    it('refuses an inviter who may update the game but does not host the cancelled one', function () {
        $host = rosterReinviteHost();
        ['replacement' => $new] = rosterReinvitePair($host);
        $admin = gameTestCreateUserWithPermission('update game');

        expect($admin->can('update', $new))->toBeTrue();

        expect(fn () => rosterReinvite($new, $admin))->toThrow(AuthorizationException::class)
            ->and(rosterReinviteRows($new))->toHaveCount(0);
    });

    it('refuses while the predecessor is not cancelled', function () {
        $host = rosterReinviteHost();
        ['replacement' => $new] = rosterReinviteLinked($host);

        expect(fn () => rosterReinvite($new, $host))->toThrow(AuthorizationException::class);
    });
});

// ═══════════════════════════════════════════════════════════
// inviteFormerRoster — WHAT CHANGED
// ═══════════════════════════════════════════════════════════

describe('inviteFormerRoster changed fields', function () {
    it('states only the Tier-1 fields whose value really changed', function () {
        Notification::fake();
        $host = rosterReinviteHost();
        // Same game system, duration, price, minimum, bench mode and language. The
        // factory randomises description and location (Tier 2 and 3): not part of the agreement.
        ['replacement' => $new, 'players' => $players] = rosterReinvitePair($host, approvedPlayers: 1, replacement: [
            'date_time' => now()->addDays(9),
            'max_players' => 5,
        ]);

        $result = rosterReinvite($new, $host);

        expect($result->changedFields)->toBe(['date_time', 'max_players']);

        Notification::assertSentTo($players->first(), RosterReinviteInvitation::class,
            fn (RosterReinviteInvitation $notification): bool => $notification->changedFields === ['date_time', 'max_players']);
    });

    it('names the game system when the replacement offers another one', function () {
        $host = rosterReinviteHost();
        ['predecessor' => $old, 'replacement' => $new] = rosterReinviteLinked($host, replacementSystems: [GameSystem::factory()->create()->id]);
        $player = User::factory()->create();

        rosterReinviteApprove($old, $player);
        rosterReinviteCancel($old);

        expect(rosterReinvite($new, $host)->changedFields)->toBe(['game_system_id']);
    });

    it('names the system set when only a Gathering’s extra systems change', function () {
        $host = rosterReinviteHost();
        // gameSystems() orders by slug, so the first system is the representative one in both games.
        $systems = GameSystem::factory()->count(3)->create()->sortBy('slug')->values();
        ['predecessor' => $old, 'replacement' => $new] = rosterReinviteLinked(
            $host,
            ['game_type' => GameType::Gathering->value],
            ['game_type' => GameType::Gathering->value],
            [$systems[0]->id, $systems[1]->id],
            [$systems[0]->id, $systems[2]->id],
        );
        $player = User::factory()->create();

        rosterReinviteApprove($old, $player);
        rosterReinviteCancel($old);

        expect(rosterReinvite($new, $host)->changedFields)->toBe(['game_systems']);
    });

    it('does not state two spellings of one value', function () {
        $host = rosterReinviteHost();
        // An unset price and a price of 0 are both free.
        ['replacement' => $new] = rosterReinvitePair($host, approvedPlayers: 1, predecessor: ['price' => null]);

        expect(rosterReinvite($new, $host)->changedFields)->toBe([]);
    });
});

// ═══════════════════════════════════════════════════════════
// inviteFormerRoster — RACES, OBSERVABILITY, ACCEPTANCE
// ═══════════════════════════════════════════════════════════

describe('inviteFormerRoster races and side effects', function () {
    it('absorbs an invitation that won the unique-constraint race', function () {
        Notification::fake();
        $host = rosterReinviteHost();
        ['replacement' => $new, 'players' => $players] = rosterReinvitePair($host, approvedPlayers: 1);
        $player = $players->first();

        // An ordinary invite lands between the existence check and the insert. The
        // listener only ever fires for a re-invite row, so it is inert for the rest of the
        // process. It runs inside the insert's savepoint, so the rival row is rolled back
        // together with the failed insert — in production it is another request's committed row.
        Event::listen('eloquent.creating: '.GameParticipant::class, function (GameParticipant $beingCreated) use ($new, $player): void {
            if ($beingCreated->join_source !== JoinSource::RosterReinvite) {
                return;
            }

            GameParticipant::factory()->create([
                'game_id' => $new->id,
                'user_id' => $player->id,
                'role' => ParticipantRole::Invited->value,
                'status' => ParticipantStatus::Pending->value,
                'join_source' => JoinSource::FriendInvite,
            ]);
        });

        $result = rosterReinvite($new, $host);

        // Absorbed, not raised, and never counted as invited.
        expect($result->invited)->toBe(0)
            ->and($result->skippedAlready)->toBe(1)
            ->and(rosterReinviteRows($new))->toHaveCount(0);

        Notification::assertNothingSentTo($player, RosterReinviteInvitation::class);
    });

    it('lets any other database failure surface instead of counting it as a skip', function () {
        $host = rosterReinviteHost();
        ['replacement' => $new] = rosterReinvitePair($host, approvedPlayers: 1);

        // The account vanishes between the roster read and the insert: a foreign-key failure.
        Event::listen('eloquent.creating: '.GameParticipant::class, function (GameParticipant $beingCreated): void {
            if ($beingCreated->join_source === JoinSource::RosterReinvite) {
                $beingCreated->user_id = '00000000-0000-0000-0000-000000000000';
            }
        });

        expect(fn () => rosterReinvite($new, $host))->toThrow(QueryException::class);

        expect(rosterReinviteRows($new))->toHaveCount(0);
    });

    it('logs one structured line and reports the counts to analytics', function () {
        Notification::fake();
        $host = rosterReinviteHost();
        ['predecessor' => $old, 'replacement' => $new] = rosterReinvitePair($host, replacement: ['max_players' => 3]);

        $analytics = Mockery::mock(PostHogAnalytics::class);
        $analytics->shouldReceive('capture')->once()->with(
            Mockery::on(fn (User $user): bool => $user->is($host)),
            'game.roster_reinvited',
            ['game_id' => $new->id, 'invited' => 2, 'skipped_capacity' => 1],
        );
        $analytics->shouldIgnoreMissing();
        app()->instance(PostHogAnalytics::class, $analytics);

        $this->captureLogRecords();

        rosterReinvite($new, $host);

        expect($this->logRecord('reinvite.roster'))->toBe([
            'message' => 'reinvite.roster',
            'level_name' => 'INFO',
            'context' => [
                'game_id' => $new->id,
                'predecessor_id' => $old->id,
                'invited' => 2,
                'skipped_capacity' => 1,
                'skipped_already' => 0,
                'skipped_missing' => 0,
                'changed_fields' => ['max_players'],
            ],
        ]);
    });

    it('clears the re-invitation when the player accepts it', function () {
        $host = rosterReinviteHost();
        ['replacement' => $new, 'players' => $players] = rosterReinvitePair($host, approvedPlayers: 1);
        $player = $players->first();

        rosterReinvite($new, $host);

        expect($player->fresh()->unreadNotifications()->where('type', RosterReinviteInvitation::class)->count())->toBe(1);

        $acceptance = app(ParticipantLifecycle::class)->acceptInvitation(
            GameParticipant::query()->where('game_id', $new->id)->where('user_id', $player->id)->sole(),
            $new,
            $player,
        );

        expect($acceptance->success)->toBeTrue()
            ->and($player->fresh()->unreadNotifications()->where('type', RosterReinviteInvitation::class)->count())->toBe(0);
    });
});

// ═══════════════════════════════════════════════════════════
// countReinvitableFormerRoster — what the banner offers
// ═══════════════════════════════════════════════════════════

describe('countReinvitableFormerRoster', function () {
    it('counts only players not yet part of the replacement, capped by the free seats', function () {
        $host = rosterReinviteHost();
        ['replacement' => $new, 'players' => $players] = rosterReinvitePair($host);
        $service = app(ParticipantService::class);

        expect($service->countReinvitableFormerRoster($new))->toBe(3);

        // A player who applied on their own is no longer someone the banner can offer.
        GameParticipant::factory()->create([
            'game_id' => $new->id,
            'user_id' => $players[0]->id,
            'role' => ParticipantRole::Applicant->value,
            'status' => ParticipantStatus::Pending->value,
            'join_source' => JoinSource::Application,
        ]);

        expect($service->countReinvitableFormerRoster($new))->toBe(2);

        // Two free seats: exactly two can be invited right now.
        $new->update(['max_players' => 3]);

        expect($service->countReinvitableFormerRoster($new))->toBe(2);

        // Full: nothing to offer.
        $new->update(['max_players' => 1]);

        expect($service->countReinvitableFormerRoster($new))->toBe(0);
    });

    it('counts nothing for a game that replaces no cancelled game', function () {
        $host = rosterReinviteHost();
        ['replacement' => $running] = rosterReinviteLinked($host);
        $unlinked = Game::factory()->create(['owner_id' => $host->id]);

        expect(app(ParticipantService::class)->countReinvitableFormerRoster($running))->toBe(0)
            ->and(app(ParticipantService::class)->countReinvitableFormerRoster($unlinked))->toBe(0);
    });
});

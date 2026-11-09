<?php

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\EventDelegationService;
use Illuminate\Support\Facades\Gate;

/*
 * M077/S01 consolidation: 19 tests → 4 flow tests
 * - Public (2 → 1): guest, authenticated viewer.
 * - Protected (8 → 1): one shared game viewed by guest, owner, stranger,
 *   friend, one-way follower, teammate, former teammate, participant.
 * - Private (5 → 1): one shared game viewed by guest, owner, participant,
 *   friend, teammate.
 * - Event oversight (4 → 1): organizer, co-organizer, stranger, no-umbrella —
 *   per-case fixtures kept as-is inside the flow.
 * The shared beforeEach world is unchanged; every original assertion is
 * preserved verbatim inside its flow, with the original test name carried
 * as a comment per check. Guest checks run before any actingAs().
 */

beforeEach(function () {
    seedPermissions();
    seedRoles();

    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
});

// ═══════════════════════════════════════════════════════════
// PUBLIC VISIBILITY — VISIBLE TO EVERYONE
// ═══════════════════════════════════════════════════════════

describe('Public game visibility', function () {
    test('public visibility matrix (flow)', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
        ]);

        // is visible to guests
        expect(Gate::allows('view', $game))->toBeTrue();

        // is visible to any authenticated user
        $viewer = User::factory()->create();
        $this->actingAs($viewer);
        expect(Gate::allows('view', $game))->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════
// PROTECTED VISIBILITY — FRIENDS + TEAMMATES + PARTICIPANTS
// ═══════════════════════════════════════════════════════════

describe('Protected game visibility', function () {
    test('protected viewer matrix (flow)', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);

        // is not visible to guests
        expect(Gate::allows('view', $game))->toBeFalse();

        // is visible to the owner
        $this->actingAs($this->owner);
        expect(Gate::allows('view', $game))->toBeTrue();

        // is not visible to a stranger
        $stranger = User::factory()->create();
        $this->actingAs($stranger);
        expect(Gate::allows('view', $game))->toBeFalse();

        // is visible to a friend (mutual follow)
        $friend = User::factory()->create();
        UserRelationship::follow($this->owner, $friend);
        UserRelationship::follow($friend, $this->owner);
        $this->actingAs($friend);
        expect(Gate::allows('view', $game))->toBeTrue();

        // is not visible to a one-way follower
        // One-way follow: follower follows owner, but owner doesn't follow back
        $follower = User::factory()->create();
        UserRelationship::follow($follower, $this->owner);
        $this->actingAs($follower);
        expect(Gate::allows('view', $game))->toBeFalse();

        // is visible to a teammate on an active team
        $teammate = User::factory()->create();
        $team = Team::factory()->create();
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $this->owner->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $teammate->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $this->actingAs($teammate);
        expect(Gate::allows('view', $game))->toBeTrue();

        // is not visible to a former teammate (inactive membership)
        $formerTeammate = User::factory()->create();
        $team = Team::factory()->create();
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $this->owner->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $formerTeammate->id,
            'role' => 'player',
            'status' => 'inactive',
            'joined_at' => now(),
        ]);
        $this->actingAs($formerTeammate);
        expect(Gate::allows('view', $game))->toBeFalse();

        // is visible to a participant
        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        $this->actingAs($participant);
        expect(Gate::allows('view', $game))->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════
// PRIVATE VISIBILITY — OWNER + PARTICIPANTS ONLY
// ═══════════════════════════════════════════════════════════

describe('Private game visibility', function () {
    test('private viewer matrix (flow)', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
        ]);

        // is not visible to guests
        expect(Gate::allows('view', $game))->toBeFalse();

        // is visible to the owner
        $this->actingAs($this->owner);
        expect(Gate::allows('view', $game))->toBeTrue();

        // is visible to a participant
        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        $this->actingAs($participant);
        expect(Gate::allows('view', $game))->toBeTrue();

        // is not visible to a friend
        $friend = User::factory()->create();
        UserRelationship::follow($this->owner, $friend);
        UserRelationship::follow($friend, $this->owner);
        $this->actingAs($friend);
        expect(Gate::allows('view', $game))->toBeFalse();

        // is not visible to a teammate
        $teammate = User::factory()->create();
        $team = Team::factory()->create();
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $this->owner->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $teammate->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $this->actingAs($teammate);
        expect(Gate::allows('view', $game))->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════
// EVENT OVERSIGHT — MANAGERS OF THE UMBRELLA EVENT (M063 follow-up)
//
// The event page's tables map routes its join CTA to the game's detail
// page, so a non-public table 403'd the event's own organizer. Managers
// of the event may view every table hosted under it.
// ═══════════════════════════════════════════════════════════

describe('Event oversight visibility', function () {
    test('event oversight matrix (flow)', function () {
        // lets the event organizer view a protected table hosted at their event
        $organizer = User::factory()->create();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        $game = Game::factory()->event($event)->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);

        $this->actingAs($organizer);
        expect(Gate::allows('view', $game))->toBeTrue();

        // lets a co-organizer (event-scoped update permission) view the table
        $organizer = User::factory()->create();
        $coOrganizer = User::factory()->create();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        app(EventDelegationService::class)->grantCoOrganizer($event, $coOrganizer, $organizer);

        $game = Game::factory()->event($event)->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
        ]);

        $this->actingAs($coOrganizer);
        expect(Gate::allows('view', $game))->toBeTrue();

        // does not grant strangers access via the event attachment
        $organizer = User::factory()->create();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        $game = Game::factory()->event($event)->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger);
        expect(Gate::allows('view', $game))->toBeFalse();

        // does not affect tables without an umbrella event
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);
        $organizer = User::factory()->create();
        Event::factory()->create(['organizer_id' => $organizer->id]);

        $this->actingAs($organizer);
        expect(Gate::allows('view', $game))->toBeFalse();
    });
});

<?php

use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRelationship;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use Illuminate\Support\Facades\Gate;

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
    it('is visible to guests', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
        ]);

        expect(Gate::allows('view', $game))->toBeTrue();
    })->group('smoke');

    it('is visible to any authenticated user', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
        ]);
        $viewer = User::factory()->create();

        $this->actingAs($viewer);
        expect(Gate::allows('view', $game))->toBeTrue();
    })->group('smoke');

});

// ═══════════════════════════════════════════════════════════
// PROTECTED VISIBILITY — FRIENDS + TEAMMATES + PARTICIPANTS
// ═══════════════════════════════════════════════════════════

describe('Protected game visibility', function () {
    it('is not visible to guests', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);

        expect(Gate::allows('view', $game))->toBeFalse();
    })->group('smoke');

    it('is visible to the owner', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);

        $this->actingAs($this->owner);
        expect(Gate::allows('view', $game))->toBeTrue();
    })->group('smoke');

    it('is not visible to a stranger', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger);
        expect(Gate::allows('view', $game))->toBeFalse();
    })->group('smoke');

    it('is visible to a friend (mutual follow)', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);
        $friend = User::factory()->create();
        UserRelationship::follow($this->owner, $friend);
        UserRelationship::follow($friend, $this->owner);

        $this->actingAs($friend);
        expect(Gate::allows('view', $game))->toBeTrue();
    })->group('smoke');

    it('is not visible to a one-way follower', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);
        $follower = User::factory()->create();
        // One-way follow: follower follows owner, but owner doesn't follow back
        UserRelationship::follow($follower, $this->owner);

        $this->actingAs($follower);
        expect(Gate::allows('view', $game))->toBeFalse();
    });

    it('is visible to a teammate on an active team', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);
        $teammate = User::factory()->create();
        $team = Team::factory()->create();
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $this->owner->id,
            'role' => ParticipantRole::Player->value,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $teammate->id,
            'role' => ParticipantRole::Player->value,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($teammate);
        expect(Gate::allows('view', $game))->toBeTrue();
    })->group('smoke');

    it('is not visible to a former teammate (inactive membership)', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);
        $formerTeammate = User::factory()->create();
        $team = Team::factory()->create();
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $this->owner->id,
            'role' => ParticipantRole::Player->value,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $formerTeammate->id,
            'role' => ParticipantRole::Player->value,
            'status' => 'inactive',
            'joined_at' => now(),
        ]);

        $this->actingAs($formerTeammate);
        expect(Gate::allows('view', $game))->toBeFalse();
    });

    it('is visible to a participant', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);
        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $this->actingAs($participant);
        expect(Gate::allows('view', $game))->toBeTrue();
    })->group('smoke');
});

// ═══════════════════════════════════════════════════════════
// PRIVATE VISIBILITY — OWNER + PARTICIPANTS ONLY
// ═══════════════════════════════════════════════════════════

describe('Private game visibility', function () {
    it('is not visible to guests', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
        ]);

        expect(Gate::allows('view', $game))->toBeFalse();
    })->group('smoke');

    it('is visible to the owner', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
        ]);

        $this->actingAs($this->owner);
        expect(Gate::allows('view', $game))->toBeTrue();
    })->group('smoke');

    it('is visible to a participant', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
        ]);
        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $this->actingAs($participant);
        expect(Gate::allows('view', $game))->toBeTrue();
    })->group('smoke');

    it('is not visible to a friend', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
        ]);
        $friend = User::factory()->create();
        UserRelationship::follow($this->owner, $friend);
        UserRelationship::follow($friend, $this->owner);

        $this->actingAs($friend);
        expect(Gate::allows('view', $game))->toBeFalse();
    })->group('smoke');

    it('is not visible to a teammate', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
        ]);
        $teammate = User::factory()->create();
        $team = Team::factory()->create();
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $this->owner->id,
            'role' => ParticipantRole::Player->value,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $teammate->id,
            'role' => ParticipantRole::Player->value,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($teammate);
        expect(Gate::allows('view', $game))->toBeFalse();
    })->group('smoke');

});

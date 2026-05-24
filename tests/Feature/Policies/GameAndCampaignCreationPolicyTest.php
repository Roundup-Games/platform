<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    seedPermissions();
    seedRoles();

    $this->gameSystem = GameSystem::factory()->create();
});

// ═══════════════════════════════════════════════════════════
// GAME CREATION POLICY
// ═══════════════════════════════════════════════════════════

describe('GamePolicy — create', function () {
    it('allows user with complete profile to create a game', function () {
        $user = User::factory()->create(['profile_complete' => true]);

        $this->actingAs($user);
        expect(Gate::allows('create', Game::class))->toBeTrue();
    });

    it('denies user with incomplete profile from creating a game', function () {
        $user = User::factory()->create(['profile_complete' => false]);

        $this->actingAs($user);
        expect(Gate::allows('create', Game::class))->toBeFalse();
    });

    it('denies guest from creating a game', function () {
        expect(Gate::allows('create', Game::class))->toBeFalse();
    });

    it('allows Platform Admin to create a game regardless of profile status', function () {
        $user = User::factory()->create(['profile_complete' => false]);
        $user->assignRole('Platform Admin');

        $this->actingAs($user);
        expect(Gate::allows('create', Game::class))->toBeTrue();
    });

    it('allows Games Admin to create a game regardless of profile status', function () {
        $user = User::factory()->create(['profile_complete' => false]);
        $user->assignRole('Games Admin');

        $this->actingAs($user);
        expect(Gate::allows('create', Game::class))->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN CREATION POLICY
// ═══════════════════════════════════════════════════════════

describe('CampaignPolicy — create', function () {
    it('allows user with complete profile to create a campaign', function () {
        $user = User::factory()->create(['profile_complete' => true]);

        $this->actingAs($user);
        expect(Gate::allows('create', Campaign::class))->toBeTrue();
    });

    it('denies user with incomplete profile from creating a campaign', function () {
        $user = User::factory()->create(['profile_complete' => false]);

        $this->actingAs($user);
        expect(Gate::allows('create', Campaign::class))->toBeFalse();
    });

    it('denies guest from creating a campaign', function () {
        expect(Gate::allows('create', Campaign::class))->toBeFalse();
    });

    it('allows Platform Admin to create a campaign regardless of profile status', function () {
        $user = User::factory()->create(['profile_complete' => false]);
        $user->assignRole('Platform Admin');

        $this->actingAs($user);
        expect(Gate::allows('create', Campaign::class))->toBeTrue();
    });

    it('allows Games Admin to create a campaign regardless of profile status', function () {
        $user = User::factory()->create(['profile_complete' => false]);
        $user->assignRole('Games Admin');

        $this->actingAs($user);
        expect(Gate::allows('create', Campaign::class))->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════
// GAME UPDATE/DELETE STILL REQUIRE OWNERSHIP OR PERMISSION
// ═══════════════════════════════════════════════════════════

describe('GamePolicy — update and delete still gated', function () {
    it('allows owner to update their own game', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $this->actingAs($owner);
        expect(Gate::allows('update', $game))->toBeTrue();
        expect(Gate::allows('delete', $game))->toBeTrue();
    });

    it('denies non-owner from updating a game without permission', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $stranger = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $this->actingAs($stranger);
        expect(Gate::allows('update', $game))->toBeFalse();
        expect(Gate::allows('delete', $game))->toBeFalse();
    });

    it('allows Games Admin to update any game', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $admin = User::factory()->create(['profile_complete' => true]);
        $admin->assignRole('Games Admin');
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $this->actingAs($admin);
        expect(Gate::allows('update', $game))->toBeTrue();
        expect(Gate::allows('delete', $game))->toBeTrue();
    });
});

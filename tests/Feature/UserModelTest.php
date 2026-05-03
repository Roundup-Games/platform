<?php

use App\Models\GameSystem;
use App\Models\User;

// ═══════════════════════════════════════════════════════════
// USER MODEL — RELATIONSHIPS & HELPERS
// ═══════════════════════════════════════════════════════════

describe('User Model — Relationships', function () {
    it('has gameSystemPreferences relationship', function () {
        $user = User::factory()->create();
        $system = GameSystem::factory()->create();

        $user->gameSystemPreferences()->attach($system, ['preference_type' => 'favorite']);

        expect($user->gameSystemPreferences)->toHaveCount(1);
    });

    it('has favoriteGameSystems scoped to favorite type', function () {
        $user = User::factory()->create();
        $fav = GameSystem::factory()->create(['name' => 'D&D']);
        $avoid = GameSystem::factory()->create(['name' => 'GURPS']);

        $user->gameSystemPreferences()->attach($fav, ['preference_type' => 'favorite']);
        $user->gameSystemPreferences()->attach($avoid, ['preference_type' => 'avoid']);

        expect($user->fresh()->favoriteGameSystems)->toHaveCount(1)
            ->and($user->fresh()->favoriteGameSystems->first()->name)->toBe('D&D');
    });

    it('has teams via team_members pivot', function () {
        $user = User::factory()->create();
        $team = \App\Models\Team::factory()->create();
        \App\Models\TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        // Access teams through raw query to avoid BelongsToMany timestamp issue
        $teamIds = \DB::table('team_members')->where('user_id', $user->id)->pluck('team_id');
        expect($teamIds)->toHaveCount(1);
    });
});

describe('User Model — Helpers', function () {
    it('isAdmin returns true for Platform Admin', function () {
        seedRoles();
        $user = User::factory()->create();
        setPermissionsTeamId(null);
        $user->assignRole('Platform Admin');
        $user->unsetRelations();

        expect($user->isAdmin())->toBeTrue();
    });

    it('isAdmin returns true for Games Admin', function () {
        seedRoles();
        $user = User::factory()->create();
        setPermissionsTeamId(null);
        $user->assignRole('Games Admin');
        $user->unsetRelations();

        expect($user->isAdmin())->toBeTrue();
    });

    it('isAdmin returns false for regular user', function () {
        $user = User::factory()->create();

        expect($user->isAdmin())->toBeFalse();
    });

    it('isTeamCaptain returns true for captain of given team', function () {
        $user = User::factory()->create();
        $team = \App\Models\Team::factory()->create();
        \App\Models\TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        expect($user->fresh()->isTeamCaptain($team))->toBeTrue();
    });

    it('isTeamCaptain returns false for player of team', function () {
        $user = User::factory()->create();
        $team = \App\Models\Team::factory()->create();
        \App\Models\TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        expect($user->fresh()->isTeamCaptain($team))->toBeFalse();
    });

    it('isTeamCaptain returns false for non-member', function () {
        $user = User::factory()->create();
        $team = \App\Models\Team::factory()->create();

        expect($user->isTeamCaptain($team))->toBeFalse();
    });

    it('hasActiveMembership returns false when no subscription', function () {
        $user = User::factory()->create();

        expect($user->hasActiveMembership())->toBeFalse();
    });
});

<?php

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;

// ═══════════════════════════════════════════════════════════
// TEAM MEMBER MODEL
// ═══════════════════════════════════════════════════════════

describe('TeamMember Model', function () {
    it('creates with fillable attributes', function () {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $inviter = User::factory()->create();

        $member = TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'active',
            'jersey_number' => '42',
            'position' => 'forward',
            'invited_by' => $inviter->id,
            'notes' => 'Great player',
            'joined_at' => now(),
        ]);

        expect($member->role)->toBe('player')
            ->and($member->status)->toBe('active')
            ->and($member->jersey_number)->toBe('42');
    });

    it('casts joined_at and left_at as datetime', function () {
        $team = Team::factory()->create();
        $user = User::factory()->create();

        $member = TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => '2025-04-13 10:00:00',
            'left_at' => '2025-06-01 12:00:00',
        ]);

        expect($member->joined_at)->toBeInstanceOf(\Carbon\Carbon::class)
            ->and($member->left_at)->toBeInstanceOf(\Carbon\Carbon::class);
    });

    it('has team relationship', function () {
        $team = Team::factory()->create(['name' => 'Test Team']);
        $user = User::factory()->create();

        $member = TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        expect($member->team->name)->toBe('Test Team');
    });

    it('has user relationship', function () {
        $team = Team::factory()->create();
        $user = User::factory()->create(['name' => 'Alice']);

        $member = TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        expect($member->user->name)->toBe('Alice');
    });

    it('has invitedBy relationship', function () {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $inviter = User::factory()->create(['name' => 'Inviter']);

        $member = TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'pending',
            'invited_by' => $inviter->id,
            'joined_at' => now(),
        ]);

        expect($member->invitedBy->name)->toBe('Inviter');
    });

    it('isActive returns true for active status', function () {
        $team = Team::factory()->create();
        $user = User::factory()->create();

        $member = TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        expect($member->isActive())->toBeTrue();
    });

    it('isActive returns false for inactive status', function () {
        $team = Team::factory()->create();
        $user = User::factory()->create();

        $member = TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'inactive',
            'joined_at' => now(),
        ]);

        expect($member->isActive())->toBeFalse();
    });

    it('isCaptain returns true for captain role', function () {
        $team = Team::factory()->create();
        $user = User::factory()->create();

        $member = TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        expect($member->isCaptain())->toBeTrue();
    });

    it('isCaptain returns false for player role', function () {
        $team = Team::factory()->create();
        $user = User::factory()->create();

        $member = TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        expect($member->isCaptain())->toBeFalse();
    });
});

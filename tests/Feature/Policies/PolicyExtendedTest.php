<?php

use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\MembershipType;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

// ═══════════════════════════════════════════════════════════
// POLICY RESTORE / FORCE-DELETE COVERAGE
//
// No model uses SoftDeletes, so restore/forceDelete are always
// denied (Laravel's default behaviour when the policy method
// does not exist). These tests confirm the dead-code removal
// is safe — the actions are correctly rejected.
// ═══════════════════════════════════════════════════════════

describe('CampaignPolicy — Restore & ForceDelete denied', function () {
    it('denies owner from restoring (no SoftDeletes)', function () {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

        expect(Gate::forUser($owner)->allows('restore', $campaign))->toBeFalse();
    });

    it('denies stranger from restoring', function () {
        $stranger = User::factory()->create();
        $campaign = Campaign::factory()->create();

        expect(Gate::forUser($stranger)->allows('restore', $campaign))->toBeFalse();
    });

    it('denies owner from forceDeleting (no SoftDeletes)', function () {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

        expect(Gate::forUser($owner)->allows('forceDelete', $campaign))->toBeFalse();
    });

    it('denies stranger from forceDeleting', function () {
        $stranger = User::factory()->create();
        $campaign = Campaign::factory()->create();

        expect(Gate::forUser($stranger)->allows('forceDelete', $campaign))->toBeFalse();
    });
});

describe('GamePolicy — Restore & ForceDelete denied', function () {
    it('denies owner from restoring (no SoftDeletes)', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        expect(Gate::forUser($owner)->allows('restore', $game))->toBeFalse();
    });

    it('denies stranger from restoring', function () {
        $stranger = User::factory()->create();
        $game = Game::factory()->create();

        expect(Gate::forUser($stranger)->allows('restore', $game))->toBeFalse();
    });

    it('denies owner from forceDeleting (no SoftDeletes)', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        expect(Gate::forUser($owner)->allows('forceDelete', $game))->toBeFalse();
    });

    it('denies stranger from forceDeleting', function () {
        $stranger = User::factory()->create();
        $game = Game::factory()->create();

        expect(Gate::forUser($stranger)->allows('forceDelete', $game))->toBeFalse();
    });
});

describe('EventPolicy — Restore & ForceDelete denied', function () {
    it('denies organizer from restoring (no SoftDeletes)', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        expect(Gate::forUser($organizer)->allows('restore', $event))->toBeFalse();
    });

    it('denies stranger from restoring', function () {
        $stranger = User::factory()->create();
        $event = Event::factory()->create();

        expect(Gate::forUser($stranger)->allows('restore', $event))->toBeFalse();
    });

    it('denies organizer from forceDeleting (no SoftDeletes)', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        expect(Gate::forUser($organizer)->allows('forceDelete', $event))->toBeFalse();
    });

    it('denies stranger from forceDeleting', function () {
        $stranger = User::factory()->create();
        $event = Event::factory()->create();

        expect(Gate::forUser($stranger)->allows('forceDelete', $event))->toBeFalse();
    });
});

describe('TeamPolicy — Restore & ForceDelete denied', function () {
    it('denies captain from restoring (no SoftDeletes)', function () {
        $captain = User::factory()->create();
        $team = Team::factory()->create();
        \App\Models\TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $captain->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        expect(Gate::forUser($captain)->allows('restore', $team))->toBeFalse();
    });

    it('denies player from restoring', function () {
        $player = User::factory()->create();
        $team = Team::factory()->create();
        \App\Models\TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        expect(Gate::forUser($player)->allows('restore', $team))->toBeFalse();
    });

    it('denies captain from forceDeleting (no SoftDeletes)', function () {
        $captain = User::factory()->create();
        $team = Team::factory()->create();
        \App\Models\TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $captain->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        expect(Gate::forUser($captain)->allows('forceDelete', $team))->toBeFalse();
    });

    it('denies player from forceDeleting', function () {
        $player = User::factory()->create();
        $team = Team::factory()->create();
        \App\Models\TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        expect(Gate::forUser($player)->allows('forceDelete', $team))->toBeFalse();
    });
});

describe('UserPolicy — Admin Actions', function () {
    it('allows Platform Admin to delete user', function () {
        seedRoles();
        $admin = User::factory()->create();
        setPermissionsTeamId(null);
        $admin->assignRole('Platform Admin');
        $admin->unsetRelations();

        $target = User::factory()->create();

        expect(Gate::forUser($admin)->allows('delete', $target))->toBeTrue();
    });

    it('denies regular user from deleting another', function () {
        $user = User::factory()->create();
        $target = User::factory()->create();

        expect(Gate::forUser($user)->allows('delete', $target))->toBeFalse();
    });
});

describe('MembershipTypePolicy — Delete', function () {
    it('allows Platform Admin to delete membership type', function () {
        seedRoles();
        $admin = User::factory()->create();
        setPermissionsTeamId(null);
        $admin->assignRole('Platform Admin');
        $admin->unsetRelations();

        $type = MembershipType::factory()->create();

        expect(Gate::forUser($admin)->allows('delete', $type))->toBeTrue();
    });
});

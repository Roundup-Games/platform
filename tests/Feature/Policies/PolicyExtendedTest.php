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
// ═══════════════════════════════════════════════════════════

describe('CampaignPolicy — Restore & ForceDelete', function () {
    it('allows owner to restore', function () {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

        expect(Gate::forUser($owner)->allows('restore', $campaign))->toBeTrue();
    });

    it('denies stranger from restoring', function () {
        $stranger = User::factory()->create();
        $campaign = Campaign::factory()->create();

        expect(Gate::forUser($stranger)->allows('restore', $campaign))->toBeFalse();
    });

    it('allows owner to forceDelete', function () {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

        expect(Gate::forUser($owner)->allows('forceDelete', $campaign))->toBeTrue();
    });

    it('denies stranger from forceDeleting', function () {
        $stranger = User::factory()->create();
        $campaign = Campaign::factory()->create();

        expect(Gate::forUser($stranger)->allows('forceDelete', $campaign))->toBeFalse();
    });
});

describe('GamePolicy — Restore & ForceDelete', function () {
    it('allows owner to restore', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        expect(Gate::forUser($owner)->allows('restore', $game))->toBeTrue();
    });

    it('denies stranger from restoring', function () {
        $stranger = User::factory()->create();
        $game = Game::factory()->create();

        expect(Gate::forUser($stranger)->allows('restore', $game))->toBeFalse();
    });

    it('allows owner to forceDelete', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $owner->id]);

        expect(Gate::forUser($owner)->allows('forceDelete', $game))->toBeTrue();
    });

    it('denies stranger from forceDeleting', function () {
        $stranger = User::factory()->create();
        $game = Game::factory()->create();

        expect(Gate::forUser($stranger)->allows('forceDelete', $game))->toBeFalse();
    });
});

describe('EventPolicy — Restore & ForceDelete', function () {
    it('allows organizer to restore', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        expect(Gate::forUser($organizer)->allows('restore', $event))->toBeTrue();
    });

    it('denies stranger from restoring', function () {
        $stranger = User::factory()->create();
        $event = Event::factory()->create();

        expect(Gate::forUser($stranger)->allows('restore', $event))->toBeFalse();
    });

    it('allows organizer to forceDelete', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        expect(Gate::forUser($organizer)->allows('forceDelete', $event))->toBeTrue();
    });

    it('denies stranger from forceDeleting', function () {
        $stranger = User::factory()->create();
        $event = Event::factory()->create();

        expect(Gate::forUser($stranger)->allows('forceDelete', $event))->toBeFalse();
    });
});

describe('TeamPolicy — Restore & ForceDelete', function () {
    it('allows captain to restore', function () {
        $captain = User::factory()->create();
        $team = Team::factory()->create();
        \App\Models\TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $captain->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        expect(Gate::forUser($captain)->allows('restore', $team))->toBeTrue();
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

    it('allows captain to forceDelete', function () {
        $captain = User::factory()->create();
        $team = Team::factory()->create();
        \App\Models\TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $captain->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        expect(Gate::forUser($captain)->allows('forceDelete', $team))->toBeTrue();
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

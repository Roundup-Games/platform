<?php

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->captain = User::factory()->create();
    $this->coach = User::factory()->create();
    $this->player = User::factory()->create();
    $this->stranger = User::factory()->create();

    $this->team = Team::factory()->create([
        'name' => 'Test Team',
        'created_by' => $this->captain->id,
        'is_active' => true,
    ]);

    // Captain
    TeamMember::create([
        'team_id' => $this->team->id,
        'user_id' => $this->captain->id,
        'role' => 'captain',
        'status' => 'active',
        'joined_at' => now(),
    ]);

    // Coach
    TeamMember::create([
        'team_id' => $this->team->id,
        'user_id' => $this->coach->id,
        'role' => 'coach',
        'status' => 'active',
        'joined_at' => now(),
    ]);

    // Player
    TeamMember::create([
        'team_id' => $this->team->id,
        'user_id' => $this->player->id,
        'role' => 'player',
        'status' => 'active',
        'joined_at' => now(),
    ]);
});

describe('view', function () {
    test('guest can view active team', function () {
        expect($this->team->is_active)->toBeTrue();
        expect(Gate::allows('view', $this->team))->toBeTrue();
    })->group('smoke');

    test('guest cannot view inactive team', function () {
        $inactiveTeam = Team::factory()->create([
            'is_active' => false,
            'created_by' => $this->captain->id,
        ]);
        TeamMember::create([
            'team_id' => $inactiveTeam->id,
            'user_id' => $this->captain->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        expect(Gate::allows('view', $inactiveTeam))->toBeFalse();
    });

    test('member can view inactive team', function () {
        $inactiveTeam = Team::factory()->create([
            'is_active' => false,
            'created_by' => $this->captain->id,
        ]);
        TeamMember::create([
            'team_id' => $inactiveTeam->id,
            'user_id' => $this->player->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($this->player);
        expect(Gate::allows('view', $inactiveTeam))->toBeTrue();
    });
});

describe('create', function () {
    test('authenticated user with permission can create team', function () {
        // Seed the permission
        $perm = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'create team', 'guard_name' => 'web']);

        // Spatie teams feature scopes by team_id. Set a team ID and assign permission.
        setPermissionsTeamId(1); // arbitrary team ID for this test
        $this->stranger->givePermissionTo($perm);
        $this->stranger->unsetRelations();

        $this->actingAs($this->stranger);
        expect(Gate::allows('create', Team::class))->toBeTrue();

        // Reset team context
        setPermissionsTeamId(null);
    });

    test('authenticated user without permission cannot create team', function () {
        $this->actingAs($this->stranger);
        expect(Gate::allows('create', Team::class))->toBeFalse();
    });

    test('guest cannot create team', function () {
        expect(Gate::allows('create', Team::class))->toBeFalse();
    });
});

describe('update', function () {
    test('captain can update team', function () {
        $this->actingAs($this->captain);
        expect(Gate::allows('update', $this->team))->toBeTrue();
    })->group('smoke');

    test('coach can update team', function () {
        $this->actingAs($this->coach);
        expect(Gate::allows('update', $this->team))->toBeTrue();
    });

    test('stranger cannot update team', function () {
        $this->actingAs($this->stranger);
        expect(Gate::allows('update', $this->team))->toBeFalse();
    });
});

describe('delete', function () {
    test('captain can delete team', function () {
        $this->actingAs($this->captain);
        expect(Gate::allows('delete', $this->team))->toBeTrue();
    });

    test('coach cannot delete team', function () {
        $this->actingAs($this->coach);
        expect(Gate::allows('delete', $this->team))->toBeFalse();
    });

    test('stranger cannot delete team', function () {
        $this->actingAs($this->stranger);
        expect(Gate::allows('delete', $this->team))->toBeFalse();
    });
});

describe('manageMembers', function () {
    test('captain can manage members', function () {
        $this->actingAs($this->captain);
        expect(Gate::allows('manageMembers', $this->team))->toBeTrue();
    });

    test('coach cannot manage members', function () {
        $this->actingAs($this->coach);
        expect(Gate::allows('manageMembers', $this->team))->toBeFalse();
    });
});

describe('invite', function () {
    test('captain can invite', function () {
        $this->actingAs($this->captain);
        expect(Gate::allows('invite', $this->team))->toBeTrue();
    });

    test('coach can invite', function () {
        $this->actingAs($this->coach);
        expect(Gate::allows('invite', $this->team))->toBeTrue();
    });

    test('stranger cannot invite', function () {
        $this->actingAs($this->stranger);
        expect(Gate::allows('invite', $this->team))->toBeFalse();
    });
});

describe('inactive/pending members have no privileges', function () {
    test('inactive captain cannot update team', function () {
        TeamMember::where('team_id', $this->team->id)
            ->where('user_id', $this->captain->id)
            ->update(['status' => 'inactive']);

        $this->actingAs($this->captain);
        expect(Gate::allows('update', $this->team))->toBeFalse();
    });

    test('pending coach cannot invite', function () {
        TeamMember::where('team_id', $this->team->id)
            ->where('user_id', $this->coach->id)
            ->update(['status' => 'pending']);

        $this->actingAs($this->coach);
        expect(Gate::allows('invite', $this->team))->toBeFalse();
    });

    test('removed member cannot manage members', function () {
        TeamMember::where('team_id', $this->team->id)
            ->where('user_id', $this->captain->id)
            ->update(['status' => 'removed']);

        $this->actingAs($this->captain);
        expect(Gate::allows('manageMembers', $this->team))->toBeFalse();
    });
});

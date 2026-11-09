<?php

use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\ScopedRoleService;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;
use Tests\Traits\AssignsScopedRoles;

/*
 * M077/S01 consolidation: 37 tests → 11 tests (9 flow tests + 2 standalone removal tests).
 * Mapping (37 executed tests = 35 declarations; the non-global-admin dataset expands to 3):
 * - Global Admin (3 tests, incl. dataset) → 1 flow
 * - Team-Scoped Roles (8 tests) → 1 flow
 * - Event-Scoped Roles (4 tests) → 1 flow
 * - Role Removal (2 tests) → 2 standalone (mutate scoped role state mid-test)
 * - Policy Integration: Team Policy (5 tests) → 1 flow
 * - Policy Integration: Event Policy (4 tests) → 1 flow
 * - Policy Integration: Global Admin Bypass (2 tests) → 1 flow
 * - Ownership Checks (1 test) → 1 flow
 * - Multi-Team Isolation (2 tests) → 1 flow (role assignments hit fresh per-step users only)
 * - viewAny for Filament resource listing (4 tests) → 1 flow (role-assigning step kept last)
 * The shared beforeEach world is unchanged; every original assertion is preserved
 * verbatim inside its describe block's flow, with the original test name carried
 * as a comment per check.
 */

uses(AssignsScopedRoles::class);

beforeEach(function () {
    seedRoles();

    $this->service = app(ScopedRoleService::class);

    // Create users
    $this->platformAdmin = User::factory()->create();
    $this->gamesAdmin = User::factory()->create();
    $this->teamAdmin = User::factory()->create();
    $this->eventAdmin = User::factory()->create();
    $this->regularUser = User::factory()->create();
    $this->otherUser = User::factory()->create();

    // Assign global roles at team_id=null for true global scope
    setPermissionsTeamId(null);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->platformAdmin->assignRole('Platform Admin');
    $this->platformAdmin->unsetRelations();

    $this->gamesAdmin->assignRole('Games Admin');
    $this->gamesAdmin->unsetRelations();

    // Create teams
    $this->teamA = Team::factory()->create(['name' => 'Team A', 'is_active' => true, 'created_by' => $this->regularUser->id]);
    $this->teamB = Team::factory()->create(['name' => 'Team B', 'is_active' => true, 'created_by' => $this->otherUser->id]);

    // Create events
    $this->eventA = Event::factory()->create(['name' => 'Event A', 'organizer_id' => $this->regularUser->id, 'is_public' => true]);
    $this->eventB = Event::factory()->create(['name' => 'Event B', 'organizer_id' => $this->otherUser->id, 'is_public' => true]);

    // Assign Team Admin scoped to teamA
    $this->assignTeamScopedRole($this->teamAdmin, 'Team Admin', $this->teamA);

    // Assign Event Admin scoped to eventA
    $this->assignEventScopedRole($this->eventAdmin, 'Event Admin', $this->eventA);
});

describe('Global Admin', function () {
    test('global admin identification (flow)', function () {
        // Platform Admin is identified as global admin
        expect($this->service->isGlobalAdmin($this->platformAdmin))->toBeTrue();
        // Games Admin is identified as global admin
        expect($this->service->isGlobalAdmin($this->gamesAdmin))->toBeTrue();
        // non-global-admin roles are not identified as global admin
        // (was a dataset test: ['teamAdmin', 'eventAdmin', 'regularUser'])
        foreach (['teamAdmin', 'eventAdmin', 'regularUser'] as $role) {
            $user = $this->$role;
            expect($this->service->isGlobalAdmin($user))->toBeFalse();
        }
    });
});

describe('Team-Scoped Roles', function () {
    test('team-scoped role permission matrix (flow)', function () {
        // Team Admin can update their assigned team
        expect($this->service->hasTeamPermission($this->teamAdmin, 'update team', $this->teamA))->toBeTrue();
        // Team Admin can view team membership
        expect($this->service->hasTeamPermission($this->teamAdmin, 'view membership', $this->teamA))->toBeTrue();
        // Team Admin can create membership in their team
        expect($this->service->hasTeamPermission($this->teamAdmin, 'create membership', $this->teamA))->toBeTrue();
        // Team Admin cannot create membership in another team
        expect($this->service->hasTeamPermission($this->teamAdmin, 'create membership', $this->teamB))->toBeFalse();
        // Team Admin can view dashboard
        expect($this->service->hasTeamPermission($this->teamAdmin, 'view dashboard', $this->teamA))->toBeTrue();
        // Team Admin cannot delete team
        // Team Admin role does not have 'delete team' permission
        expect($this->service->hasTeamPermission($this->teamAdmin, 'delete team', $this->teamA))->toBeFalse();
        // Team Admin cannot manage settings
        expect($this->service->hasTeamPermission($this->teamAdmin, 'manage settings', $this->teamA))->toBeFalse();
        // Platform Admin bypasses team scope for any team
        expect($this->service->hasTeamPermission($this->platformAdmin, 'update team', $this->teamA))->toBeTrue();
        expect($this->service->hasTeamPermission($this->platformAdmin, 'update team', $this->teamB))->toBeTrue();
        expect($this->service->hasTeamPermission($this->platformAdmin, 'delete team', $this->teamA))->toBeTrue();
    });
});

describe('Event-Scoped Roles', function () {
    test('event-scoped role permission matrix (flow)', function () {
        // Event Admin can update their assigned event
        expect($this->service->hasEventPermission($this->eventAdmin, 'update event', $this->eventA))->toBeTrue();
        // Event Admin can delete their assigned event
        expect($this->service->hasEventPermission($this->eventAdmin, 'delete event', $this->eventA))->toBeTrue();
        // Event Admin can create event (global permission)
        expect($this->service->hasEventPermission($this->eventAdmin, 'create event', $this->eventA))->toBeTrue();
        // Platform Admin bypasses event scope for any event
        expect($this->service->hasEventPermission($this->platformAdmin, 'update event', $this->eventA))->toBeTrue();
        expect($this->service->hasEventPermission($this->platformAdmin, 'update event', $this->eventB))->toBeTrue();
        expect($this->service->hasEventPermission($this->platformAdmin, 'delete event', $this->eventA))->toBeTrue();
    });
});

describe('Role Removal', function () {
    // Kept standalone: mutates scoped role state mid-test (rule: do not merge into matrices).
    test('removing team-scoped role revokes permissions', function () {
        $this->removeTeamScopedRole($this->teamAdmin, 'Team Admin', $this->teamA);

        // Force fresh context
        setPermissionsTeamId(null);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->teamAdmin->unsetRelations();

        expect($this->service->hasTeamPermission($this->teamAdmin, 'update team', $this->teamA))->toBeFalse();
    });

    // Kept standalone: mutates scoped role state mid-test (rule: do not merge into matrices).
    test('removing event-scoped role revokes permissions', function () {
        $this->removeEventScopedRole($this->eventAdmin, 'Event Admin', $this->eventA);

        // Force fresh context
        setPermissionsTeamId(null);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->eventAdmin->unsetRelations();

        expect($this->service->hasEventPermission($this->eventAdmin, 'update event', $this->eventA))->toBeFalse();
    });
});

describe('Policy Integration: Team Policy', function () {
    test('team policy integration (flow)', function () {
        // Team Admin can update team via TeamPolicy
        $this->actingAs($this->teamAdmin);
        expect(Gate::allows('update', $this->teamA))->toBeTrue();
        // Team Admin cannot update another team via TeamPolicy
        $this->actingAs($this->teamAdmin);
        expect(Gate::allows('update', $this->teamB))->toBeFalse();
        // Team Admin cannot create teams via TeamPolicy
        $this->actingAs($this->teamAdmin);
        expect(Gate::allows('create', Team::class))->toBeFalse();
        // Team Admin cannot delete team via TeamPolicy
        // Team Admin role does not include 'delete team' permission
        $this->actingAs($this->teamAdmin);
        expect(Gate::allows('delete', $this->teamA))->toBeFalse();
        // captain can still update team regardless of scoped roles
        // (creates a TeamMember row — membership, not a role assignment; no effect on earlier checks)
        $captain = User::factory()->create();
        TeamMember::create([
            'team_id' => $this->teamA->id,
            'user_id' => $captain->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($captain);
        expect(Gate::allows('update', $this->teamA))->toBeTrue();
    });
});

describe('Policy Integration: Event Policy', function () {
    test('event policy integration (flow)', function () {
        // Event Admin can update event via EventPolicy
        $this->actingAs($this->eventAdmin);
        expect(Gate::allows('update', $this->eventA))->toBeTrue();
        // Event Admin cannot update another event via EventPolicy
        $this->actingAs($this->eventAdmin);
        expect(Gate::allows('update', $this->eventB))->toBeFalse();
        // Event Admin cannot delete another event via EventPolicy
        $this->actingAs($this->eventAdmin);
        expect(Gate::allows('delete', $this->eventB))->toBeFalse();
        // organizer can always update their own event
        $this->actingAs($this->regularUser);
        expect(Gate::allows('update', $this->eventA))->toBeTrue();
    });
});

describe('Policy Integration: Global Admin Bypass', function () {
    test('global admin bypass policy integration (flow)', function () {
        // Platform Admin bypasses all policies via before hook
        // smoke: Platform Admin bypasses all authorization policies
        $this->actingAs($this->platformAdmin);

        // User policy
        expect(Gate::allows('viewAny', User::class))->toBeTrue();
        expect(Gate::allows('delete', $this->otherUser))->toBeTrue();

        // Team policy
        expect(Gate::allows('update', $this->teamA))->toBeTrue();
        expect(Gate::allows('delete', $this->teamA))->toBeTrue();

        // Event policy
        expect(Gate::allows('update', $this->eventA))->toBeTrue();
        expect(Gate::allows('delete', $this->eventA))->toBeTrue();

        // Game/Campaign policy
        $game = Game::factory()->create(['owner_id' => $this->otherUser->id]);
        $campaign = Campaign::factory()->create(['owner_id' => $this->otherUser->id]);
        expect(Gate::allows('update', $game))->toBeTrue();
        expect(Gate::allows('delete', $campaign))->toBeTrue();

        // Games Admin bypasses all policies via before hook
        $this->actingAs($this->gamesAdmin);

        expect(Gate::allows('viewAny', Game::class))->toBeTrue();
        expect(Gate::allows('viewAny', Campaign::class))->toBeTrue();
        expect(Gate::allows('viewAny', User::class))->toBeTrue();

        $game = Game::factory()->create(['owner_id' => $this->otherUser->id]);
        expect(Gate::allows('update', $game))->toBeTrue();
        expect(Gate::allows('delete', $game))->toBeTrue();
    });
});

describe('Ownership Checks', function () {
    test('ownership checks (flow)', function () {
        // entity owner can update and delete their own entity
        $game = Game::factory()->create(['owner_id' => $this->regularUser->id, 'visibility' => 'public']);
        $campaign = Campaign::factory()->create(['owner_id' => $this->regularUser->id, 'visibility' => 'public']);
        $this->actingAs($this->regularUser);
        expect(Gate::allows('update', $game))->toBeTrue();
        expect(Gate::allows('delete', $game))->toBeTrue();
        expect(Gate::allows('update', $campaign))->toBeTrue();
        expect(Gate::allows('delete', $campaign))->toBeTrue();
    });
});

describe('Multi-Team Isolation', function () {
    test('multi-team isolation (flow)', function () {
        // user can be Team Admin of multiple teams
        // (assigns 'Team Admin' scoped to teamA and teamB — on a fresh user only,
        // so no later step depends on or breaks from this state)
        $multiAdmin = User::factory()->create();
        $this->assignTeamScopedRole($multiAdmin, 'Team Admin', $this->teamA);
        $this->assignTeamScopedRole($multiAdmin, 'Team Admin', $this->teamB);

        expect($this->service->hasTeamPermission($multiAdmin, 'update team', $this->teamA))->toBeTrue();
        expect($this->service->hasTeamPermission($multiAdmin, 'update team', $this->teamB))->toBeTrue();

        // user can be Event Admin of multiple events
        // (assigns 'Event Admin' scoped to eventA and eventB — on a second fresh user only)
        $multiAdmin = User::factory()->create();
        $this->assignEventScopedRole($multiAdmin, 'Event Admin', $this->eventA);
        $this->assignEventScopedRole($multiAdmin, 'Event Admin', $this->eventB);

        expect($this->service->hasEventPermission($multiAdmin, 'update event', $this->eventA))->toBeTrue();
        expect($this->service->hasEventPermission($multiAdmin, 'update event', $this->eventB))->toBeTrue();
    });
});

describe('viewAny for Filament resource listing', function () {
    test('viewAny authorization matrix (flow)', function () {
        // regular user without permissions cannot viewAny entities
        $this->actingAs($this->regularUser);

        expect(Gate::allows('viewAny', User::class))->toBeFalse();
        expect(Gate::allows('viewAny', Team::class))->toBeFalse();
        expect(Gate::allows('viewAny', Game::class))->toBeFalse();
        expect(Gate::allows('viewAny', Campaign::class))->toBeFalse();
        expect(Gate::allows('viewAny', Event::class))->toBeFalse();

        // Team Admin can viewAny teams (has view team permission)
        $this->actingAs($this->teamAdmin);
        expect(Gate::allows('viewAny', Team::class))->toBeTrue();

        // event-scoped Event Admin cannot viewAny events: any-scope leak closed (M063/S04/T03)
        $this->actingAs($this->eventAdmin);
        expect(Gate::allows('viewAny', Event::class))->toBeFalse();

        // globally-assigned Event Admin can viewAny events (curated organizer)
        // NOTE: this step assigns 'Event Admin' to $this->regularUser — it must stay
        // LAST: the earlier steps assert regularUser has no viewAny permissions.
        setPermissionsTeamId(null);
        $this->regularUser->assignRole('Event Admin');
        $this->regularUser->unsetRelations();

        $this->actingAs($this->regularUser);
        expect(Gate::allows('viewAny', Event::class))->toBeTrue();
    });
});

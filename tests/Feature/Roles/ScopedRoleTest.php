<?php

use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\ScopedRoleService;
use Illuminate\Support\Facades\Gate;

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
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

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
    $this->service->assignTeamScopedRole($this->teamAdmin, 'Team Admin', $this->teamA);

    // Assign Event Admin scoped to eventA
    $this->service->assignEventScopedRole($this->eventAdmin, 'Event Admin', $this->eventA);
});

// ── Global Admin ──────────────────────────────────────

test('Platform Admin is identified as global admin', function () {
    expect($this->service->isGlobalAdmin($this->platformAdmin))->toBeTrue();
});

test('Games Admin is identified as global admin', function () {
    expect($this->service->isGlobalAdmin($this->gamesAdmin))->toBeTrue();
});

test('Team Admin is not global admin', function () {
    expect($this->service->isGlobalAdmin($this->teamAdmin))->toBeFalse();
});

test('Event Admin is not global admin', function () {
    expect($this->service->isGlobalAdmin($this->eventAdmin))->toBeFalse();
});

test('regular user is not global admin', function () {
    expect($this->service->isGlobalAdmin($this->regularUser))->toBeFalse();
});

// ── Team-Scoped Roles ─────────────────────────────────

test('Team Admin can update their assigned team', function () {
    expect($this->service->hasTeamPermission($this->teamAdmin, 'update team', $this->teamA))->toBeTrue();
});

test('Team Admin cannot update a different team', function () {
    expect($this->service->hasTeamPermission($this->teamAdmin, 'update team', $this->teamB))->toBeFalse();
});

test('Team Admin can view team membership', function () {
    expect($this->service->hasTeamPermission($this->teamAdmin, 'view membership', $this->teamA))->toBeTrue();
});

test('Team Admin can create membership in their team', function () {
    expect($this->service->hasTeamPermission($this->teamAdmin, 'create membership', $this->teamA))->toBeTrue();
});

test('Team Admin cannot create membership in another team', function () {
    expect($this->service->hasTeamPermission($this->teamAdmin, 'create membership', $this->teamB))->toBeFalse();
});

test('Team Admin can view dashboard', function () {
    expect($this->service->hasTeamPermission($this->teamAdmin, 'view dashboard', $this->teamA))->toBeTrue();
});

test('Team Admin cannot delete team', function () {
    // Team Admin role does not have 'delete team' permission
    expect($this->service->hasTeamPermission($this->teamAdmin, 'delete team', $this->teamA))->toBeFalse();
});

test('Team Admin cannot manage settings', function () {
    expect($this->service->hasTeamPermission($this->teamAdmin, 'manage settings', $this->teamA))->toBeFalse();
});

test('Platform Admin bypasses team scope for any team', function () {
    expect($this->service->hasTeamPermission($this->platformAdmin, 'update team', $this->teamA))->toBeTrue();
    expect($this->service->hasTeamPermission($this->platformAdmin, 'update team', $this->teamB))->toBeTrue();
    expect($this->service->hasTeamPermission($this->platformAdmin, 'delete team', $this->teamA))->toBeTrue();
});

// ── Event-Scoped Roles ────────────────────────────────

test('Event Admin can update their assigned event', function () {
    expect($this->service->hasEventPermission($this->eventAdmin, 'update event', $this->eventA))->toBeTrue();
});

test('Event Admin cannot update a different event', function () {
    expect($this->service->hasEventPermission($this->eventAdmin, 'update event', $this->eventB))->toBeFalse();
});

test('Event Admin can delete their assigned event', function () {
    expect($this->service->hasEventPermission($this->eventAdmin, 'delete event', $this->eventA))->toBeTrue();
});

test('Event Admin cannot delete a different event', function () {
    expect($this->service->hasEventPermission($this->eventAdmin, 'delete event', $this->eventB))->toBeFalse();
});

test('Event Admin can create event (global permission)', function () {
    expect($this->service->hasEventPermission($this->eventAdmin, 'create event', $this->eventA))->toBeTrue();
});

test('Event Admin cannot manage settings', function () {
    expect($this->service->hasEventPermission($this->eventAdmin, 'manage settings', $this->eventA))->toBeFalse();
});

test('Platform Admin bypasses event scope for any event', function () {
    expect($this->service->hasEventPermission($this->platformAdmin, 'update event', $this->eventA))->toBeTrue();
    expect($this->service->hasEventPermission($this->platformAdmin, 'update event', $this->eventB))->toBeTrue();
    expect($this->service->hasEventPermission($this->platformAdmin, 'delete event', $this->eventA))->toBeTrue();
});

// ── Administered Entities ─────────────────────────────

test('getAdministeredTeams returns teams where user is Team Admin', function () {
    $teams = $this->service->getAdministeredTeams($this->teamAdmin);
    expect($teams->count())->toBe(1);
    expect($teams->first()->id)->toBe($this->teamA->id);
});

test('getAdministeredTeams returns empty for non-team-admin', function () {
    $teams = $this->service->getAdministeredTeams($this->regularUser);
    expect($teams)->toBeEmpty();
});

test('getAdministeredTeams returns empty for Platform Admin (global, not scoped)', function () {
    // Platform Admin role is global, not scoped to any team_id
    $teams = $this->service->getAdministeredTeams($this->platformAdmin);
    expect($teams)->toBeEmpty();
});

test('getAdministeredEvents returns events where user is Event Admin', function () {
    $events = $this->service->getAdministeredEvents($this->eventAdmin);
    expect($events->count())->toBe(1);
    expect($events->first()->id)->toBe($this->eventA->id);
});

test('getAdministeredEvents returns empty for non-event-admin', function () {
    $events = $this->service->getAdministeredEvents($this->regularUser);
    expect($events)->toBeEmpty();
});

// ── Role Removal ──────────────────────────────────────

test('removing team-scoped role revokes permissions', function () {
    $this->service->removeTeamScopedRole($this->teamAdmin, 'Team Admin', $this->teamA);

    // Force fresh context
    setPermissionsTeamId(null);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $this->teamAdmin->unsetRelations();

    expect($this->service->hasTeamPermission($this->teamAdmin, 'update team', $this->teamA))->toBeFalse();
});

test('removing event-scoped role revokes permissions', function () {
    $this->service->removeEventScopedRole($this->eventAdmin, 'Event Admin', $this->eventA);

    // Force fresh context
    setPermissionsTeamId(null);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $this->eventAdmin->unsetRelations();

    expect($this->service->hasEventPermission($this->eventAdmin, 'update event', $this->eventA))->toBeFalse();
});

// ── Policy Integration: Team Policy ───────────────────

test('Team Admin can update team via TeamPolicy', function () {
    $this->actingAs($this->teamAdmin);
    expect(Gate::allows('update', $this->teamA))->toBeTrue();
});

test('Team Admin cannot update another team via TeamPolicy', function () {
    $this->actingAs($this->teamAdmin);
    expect(Gate::allows('update', $this->teamB))->toBeFalse();
});

test('Team Admin cannot create teams via TeamPolicy', function () {
    $this->actingAs($this->teamAdmin);
    expect(Gate::allows('create', Team::class))->toBeFalse();
});

test('Team Admin cannot delete team via TeamPolicy', function () {
    // Team Admin role does not include 'delete team' permission
    $this->actingAs($this->teamAdmin);
    expect(Gate::allows('delete', $this->teamA))->toBeFalse();
});

test('captain can still update team regardless of scoped roles', function () {
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

// ── Policy Integration: Event Policy ──────────────────

test('Event Admin can update event via EventPolicy', function () {
    $this->actingAs($this->eventAdmin);
    expect(Gate::allows('update', $this->eventA))->toBeTrue();
});

test('Event Admin cannot update another event via EventPolicy', function () {
    $this->actingAs($this->eventAdmin);
    expect(Gate::allows('update', $this->eventB))->toBeFalse();
});

test('Event Admin cannot delete another event via EventPolicy', function () {
    $this->actingAs($this->eventAdmin);
    expect(Gate::allows('delete', $this->eventB))->toBeFalse();
});

test('organizer can always update their own event', function () {
    $this->actingAs($this->regularUser);
    expect(Gate::allows('update', $this->eventA))->toBeTrue();
});

// ── Policy Integration: Global Admin Bypass ───────────

test('Platform Admin bypasses all policies via before hook', function () {
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
});

test('Games Admin bypasses all policies via before hook', function () {
    $this->actingAs($this->gamesAdmin);

    expect(Gate::allows('viewAny', Game::class))->toBeTrue();
    expect(Gate::allows('viewAny', Campaign::class))->toBeTrue();
    expect(Gate::allows('viewAny', User::class))->toBeTrue();

    $game = Game::factory()->create(['owner_id' => $this->otherUser->id]);
    expect(Gate::allows('update', $game))->toBeTrue();
    expect(Gate::allows('delete', $game))->toBeTrue();
});

// ── Ownership Checks ─────────────────────────────────

test('game owner can update their own game', function () {
    $game = Game::factory()->create(['owner_id' => $this->regularUser->id, 'visibility' => 'public']);
    $this->actingAs($this->regularUser);
    expect(Gate::allows('update', $game))->toBeTrue();
});

test('game owner can delete their own game', function () {
    $game = Game::factory()->create(['owner_id' => $this->regularUser->id, 'visibility' => 'public']);
    $this->actingAs($this->regularUser);
    expect(Gate::allows('delete', $game))->toBeTrue();
});

test('non-owner without permission cannot update game', function () {
    $game = Game::factory()->create(['owner_id' => $this->otherUser->id, 'visibility' => 'public']);
    $this->actingAs($this->regularUser);
    expect(Gate::allows('update', $game))->toBeFalse();
});

test('campaign owner can update their own campaign', function () {
    $campaign = Campaign::factory()->create(['owner_id' => $this->regularUser->id, 'visibility' => 'public']);
    $this->actingAs($this->regularUser);
    expect(Gate::allows('update', $campaign))->toBeTrue();
});

test('campaign owner can delete their own campaign', function () {
    $campaign = Campaign::factory()->create(['owner_id' => $this->regularUser->id, 'visibility' => 'public']);
    $this->actingAs($this->regularUser);
    expect(Gate::allows('delete', $campaign))->toBeTrue();
});

test('non-owner without permission cannot update campaign', function () {
    $campaign = Campaign::factory()->create(['owner_id' => $this->otherUser->id, 'visibility' => 'public']);
    $this->actingAs($this->regularUser);
    expect(Gate::allows('update', $campaign))->toBeFalse();
});

// ── Multi-Team Isolation ─────────────────────────────

test('user can be Team Admin of multiple teams', function () {
    $multiAdmin = User::factory()->create();
    $this->service->assignTeamScopedRole($multiAdmin, 'Team Admin', $this->teamA);
    $this->service->assignTeamScopedRole($multiAdmin, 'Team Admin', $this->teamB);

    expect($this->service->hasTeamPermission($multiAdmin, 'update team', $this->teamA))->toBeTrue();
    expect($this->service->hasTeamPermission($multiAdmin, 'update team', $this->teamB))->toBeTrue();

    $teams = $this->service->getAdministeredTeams($multiAdmin);
    expect($teams->count())->toBe(2);
});

test('user can be Event Admin of multiple events', function () {
    $multiAdmin = User::factory()->create();
    $this->service->assignEventScopedRole($multiAdmin, 'Event Admin', $this->eventA);
    $this->service->assignEventScopedRole($multiAdmin, 'Event Admin', $this->eventB);

    expect($this->service->hasEventPermission($multiAdmin, 'update event', $this->eventA))->toBeTrue();
    expect($this->service->hasEventPermission($multiAdmin, 'update event', $this->eventB))->toBeTrue();

    $events = $this->service->getAdministeredEvents($multiAdmin);
    expect($events->count())->toBe(2);
});

// ── checkPermission graceful handling ─────────────────

test('checkPermission returns false for non-existent permission', function () {
    // Create a user with no permissions seeded
    $freshUser = User::factory()->create();
    expect($this->service->checkPermission($freshUser, 'nonexistent permission'))->toBeFalse();
});

// ── viewAny for Filament resource listing ─────────────

test('regular user without permissions cannot viewAny entities', function () {
    $this->actingAs($this->regularUser);

    expect(Gate::allows('viewAny', User::class))->toBeFalse();
    expect(Gate::allows('viewAny', Team::class))->toBeFalse();
    expect(Gate::allows('viewAny', Game::class))->toBeFalse();
    expect(Gate::allows('viewAny', Campaign::class))->toBeFalse();
    expect(Gate::allows('viewAny', Event::class))->toBeFalse();
});

test('Team Admin can viewAny teams (has view team permission)', function () {
    $this->actingAs($this->teamAdmin);
    expect(Gate::allows('viewAny', Team::class))->toBeTrue();
});

test('Event Admin can viewAny events (has view event permission)', function () {
    $this->actingAs($this->eventAdmin);
    expect(Gate::allows('viewAny', Event::class))->toBeTrue();
});

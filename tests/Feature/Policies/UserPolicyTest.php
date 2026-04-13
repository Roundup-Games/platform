<?php

use App\Models\User;
use App\Models\Team;
use App\Services\ScopedRoleService;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // Seed permissions
    seedPermissions();
    seedRoles();

    $this->admin = User::factory()->create();
    $this->gamesAdmin = User::factory()->create();
    $this->regularUser = User::factory()->create();
    $this->otherUser = User::factory()->create();

    // Assign global admin roles at team_id=1
    setPermissionsTeamId(1);
    $this->admin->assignRole('Platform Admin');
    $this->admin->unsetRelations();

    setPermissionsTeamId(1);
    $this->gamesAdmin->assignRole('Games Admin');
    $this->gamesAdmin->unsetRelations();

    setPermissionsTeamId(1);
});

// ── before() global admin bypass ─────────────────────

test('Platform Admin can do anything on users', function () {
    $this->actingAs($this->admin);
    expect(Gate::allows('viewAny', User::class))->toBeTrue();
    expect(Gate::allows('view', $this->otherUser))->toBeTrue();
    expect(Gate::allows('create', User::class))->toBeTrue();
    expect(Gate::allows('update', $this->otherUser))->toBeTrue();
    expect(Gate::allows('delete', $this->otherUser))->toBeTrue();
});

test('Games Admin can do anything on users', function () {
    $this->actingAs($this->gamesAdmin);
    expect(Gate::allows('viewAny', User::class))->toBeTrue();
    expect(Gate::allows('update', $this->otherUser))->toBeTrue();
});

// ── viewAny ──────────────────────────────────────────

test('user with view user permission can viewAny', function () {
    setPermissionsTeamId(1);
    $this->regularUser->givePermissionTo('view user');
    $this->regularUser->unsetRelations();

    $this->actingAs($this->regularUser);
    expect(Gate::allows('viewAny', User::class))->toBeTrue();
});

test('user without permission cannot viewAny', function () {
    $this->actingAs($this->regularUser);
    expect(Gate::allows('viewAny', User::class))->toBeFalse();
});

// ── view ─────────────────────────────────────────────

test('user can view their own profile', function () {
    $this->actingAs($this->regularUser);
    expect(Gate::allows('view', $this->regularUser))->toBeTrue();
});

test('user with permission can view other user', function () {
    setPermissionsTeamId(1);
    $this->regularUser->givePermissionTo('view user');
    $this->regularUser->unsetRelations();

    $this->actingAs($this->regularUser);
    expect(Gate::allows('view', $this->otherUser))->toBeTrue();
});

test('user without permission cannot view other user', function () {
    $this->actingAs($this->regularUser);
    expect(Gate::allows('view', $this->otherUser))->toBeFalse();
});

// ── update ───────────────────────────────────────────

test('user can update their own profile', function () {
    $this->actingAs($this->regularUser);
    expect(Gate::allows('update', $this->regularUser))->toBeTrue();
});

test('user with permission can update other user', function () {
    setPermissionsTeamId(1);
    $this->regularUser->givePermissionTo('update user');
    $this->regularUser->unsetRelations();

    $this->actingAs($this->regularUser);
    expect(Gate::allows('update', $this->otherUser))->toBeTrue();
});

test('user without permission cannot update other user', function () {
    $this->actingAs($this->regularUser);
    expect(Gate::allows('update', $this->otherUser))->toBeFalse();
});

// ── delete ───────────────────────────────────────────

test('user cannot delete themselves', function () {
    $this->actingAs($this->regularUser);
    expect(Gate::allows('delete', $this->regularUser))->toBeFalse();
});

test('user with permission can delete other user', function () {
    setPermissionsTeamId(1);
    $this->regularUser->givePermissionTo('delete user');
    $this->regularUser->unsetRelations();

    $this->actingAs($this->regularUser);
    expect(Gate::allows('delete', $this->otherUser))->toBeTrue();
});

test('user without permission cannot delete other user', function () {
    $this->actingAs($this->regularUser);
    expect(Gate::allows('delete', $this->otherUser))->toBeFalse();
});

// ── create ───────────────────────────────────────────

test('user with create user permission can create', function () {
    setPermissionsTeamId(1);
    $this->regularUser->givePermissionTo('create user');
    $this->regularUser->unsetRelations();

    $this->actingAs($this->regularUser);
    expect(Gate::allows('create', User::class))->toBeTrue();
});

test('user without permission cannot create user', function () {
    $this->actingAs($this->regularUser);
    expect(Gate::allows('create', User::class))->toBeFalse();
});

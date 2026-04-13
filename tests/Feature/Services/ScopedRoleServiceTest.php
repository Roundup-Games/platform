<?php

use App\Models\Event;
use App\Models\Team;
use App\Models\User;
use App\Services\ScopedRoleService;

beforeEach(function () {
    seedRoles();
    $this->service = app(ScopedRoleService::class);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create(['name' => 'Test Team', 'is_active' => true, 'created_by' => $this->user->id]);
    $this->event = Event::factory()->create(['name' => 'Test Event', 'organizer_id' => $this->user->id, 'is_public' => true]);

    // Ensure we start with a clean global context
    setPermissionsTeamId(null);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
});

// ── assignTeamScopedRole exception safety ─────────────

test('assignTeamScopedRole resets context when role does not exist', function () {
    expect(getPermissionsTeamId())->toBeNull();

    try {
        $this->service->assignTeamScopedRole($this->user, 'Nonexistent Role', $this->team);
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        // Expected
    }

    expect(getPermissionsTeamId())->toBeNull();
});

test('assignTeamScopedRole resets context when assignRole throws', function () {
    // Create a user that will cause assignRole to fail by mocking
    $user = Mockery::mock($this->user)->makePartial();
    $user->shouldReceive('assignRole')->andThrow(new \RuntimeException('DB error'));

    expect(getPermissionsTeamId())->toBeNull();

    try {
        $this->service->assignTeamScopedRole($user, 'Team Admin', $this->team);
    } catch (\RuntimeException $e) {
        // Expected
    }

    expect(getPermissionsTeamId())->toBeNull();
});

// ── assignEventScopedRole exception safety ────────────

test('assignEventScopedRole resets context when role does not exist', function () {
    expect(getPermissionsTeamId())->toBeNull();

    try {
        $this->service->assignEventScopedRole($this->user, 'Nonexistent Role', $this->event);
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        // Expected
    }

    expect(getPermissionsTeamId())->toBeNull();
});

// ── removeTeamScopedRole exception safety ─────────────

test('removeTeamScopedRole resets context when removeRole throws', function () {
    $user = Mockery::mock($this->user)->makePartial();
    $user->shouldReceive('removeRole')->andThrow(new \RuntimeException('DB error'));

    expect(getPermissionsTeamId())->toBeNull();

    try {
        $this->service->removeTeamScopedRole($user, 'Team Admin', $this->team);
    } catch (\RuntimeException $e) {
        // Expected
    }

    expect(getPermissionsTeamId())->toBeNull();
});

// ── removeEventScopedRole exception safety ────────────

test('removeEventScopedRole resets context when removeRole throws', function () {
    $user = Mockery::mock($this->user)->makePartial();
    $user->shouldReceive('removeRole')->andThrow(new \RuntimeException('DB error'));

    expect(getPermissionsTeamId())->toBeNull();

    try {
        $this->service->removeEventScopedRole($user, 'Event Admin', $this->event);
    } catch (\RuntimeException $e) {
        // Expected
    }

    expect(getPermissionsTeamId())->toBeNull();
});

// ── hasTeamPermission exception safety ────────────────

test('hasTeamPermission resets context when checkPermission throws', function () {
    // Use a service with a mocked checkPermission that throws
    $service = Mockery::mock(ScopedRoleService::class)->makePartial();
    $service->shouldReceive('checkPermission')
        ->andReturn(false)  // first call (global check)
        ->andReturnUsing(function () { throw new \RuntimeException('Unexpected'); }); // second call (scoped check)

    expect(getPermissionsTeamId())->toBeNull();

    try {
        $service->hasTeamPermission($this->user, 'update team', $this->team);
    } catch (\RuntimeException $e) {
        // Expected
    }

    expect(getPermissionsTeamId())->toBeNull();
});

// ── hasEventPermission exception safety ───────────────

test('hasEventPermission resets context when checkPermission throws', function () {
    $service = Mockery::mock(ScopedRoleService::class)->makePartial();
    $service->shouldReceive('checkPermission')
        ->andReturn(false)
        ->andReturnUsing(function () { throw new \RuntimeException('Unexpected'); });

    expect(getPermissionsTeamId())->toBeNull();

    try {
        $service->hasEventPermission($this->user, 'update event', $this->event);
    } catch (\RuntimeException $e) {
        // Expected
    }

    expect(getPermissionsTeamId())->toBeNull();
});

// ── hasPermissionInAnyScope exception safety ──────────

test('hasPermissionInAnyScope restores original context on exception', function () {
    // Set a known original context to verify restoration
    setPermissionsTeamId(42);

    // Create a service where iteration will fail
    $service = Mockery::mock(ScopedRoleService::class)->makePartial();
    $service->shouldReceive('checkPermission')->andReturn(false);

    // Assign a scoped role so there's something to iterate
    $realService = app(ScopedRoleService::class);
    setPermissionsTeamId(null);
    $realService->assignTeamScopedRole($this->user, 'Team Admin', $this->team);

    // Reset to our test context
    setPermissionsTeamId(42);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $this->user->unsetRelations();

    // Now override hasPermissionTo to throw during iteration
    $mockUser = Mockery::mock($this->user)->makePartial();
    $mockUser->shouldReceive('hasPermissionTo')->andThrow(new \RuntimeException('Break iteration'));

    try {
        $service->hasPermissionInAnyScope($mockUser, 'update team');
    } catch (\RuntimeException $e) {
        // Expected
    }

    expect(getPermissionsTeamId())->toBe(42);
});

// ── sequential operations remain isolated ─────────────

test('context isolation between sequential assignTeamScopedRole calls after failure', function () {
    // First call fails with nonexistent role
    try {
        $this->service->assignTeamScopedRole($this->user, 'Nonexistent Role', $this->team);
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        // Expected
    }

    // Context should be clean for the next operation
    expect(getPermissionsTeamId())->toBeNull();

    // Second call succeeds
    $this->service->assignTeamScopedRole($this->user, 'Team Admin', $this->team);

    expect(getPermissionsTeamId())->toBeNull();
    expect($this->service->hasTeamPermission($this->user, 'update team', $this->team))->toBeTrue();
});

test('context isolation between sequential assignEventScopedRole calls after failure', function () {
    // First call fails
    try {
        $this->service->assignEventScopedRole($this->user, 'Nonexistent Role', $this->event);
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        // Expected
    }

    expect(getPermissionsTeamId())->toBeNull();

    // Second call succeeds
    $this->service->assignEventScopedRole($this->user, 'Event Admin', $this->event);

    expect(getPermissionsTeamId())->toBeNull();
    expect($this->service->hasEventPermission($this->user, 'update event', $this->event))->toBeTrue();
});

afterEach(function () {
    Mockery::close();
    // Always clean up global state
    setPermissionsTeamId(null);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
});

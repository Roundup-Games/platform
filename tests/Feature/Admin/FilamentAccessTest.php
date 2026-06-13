<?php

use App\Models\Event;
use App\Models\Team;
use App\Models\User;
use App\Services\ScopedRoleService;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    seedRoles();

    $this->platformAdmin = User::factory()->create();
    $this->gamesAdmin = User::factory()->create();
    $this->teamAdmin = User::factory()->create();
    $this->eventAdmin = User::factory()->create();
    $this->regularUser = User::factory()->create();

    // Assign global roles (at null context for true global assignment)
    setPermissionsTeamId(null);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->platformAdmin->assignRole('Platform Admin');
    $this->platformAdmin->unsetRelations();

    $this->gamesAdmin->assignRole('Games Admin');
    $this->gamesAdmin->unsetRelations();

    // Assign scoped roles
    $service = app(ScopedRoleService::class);
    $team = Team::factory()->create(['is_active' => true, 'created_by' => $this->teamAdmin->id]);
    $service->assignTeamScopedRole($this->teamAdmin, 'Team Admin', $team);

    $event = Event::factory()->create(['organizer_id' => $this->eventAdmin->id, 'is_public' => true]);
    $service->assignEventScopedRole($this->eventAdmin, 'Event Admin', $event);
});

describe('Admin Panel Authentication', function () {
    test('guest is redirected from admin panel', function () {
        $response = $this->get('/admin');
        $response->assertRedirect();
    })->group('smoke');

    test('Platform Admin can access admin panel', function () {
        $this->actingAs($this->platformAdmin);
        $response = $this->get('/admin');
        $response->assertSuccessful();
    })->group('smoke');

    test('Games Admin can access admin panel', function () {
        $this->actingAs($this->gamesAdmin);
        $response = $this->get('/admin');
        $response->assertSuccessful();
    })->group('smoke');

    test('regular user cannot access admin panel', function () {
        $this->actingAs($this->regularUser);
        $response = $this->get('/admin');
        $response->assertForbidden();
    })->group('smoke');

    test('Team Admin cannot access admin panel', function () {
        $this->actingAs($this->teamAdmin);
        $response = $this->get('/admin');
        $response->assertForbidden();
    })->group('smoke');

    test('Event Admin cannot access admin panel', function () {
        $this->actingAs($this->eventAdmin);
        $response = $this->get('/admin');
        $response->assertForbidden();
    })->group('smoke');
});

describe('Platform Login & Custom 403', function () {
    test('guest is redirected to platform login, not admin login', function () {
        $response = $this->get('/admin');
        $response->assertRedirect();
        // Should redirect to the platform login route, not /admin/login
        expect($response->headers->get('Location'))->not->toContain('/admin/login');
        expect($response->headers->get('Location'))->toContain('login');
    })->group('smoke');

    test('unauthorized user sees custom 403 page with dashboard link', function () {
        $this->actingAs($this->regularUser);
        $response = $this->get('/admin');
        $response->assertForbidden();
        $response->assertSee('Not Authorized');
        $response->assertSee('Return to Dashboard');
    })->group('smoke');

});

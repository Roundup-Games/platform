<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    seedRoles();

    setPermissionsTeamId(null);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('Platform Admin');
    $this->platformAdmin->unsetRelations();

    $this->serviceAdmin = User::factory()->create();
    $this->serviceAdmin->assignRole('Service Admin');
    $this->serviceAdmin->unsetRelations();

    $this->gamesAdmin = User::factory()->create();
    $this->gamesAdmin->assignRole('Games Admin');
    $this->gamesAdmin->unsetRelations();

    $this->regularUser = User::factory()->create();
});

// ── Service Admin ────────────────────────────────────

describe('Service Admin', function () {
    test('can access Filament admin panel', function () {
        $panel = Filament::getPanel('admin');
        expect($this->serviceAdmin->canAccessPanel($panel))->toBeTrue();
    });

    test('has manage tickets permission', function () {
        expect($this->serviceAdmin->hasPermissionTo('manage tickets'))->toBeTrue();
    });

    test('has view dashboard permission', function () {
        expect($this->serviceAdmin->hasPermissionTo('view dashboard'))->toBeTrue();
    });

    test('has view user permission', function () {
        expect($this->serviceAdmin->hasPermissionTo('view user'))->toBeTrue();
    });

    test('passes escalated-agent gate', function () {
        expect(Gate::forUser($this->serviceAdmin)->allows('escalated-agent'))->toBeTrue();
    });

    test('does not pass escalated-admin gate', function () {
        expect(Gate::forUser($this->serviceAdmin)->allows('escalated-admin'))->toBeFalse();
    });

    test('cannot delete games', function () {
        expect($this->serviceAdmin->hasPermissionTo('delete game'))->toBeFalse();
    });

    test('cannot manage settings', function () {
        expect($this->serviceAdmin->hasPermissionTo('manage settings'))->toBeFalse();
    });

    test('cannot manage roles', function () {
        expect($this->serviceAdmin->hasPermissionTo('manage roles'))->toBeFalse();
    });

    test('cannot view audit log', function () {
        expect($this->serviceAdmin->hasPermissionTo('view audit log'))->toBeFalse();
    });
});

// ── Games Admin ──────────────────────────────────────

describe('Games Admin', function () {
    test('can access Filament admin panel', function () {
        $panel = Filament::getPanel('admin');
        expect($this->gamesAdmin->canAccessPanel($panel))->toBeTrue();
    });

    test('has view dashboard permission', function () {
        expect($this->gamesAdmin->hasPermissionTo('view dashboard'))->toBeTrue();
    });

    test('can manage games', function () {
        expect($this->gamesAdmin->hasPermissionTo('create game'))->toBeTrue();
        expect($this->gamesAdmin->hasPermissionTo('update game'))->toBeTrue();
        expect($this->gamesAdmin->hasPermissionTo('delete game'))->toBeTrue();
    });

    test('does not have manage tickets permission', function () {
        expect($this->gamesAdmin->hasPermissionTo('manage tickets'))->toBeFalse();
    });

    test('does not pass escalated-agent gate', function () {
        expect(Gate::forUser($this->gamesAdmin)->allows('escalated-agent'))->toBeFalse();
    });

    test('does not pass escalated-admin gate', function () {
        expect(Gate::forUser($this->gamesAdmin)->allows('escalated-admin'))->toBeFalse();
    });
});

// ── Platform Admin ───────────────────────────────────

describe('Platform Admin', function () {
    test('can access Filament admin panel', function () {
        $panel = Filament::getPanel('admin');
        expect($this->platformAdmin->canAccessPanel($panel))->toBeTrue();
    });

    test('has all permissions including manage tickets', function () {
        expect($this->platformAdmin->hasPermissionTo('manage tickets'))->toBeTrue();
        expect($this->platformAdmin->hasPermissionTo('manage settings'))->toBeTrue();
        expect($this->platformAdmin->hasPermissionTo('manage roles'))->toBeTrue();
        expect($this->platformAdmin->hasPermissionTo('view audit log'))->toBeTrue();
        expect($this->platformAdmin->hasPermissionTo('delete game'))->toBeTrue();
    });

    test('passes escalated-agent gate', function () {
        expect(Gate::forUser($this->platformAdmin)->allows('escalated-agent'))->toBeTrue();
    });

    test('passes escalated-admin gate', function () {
        expect(Gate::forUser($this->platformAdmin)->allows('escalated-admin'))->toBeTrue();
    });
});

// ── Regular User ─────────────────────────────────────

describe('Regular User', function () {
    test('cannot access Filament admin panel', function () {
        $panel = Filament::getPanel('admin');
        expect($this->regularUser->canAccessPanel($panel))->toBeFalse();
    });

    test('does not pass escalated-agent gate', function () {
        expect(Gate::forUser($this->regularUser)->allows('escalated-agent'))->toBeFalse();
    });

    test('does not pass escalated-admin gate', function () {
        expect(Gate::forUser($this->regularUser)->allows('escalated-admin'))->toBeFalse();
    });

    test('does not have manage tickets permission', function () {
        expect($this->regularUser->hasPermissionTo('manage tickets'))->toBeFalse();
    });
});

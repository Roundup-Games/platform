<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/*
 * M077/S01 consolidation: 24 one-assertion permission tests → 4 flow tests
 * (one per role). The shared beforeEach world is unchanged; every original
 * assertion is preserved verbatim inside its role's flow, with the original
 * test name carried as a comment per check.
 */

beforeEach(function () {
    seedRoles();

    setPermissionsTeamId(null);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

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

describe('Service Admin', function () {
    test('service admin permission matrix (flow)', function () {
        $panel = Filament::getPanel('admin');

        // can access Filament admin panel
        expect($this->serviceAdmin->canAccessPanel($panel))->toBeTrue();
        // has manage tickets permission
        expect($this->serviceAdmin->hasPermissionTo('manage tickets'))->toBeTrue();
        // has view dashboard permission
        expect($this->serviceAdmin->hasPermissionTo('view dashboard'))->toBeTrue();
        // has view user permission
        expect($this->serviceAdmin->hasPermissionTo('view user'))->toBeTrue();
        // passes escalated-agent gate
        expect(Gate::forUser($this->serviceAdmin)->allows('escalated-agent'))->toBeTrue();
        // does not pass escalated-admin gate
        expect(Gate::forUser($this->serviceAdmin)->allows('escalated-admin'))->toBeFalse();
        // cannot delete games
        expect($this->serviceAdmin->hasPermissionTo('delete game'))->toBeFalse();
        // cannot manage settings
        expect($this->serviceAdmin->hasPermissionTo('manage settings'))->toBeFalse();
        // cannot manage roles
        expect($this->serviceAdmin->hasPermissionTo('manage roles'))->toBeFalse();
        // cannot view audit log
        expect($this->serviceAdmin->hasPermissionTo('view audit log'))->toBeFalse();
    });
});

describe('Games Admin', function () {
    test('games admin permission matrix (flow)', function () {
        $panel = Filament::getPanel('admin');

        // can access Filament admin panel
        expect($this->gamesAdmin->canAccessPanel($panel))->toBeTrue();
        // has view dashboard permission
        expect($this->gamesAdmin->hasPermissionTo('view dashboard'))->toBeTrue();
        // can manage games (create/update/delete)
        expect($this->gamesAdmin->hasPermissionTo('create game'))->toBeTrue();
        expect($this->gamesAdmin->hasPermissionTo('update game'))->toBeTrue();
        expect($this->gamesAdmin->hasPermissionTo('delete game'))->toBeTrue();
        // does not have manage tickets permission
        expect($this->gamesAdmin->hasPermissionTo('manage tickets'))->toBeFalse();
        // does not pass escalated-agent gate
        expect(Gate::forUser($this->gamesAdmin)->allows('escalated-agent'))->toBeFalse();
        // does not pass escalated-admin gate
        expect(Gate::forUser($this->gamesAdmin)->allows('escalated-admin'))->toBeFalse();
    });
});

describe('Platform Admin', function () {
    test('platform admin permission matrix (flow)', function () {
        $panel = Filament::getPanel('admin');

        // can access Filament admin panel
        expect($this->platformAdmin->canAccessPanel($panel))->toBeTrue();
        // has all permissions including manage tickets
        expect($this->platformAdmin->hasPermissionTo('manage tickets'))->toBeTrue();
        expect($this->platformAdmin->hasPermissionTo('manage settings'))->toBeTrue();
        expect($this->platformAdmin->hasPermissionTo('manage roles'))->toBeTrue();
        expect($this->platformAdmin->hasPermissionTo('view audit log'))->toBeTrue();
        expect($this->platformAdmin->hasPermissionTo('delete game'))->toBeTrue();
        // passes escalated-agent gate
        expect(Gate::forUser($this->platformAdmin)->allows('escalated-agent'))->toBeTrue();
        // passes escalated-admin gate
        expect(Gate::forUser($this->platformAdmin)->allows('escalated-admin'))->toBeTrue();
    });
});

describe('Regular User', function () {
    test('regular user permission matrix (flow)', function () {
        $panel = Filament::getPanel('admin');

        // cannot access Filament admin panel
        expect($this->regularUser->canAccessPanel($panel))->toBeFalse();
        // does not pass escalated-agent gate
        expect(Gate::forUser($this->regularUser)->allows('escalated-agent'))->toBeFalse();
        // does not pass escalated-admin gate
        expect(Gate::forUser($this->regularUser)->allows('escalated-admin'))->toBeFalse();
        // does not have manage tickets permission
        expect($this->regularUser->hasPermissionTo('manage tickets'))->toBeFalse();
    });
});

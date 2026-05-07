<?php

use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    seedPermissions();
    seedRoles();
    setPermissionsTeamId(1);

    $this->admin = User::factory()->create();
    $this->gamesAdmin = User::factory()->create();
    $this->regularUser = User::factory()->create();

    $this->admin->assignRole('Platform Admin');
    $this->admin->unsetRelations();

    $this->gamesAdmin->assignRole('Games Admin');
    $this->gamesAdmin->unsetRelations();

    $this->gameSystem = GameSystem::factory()->create();

    setPermissionsTeamId(1);
});

describe('GameSystemPolicy', function () {
    describe('before() — global admin bypass', function () {
        test('Platform Admin can do anything', function () {
            $this->actingAs($this->admin);
            expect(Gate::allows('viewAny', GameSystem::class))->toBeTrue();
            expect(Gate::allows('view', $this->gameSystem))->toBeTrue();
            expect(Gate::allows('create', GameSystem::class))->toBeTrue();
            expect(Gate::allows('update', $this->gameSystem))->toBeTrue();
            expect(Gate::allows('delete', $this->gameSystem))->toBeTrue();
        });
    });

    describe('viewAny', function () {
        test('guest can view any game system', function () {
            expect(Gate::allows('viewAny', GameSystem::class))->toBeTrue();
        });

        test('authenticated user can view any game system', function () {
            $this->actingAs($this->regularUser);
            expect(Gate::allows('viewAny', GameSystem::class))->toBeTrue();
        });
    });

    describe('view', function () {
        test('guest can view a game system', function () {
            expect(Gate::allows('view', $this->gameSystem))->toBeTrue();
        });

        test('authenticated user can view a game system', function () {
            $this->actingAs($this->regularUser);
            expect(Gate::allows('view', $this->gameSystem))->toBeTrue();
        });
    });

    describe('create', function () {
        test('Games Admin can create a game system', function () {
            $this->actingAs($this->gamesAdmin);
            expect(Gate::allows('create', GameSystem::class))->toBeTrue();
        });

        test('regular user cannot create a game system', function () {
            $this->actingAs($this->regularUser);
            expect(Gate::allows('create', GameSystem::class))->toBeFalse();
        });
    });

    describe('update', function () {
        test('Games Admin can update a game system', function () {
            $this->actingAs($this->gamesAdmin);
            expect(Gate::allows('update', $this->gameSystem))->toBeTrue();
        });

        test('regular user cannot update a game system', function () {
            $this->actingAs($this->regularUser);
            expect(Gate::allows('update', $this->gameSystem))->toBeFalse();
        });
    });

    describe('delete', function () {
        test('Platform Admin can delete a game system', function () {
            $this->actingAs($this->admin);
            expect(Gate::allows('delete', $this->gameSystem))->toBeTrue();
        });

        test('Games Admin can delete a game system (is global admin)', function () {
            $this->actingAs($this->gamesAdmin);
            expect(Gate::allows('delete', $this->gameSystem))->toBeTrue();
        });

        test('regular user cannot delete a game system', function () {
            $this->actingAs($this->regularUser);
            expect(Gate::allows('delete', $this->gameSystem))->toBeFalse();
        });
    });

    describe('requestNew', function () {
        test('authenticated user can request a new game system', function () {
            $this->actingAs($this->regularUser);
            expect(Gate::allows('requestNew', GameSystem::class))->toBeTrue();
        });

        test('guest cannot request a new game system', function () {
            expect(Gate::allows('requestNew', GameSystem::class))->toBeFalse();
        });
    });
});

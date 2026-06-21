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
        test('guests and authenticated users can viewAny', function (string $role) {
            if ($role === 'authenticated') {
                $this->actingAs($this->regularUser);
            }
            expect(Gate::allows('viewAny', GameSystem::class))->toBeTrue();
        })->with([
            'guest',
            'authenticated',
        ]);
    });

    describe('view', function () {
        test('guests and authenticated users can view a game system', function (string $role) {
            if ($role === 'authenticated') {
                $this->actingAs($this->regularUser);
            }
            expect(Gate::allows('view', $this->gameSystem))->toBeTrue();
        })->with([
            'guest',
            'authenticated',
        ]);
    });

    describe('create', function () {
        test('create is gated by Games Admin role', function (string $role, bool $expected) {
            $this->actingAs($role === 'games-admin' ? $this->gamesAdmin : $this->regularUser);
            expect(Gate::allows('create', GameSystem::class))->toBe($expected);
        })->with([
            'Games Admin can create a game system' => ['games-admin', true],
            'regular user cannot create a game system' => ['regular', false],
        ]);
    });

    describe('update', function () {
        test('update is gated by Games Admin role', function (string $role, bool $expected) {
            $this->actingAs($role === 'games-admin' ? $this->gamesAdmin : $this->regularUser);
            expect(Gate::allows('update', $this->gameSystem))->toBe($expected);
        })->with([
            'Games Admin can update a game system' => ['games-admin', true],
            'regular user cannot update a game system' => ['regular', false],
        ]);
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
        test('requestNew gates by authentication', function (string $role) {
            if ($role === 'authenticated') {
                $this->actingAs($this->regularUser);
            }
            expect(Gate::allows('requestNew', GameSystem::class))->toBe($role === 'authenticated');
        })->with([
            'authenticated',
            'guest',
        ]);
    });
});

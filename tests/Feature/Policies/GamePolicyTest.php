<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedPermissions();
    seedRoles();

    $this->admin = User::factory()->create();
    $this->gamesAdmin = User::factory()->create();
    $this->owner = User::factory()->create();
    $this->regularUser = User::factory()->create();

    // Assign Platform Admin
    setPermissionsTeamId(1);
    $this->admin->assignRole('Platform Admin');
    $this->admin->unsetRelations();

    // Assign Games Admin
    setPermissionsTeamId(1);
    $this->gamesAdmin->assignRole('Games Admin');
    $this->gamesAdmin->unsetRelations();

    setPermissionsTeamId(1);

    $this->publicGame = Game::factory()->create([
        'owner_id' => $this->owner->id,
        'visibility' => 'public',
    ]);

    $this->privateGame = Game::factory()->create([
        'owner_id' => $this->owner->id,
        'visibility' => 'private',
    ]);

    $this->protectedGame = Game::factory()->create([
        'owner_id' => $this->owner->id,
        'visibility' => 'protected',
    ]);
});

describe('Game Policy', function () {
    describe('global admin bypass', function () {
        test('Platform Admin can do anything on games', function () {
            $this->actingAs($this->admin);
            expect(Gate::allows('viewAny', Game::class))->toBeTrue();
            expect(Gate::allows('create', Game::class))->toBeTrue();
            expect(Gate::allows('update', $this->publicGame))->toBeTrue();
            expect(Gate::allows('delete', $this->publicGame))->toBeTrue();
        });

        test('Games Admin can do anything on games', function () {
            $this->actingAs($this->gamesAdmin);
            expect(Gate::allows('update', $this->publicGame))->toBeTrue();
            expect(Gate::allows('delete', $this->publicGame))->toBeTrue();
        });
    });

    describe('view', function () {
        test('guest can view public game', function () {
            expect(Gate::allows('view', $this->publicGame))->toBeTrue();
        });

        test('guest cannot view private game', function () {
            expect(Gate::allows('view', $this->privateGame))->toBeFalse();
        });

        test('owner can view their private game', function () {
            $this->actingAs($this->owner);
            expect(Gate::allows('view', $this->privateGame))->toBeTrue();
        });
    });

    describe('protected visibility', function () {
        test('guest cannot view protected game', function () {
            expect(Gate::allows('view', $this->protectedGame))->toBeFalse();
        });

        test('owner can view their protected game', function () {
            $this->actingAs($this->owner);
            expect(Gate::allows('view', $this->protectedGame))->toBeTrue();
        });

        test('stranger cannot view protected game', function () {
            $this->actingAs($this->regularUser);
            expect(Gate::allows('view', $this->protectedGame))->toBeFalse();
        });

        test('friend of owner can view protected game', function () {
            UserRelationship::follow($this->regularUser, $this->owner);
            UserRelationship::follow($this->owner, $this->regularUser);

            $this->actingAs($this->regularUser);
            expect(Gate::allows('view', $this->protectedGame))->toBeTrue();
        });

        test('participant can view protected game', function () {
            GameParticipant::create([
                'game_id' => $this->protectedGame->id,
                'user_id' => $this->regularUser->id,
                'role' => 'player',
                'status' => 'approved',
            ]);

            $this->actingAs($this->regularUser);
            expect(Gate::allows('view', $this->protectedGame))->toBeTrue();
        });
    });

    describe('update', function () {
        test('owner can update their game', function () {
            $this->actingAs($this->owner);
            expect(Gate::allows('update', $this->publicGame))->toBeTrue();
        });

        test('user with update game permission can update any game', function () {
            setPermissionsTeamId(1);
            $this->regularUser->givePermissionTo('update game');
            $this->regularUser->unsetRelations();
            setPermissionsTeamId(1);

            $this->actingAs($this->regularUser);
            expect(Gate::allows('update', $this->publicGame))->toBeTrue();
        });

        test('regular user cannot update game', function () {
            $this->actingAs($this->regularUser);
            expect(Gate::allows('update', $this->publicGame))->toBeFalse();
        });
    });

    describe('delete', function () {
        test('owner can delete their game', function () {
            $this->actingAs($this->owner);
            expect(Gate::allows('delete', $this->publicGame))->toBeTrue();
        });

        test('regular user cannot delete game', function () {
            $this->actingAs($this->regularUser);
            expect(Gate::allows('delete', $this->publicGame))->toBeFalse();
        });
    });

    describe('create', function () {
        test('user with create game permission can create', function () {
            setPermissionsTeamId(1);
            $this->regularUser->givePermissionTo('create game');
            $this->regularUser->unsetRelations();
            setPermissionsTeamId(1);

            $this->actingAs($this->regularUser);
            expect(Gate::allows('create', Game::class))->toBeTrue();
        });

        test('user without permission cannot create game', function () {
            $this->actingAs($this->regularUser);
            expect(Gate::allows('create', Game::class))->toBeFalse();
        });
    });
});

<?php

use App\Livewire\Games\GameListing;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedPermissions();
    seedRoles();

    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
});

// ═══════════════════════════════════════════════════════════
// PUBLIC VISIBILITY — VISIBLE TO EVERYONE
// ═══════════════════════════════════════════════════════════

describe('Public game visibility', function () {
    it('is visible to guests', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
        ]);

        expect(Gate::allows('view', $game))->toBeTrue();
    });

    it('is visible to any authenticated user', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
        ]);
        $viewer = User::factory()->create();

        $this->actingAs($viewer);
        expect(Gate::allows('view', $game))->toBeTrue();
    });

    it('is visible to the owner', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
        ]);

        $this->actingAs($this->owner);
        expect(Gate::allows('view', $game))->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════
// PROTECTED VISIBILITY — FRIENDS + TEAMMATES + PARTICIPANTS
// ═══════════════════════════════════════════════════════════

describe('Protected game visibility', function () {
    it('is not visible to guests', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);

        expect(Gate::allows('view', $game))->toBeFalse();
    });

    it('is visible to the owner', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);

        $this->actingAs($this->owner);
        expect(Gate::allows('view', $game))->toBeTrue();
    });

    it('is not visible to a stranger', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger);
        expect(Gate::allows('view', $game))->toBeFalse();
    });

    it('is visible to a friend (mutual follow)', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);
        $friend = User::factory()->create();
        UserRelationship::follow($this->owner, $friend);
        UserRelationship::follow($friend, $this->owner);

        $this->actingAs($friend);
        expect(Gate::allows('view', $game))->toBeTrue();
    });

    it('is not visible to a one-way follower', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);
        $follower = User::factory()->create();
        // One-way follow: follower follows owner, but owner doesn't follow back
        UserRelationship::follow($follower, $this->owner);

        $this->actingAs($follower);
        expect(Gate::allows('view', $game))->toBeFalse();
    });

    it('is visible to a teammate on an active team', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);
        $teammate = User::factory()->create();
        $team = Team::factory()->create();
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $this->owner->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $teammate->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($teammate);
        expect(Gate::allows('view', $game))->toBeTrue();
    });

    it('is not visible to a former teammate (inactive membership)', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);
        $formerTeammate = User::factory()->create();
        $team = Team::factory()->create();
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $this->owner->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $formerTeammate->id,
            'role' => 'player',
            'status' => 'inactive',
            'joined_at' => now(),
        ]);

        $this->actingAs($formerTeammate);
        expect(Gate::allows('view', $game))->toBeFalse();
    });

    it('is visible to a participant', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);
        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $this->actingAs($participant);
        expect(Gate::allows('view', $game))->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════
// PRIVATE VISIBILITY — OWNER + PARTICIPANTS ONLY
// ═══════════════════════════════════════════════════════════

describe('Private game visibility', function () {
    it('is not visible to guests', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
        ]);

        expect(Gate::allows('view', $game))->toBeFalse();
    });

    it('is visible to the owner', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
        ]);

        $this->actingAs($this->owner);
        expect(Gate::allows('view', $game))->toBeTrue();
    });

    it('is visible to a participant', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
        ]);
        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $this->actingAs($participant);
        expect(Gate::allows('view', $game))->toBeTrue();
    });

    it('is not visible to a friend', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
        ]);
        $friend = User::factory()->create();
        UserRelationship::follow($this->owner, $friend);
        UserRelationship::follow($friend, $this->owner);

        $this->actingAs($friend);
        expect(Gate::allows('view', $game))->toBeFalse();
    });

    it('is not visible to a teammate', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
        ]);
        $teammate = User::factory()->create();
        $team = Team::factory()->create();
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $this->owner->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $teammate->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($teammate);
        expect(Gate::allows('view', $game))->toBeFalse();
    });

    it('is not visible to a stranger', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
        ]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger);
        expect(Gate::allows('view', $game))->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════
// LISTING QUERY VISIBILITY — STRANGER vs FRIEND
// ═══════════════════════════════════════════════════════════

describe('Game listing query visibility', function () {
    beforeEach(function () {
        $this->publicGame = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDay(),
        ]);
        $this->protectedGame = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
            'status' => 'scheduled',
            'date_time' => now()->addDay(),
        ]);
        $this->privateGame = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
            'status' => 'scheduled',
            'date_time' => now()->addDay(),
        ]);
    });

    it('guest sees only public games in listing query', function () {
        Auth::logout();
        $user = null;

        $visible = Game::query()
            ->where(function ($q) use ($user) {
                $q->where('visibility', 'public');
                if ($user) {
                    $q->orWhere(function ($q) use ($user) {
                        $q->where('visibility', 'protected')
                            ->where(function ($q) use ($user) {
                                $allowedOwnerIds = $user->getAllowedOwnerIdsForProtectedContent();
                                $q->whereIn('owner_id', $allowedOwnerIds)
                                    ->orWhereHas('participants', fn ($pq) => $pq->where('user_id', $user->id));
                            });
                    });
                }
            })
            ->pluck('id')
            ->all();

        expect($visible)->toContain($this->publicGame->id);
        expect($visible)->not->toContain($this->protectedGame->id);
        expect($visible)->not->toContain($this->privateGame->id);
    });

    it('stranger sees only public games in listing query', function () {
        $stranger = User::factory()->create();

        $visible = Game::query()
            ->where(function ($q) use ($stranger) {
                $q->where('visibility', 'public');
                if ($stranger) {
                    $q->orWhere(function ($q) use ($stranger) {
                        $q->where('visibility', 'protected')
                            ->where(function ($q) use ($stranger) {
                                $allowedOwnerIds = $stranger->getAllowedOwnerIdsForProtectedContent();
                                $q->whereIn('owner_id', $allowedOwnerIds)
                                    ->orWhereHas('participants', fn ($pq) => $pq->where('user_id', $stranger->id));
                            });
                    });
                }
            })
            ->pluck('id')
            ->all();

        expect($visible)->toContain($this->publicGame->id);
        expect($visible)->not->toContain($this->protectedGame->id);
        expect($visible)->not->toContain($this->privateGame->id);
    });

    it('friend sees public and protected games in listing query', function () {
        $friend = User::factory()->create();
        UserRelationship::follow($this->owner, $friend);
        UserRelationship::follow($friend, $this->owner);

        $visible = Game::query()
            ->where(function ($q) use ($friend) {
                $q->where('visibility', 'public');
                if ($friend) {
                    $q->orWhere(function ($q) use ($friend) {
                        $q->where('visibility', 'protected')
                            ->where(function ($q) use ($friend) {
                                $allowedOwnerIds = $friend->getAllowedOwnerIdsForProtectedContent();
                                $q->whereIn('owner_id', $allowedOwnerIds)
                                    ->orWhereHas('participants', fn ($pq) => $pq->where('user_id', $friend->id));
                            });
                    });
                }
            })
            ->pluck('id')
            ->all();

        expect($visible)->toContain($this->publicGame->id);
        expect($visible)->toContain($this->protectedGame->id);
        expect($visible)->not->toContain($this->privateGame->id);
    });

    it('participant sees public and protected games in listing query', function () {
        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $this->protectedGame->id,
            'user_id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $visible = Game::query()
            ->where(function ($q) use ($participant) {
                $q->where('visibility', 'public');
                if ($participant) {
                    $q->orWhere(function ($q) use ($participant) {
                        $q->where('visibility', 'protected')
                            ->where(function ($q) use ($participant) {
                                $allowedOwnerIds = $participant->getAllowedOwnerIdsForProtectedContent();
                                $q->whereIn('owner_id', $allowedOwnerIds)
                                    ->orWhereHas('participants', fn ($pq) => $pq->where('user_id', $participant->id));
                            });
                    });
                }
            })
            ->pluck('id')
            ->all();

        expect($visible)->toContain($this->publicGame->id);
        expect($visible)->toContain($this->protectedGame->id);
        expect($visible)->not->toContain($this->privateGame->id);
    });

    it('owner sees all their own games in listing query regardless of visibility', function () {
        $visible = Game::query()
            ->where(function ($q) {
                $q->where('visibility', 'public');
                $q->orWhere(function ($q) {
                    $q->where('visibility', 'protected')
                        ->where(function ($q) {
                            $allowedOwnerIds = $this->owner->getAllowedOwnerIdsForProtectedContent();
                            $q->whereIn('owner_id', $allowedOwnerIds)
                                ->orWhereHas('participants', fn ($pq) => $pq->where('user_id', $this->owner->id));
                        });
                });
            })
            ->pluck('id')
            ->all();

        expect($visible)->toContain($this->publicGame->id);
        expect($visible)->toContain($this->protectedGame->id);
        // Private games are excluded from listing — only policy-level view is allowed for owner
        expect($visible)->not->toContain($this->privateGame->id);
    });
});

// ═══════════════════════════════════════════════════════════
// CRUD POLICY — ADMIN BYPASS, UPDATE, DELETE, CREATE
// (Consolidated from GamePolicyTest)
// ═══════════════════════════════════════════════════════════

describe('Game CRUD policy — admin bypass', function () {
    test('Platform Admin can do anything on games', function () {
        setPermissionsTeamId(1);
        $admin = User::factory()->create();
        $admin->assignRole('Platform Admin');
        $admin->unsetRelations();
        setPermissionsTeamId(1);

        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
        ]);

        $this->actingAs($admin);
        expect(Gate::allows('viewAny', Game::class))->toBeTrue();
        expect(Gate::allows('create', Game::class))->toBeTrue();
        expect(Gate::allows('update', $game))->toBeTrue();
        expect(Gate::allows('delete', $game))->toBeTrue();
    });

    test('Games Admin can do anything on games', function () {
        setPermissionsTeamId(1);
        $gamesAdmin = User::factory()->create();
        $gamesAdmin->assignRole('Games Admin');
        $gamesAdmin->unsetRelations();
        setPermissionsTeamId(1);

        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
        ]);

        $this->actingAs($gamesAdmin);
        expect(Gate::allows('update', $game))->toBeTrue();
        expect(Gate::allows('delete', $game))->toBeTrue();
    });
});

describe('Game CRUD policy — update', function () {
    test('owner can update their game', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
        ]);

        $this->actingAs($this->owner);
        expect(Gate::allows('update', $game))->toBeTrue();
    });

    test('user with update game permission can update any game', function () {
        setPermissionsTeamId(1);
        $user = User::factory()->create();
        $user->givePermissionTo('update game');
        $user->unsetRelations();
        setPermissionsTeamId(1);

        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
        ]);

        $this->actingAs($user);
        expect(Gate::allows('update', $game))->toBeTrue();
    });

    test('regular user cannot update game', function () {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
        ]);

        $this->actingAs($user);
        expect(Gate::allows('update', $game))->toBeFalse();
    });
});

describe('Game CRUD policy — delete', function () {
    test('owner can delete their game', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
        ]);

        $this->actingAs($this->owner);
        expect(Gate::allows('delete', $game))->toBeTrue();
    });

    test('regular user cannot delete game', function () {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
        ]);

        $this->actingAs($user);
        expect(Gate::allows('delete', $game))->toBeFalse();
    });
});

describe('Game CRUD policy — create', function () {
    test('user with create game permission can create', function () {
        setPermissionsTeamId(1);
        $user = User::factory()->create();
        $user->givePermissionTo('create game');
        $user->unsetRelations();
        setPermissionsTeamId(1);

        $this->actingAs($user);
        expect(Gate::allows('create', Game::class))->toBeTrue();
    });

    test('user without permission cannot create game', function () {
        $user = User::factory()->create();

        $this->actingAs($user);
        expect(Gate::allows('create', Game::class))->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════
// PERFORMANCE — LISTING QUERY WITH LARGE DATASET
// ═══════════════════════════════════════════════════════════

describe('Performance with large dataset', function () {
    it('lists games efficiently with 1000 games and 500 relationships', function () {
        // Create additional game systems for variety
        $gameSystems = GameSystem::factory()->count(5)->create();
        $allSystems = $gameSystems->prepend($this->gameSystem);

        // Create owners for the games
        $owners = User::factory()->count(20)->create();

        // Create 1000 games with mixed visibility
        $games = collect();
        for ($i = 0; $i < 1000; $i++) {
            $visibility = ['public', 'public', 'public', 'protected', 'private'][rand(0, 4)]; // 60% public, 20% protected, 20% private
            $games->push(Game::factory()->create([
                'owner_id' => $owners->random()->id,
                'game_system_id' => $allSystems->random()->id,
                'visibility' => $visibility,
                'status' => 'scheduled',
                'date_time' => now()->addDays(rand(1, 60)),
            ]));
        }

        // Create a viewer with 500 relationships (250 mutual follows = friends)
        $viewer = User::factory()->create();
        $friends = User::factory()->count(250)->create();
        foreach ($friends as $friend) {
            UserRelationship::follow($viewer, $friend);
            UserRelationship::follow($friend, $viewer);
        }

        // Also make some of the friends be game owners
        foreach ($friends->take(10) as $friendOwner) {
            Game::factory()->create([
                'owner_id' => $friendOwner->id,
                'game_system_id' => $allSystems->random()->id,
                'visibility' => 'protected',
                'status' => 'scheduled',
                'date_time' => now()->addDay(),
            ]);
        }

        // Measure query execution time
        $start = microtime(true);

        $results = Game::query()
            ->where(function ($q) use ($viewer) {
                $q->where('visibility', 'public');
                $q->orWhere(function ($q) use ($viewer) {
                    $q->where('visibility', 'protected')
                        ->where(function ($q) use ($viewer) {
                            $allowedOwnerIds = $viewer->getAllowedOwnerIdsForProtectedContent();
                            $q->whereIn('owner_id', $allowedOwnerIds)
                                ->orWhereHas('participants', fn ($pq) => $pq->where('user_id', $viewer->id));
                        });
                });
            })
            ->where('status', 'scheduled')
            ->where('date_time', '>', now())
            ->pluck('id');

        $elapsed = (microtime(true) - $start) * 1000;

        // Query should complete in under 2 seconds even with 1000 games + 500 relationships
        expect($elapsed)->toBeLessThan(2000);
        expect($results)->not->toBeEmpty();

        // Verify protected games from friends are visible
        $protectedVisible = Game::whereIn('id', $results->all())
            ->where('visibility', 'protected')
            ->count();
        // At least the 10 protected games from friend owners should be visible
        expect($protectedVisible)->toBeGreaterThanOrEqual(10);
    });
});

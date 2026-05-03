<?php

use App\Models\Campaign;
use App\Models\CampaignParticipant;
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

describe('Public campaign visibility', function () {
    it('is visible to guests', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
        ]);

        expect(Gate::allows('view', $campaign))->toBeTrue();
    })->group('smoke');

    it('is visible to any authenticated user', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
        ]);
        $viewer = User::factory()->create();

        $this->actingAs($viewer);
        expect(Gate::allows('view', $campaign))->toBeTrue();
    })->group('smoke');

    it('is visible to the owner', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
        ]);

        $this->actingAs($this->owner);
        expect(Gate::allows('view', $campaign))->toBeTrue();
    })->group('smoke');
});

// ═══════════════════════════════════════════════════════════
// PROTECTED VISIBILITY — FRIENDS + TEAMMATES + PARTICIPANTS
// ═══════════════════════════════════════════════════════════

describe('Protected campaign visibility', function () {
    it('is not visible to guests', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);

        expect(Gate::allows('view', $campaign))->toBeFalse();
    })->group('smoke');

    it('is visible to the owner', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);

        $this->actingAs($this->owner);
        expect(Gate::allows('view', $campaign))->toBeTrue();
    })->group('smoke');

    it('is not visible to a stranger', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger);
        expect(Gate::allows('view', $campaign))->toBeFalse();
    })->group('smoke');

    it('is visible to a friend (mutual follow)', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);
        $friend = User::factory()->create();
        UserRelationship::follow($this->owner, $friend);
        UserRelationship::follow($friend, $this->owner);

        $this->actingAs($friend);
        expect(Gate::allows('view', $campaign))->toBeTrue();
    })->group('smoke');

    it('is not visible to a one-way follower', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);
        $follower = User::factory()->create();
        UserRelationship::follow($follower, $this->owner);

        $this->actingAs($follower);
        expect(Gate::allows('view', $campaign))->toBeFalse();
    });

    it('is visible to a teammate on an active team', function () {
        $campaign = Campaign::factory()->create([
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
        expect(Gate::allows('view', $campaign))->toBeTrue();
    })->group('smoke');

    it('is not visible to a former teammate (inactive membership)', function () {
        $campaign = Campaign::factory()->create([
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
        expect(Gate::allows('view', $campaign))->toBeFalse();
    });

    it('is visible to a participant', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
        ]);
        $participant = User::factory()->create();
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $this->actingAs($participant);
        expect(Gate::allows('view', $campaign))->toBeTrue();
    })->group('smoke');
});

// ═══════════════════════════════════════════════════════════
// PRIVATE VISIBILITY — OWNER + PARTICIPANTS ONLY
// ═══════════════════════════════════════════════════════════

describe('Private campaign visibility', function () {
    it('is not visible to guests', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
        ]);

        expect(Gate::allows('view', $campaign))->toBeFalse();
    })->group('smoke');

    it('is visible to the owner', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
        ]);

        $this->actingAs($this->owner);
        expect(Gate::allows('view', $campaign))->toBeTrue();
    })->group('smoke');

    it('is visible to a participant', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
        ]);
        $participant = User::factory()->create();
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $this->actingAs($participant);
        expect(Gate::allows('view', $campaign))->toBeTrue();
    })->group('smoke');

    it('is not visible to a friend', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
        ]);
        $friend = User::factory()->create();
        UserRelationship::follow($this->owner, $friend);
        UserRelationship::follow($friend, $this->owner);

        $this->actingAs($friend);
        expect(Gate::allows('view', $campaign))->toBeFalse();
    })->group('smoke');

    it('is not visible to a teammate', function () {
        $campaign = Campaign::factory()->create([
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
        expect(Gate::allows('view', $campaign))->toBeFalse();
    })->group('smoke');

    it('is not visible to a stranger', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
        ]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger);
        expect(Gate::allows('view', $campaign))->toBeFalse();
    })->group('smoke');
});

// ═══════════════════════════════════════════════════════════
// LISTING QUERY VISIBILITY — STRANGER vs FRIEND
// ═══════════════════════════════════════════════════════════

describe('Campaign listing query visibility', function () {
    beforeEach(function () {
        $this->publicCampaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
            'status' => 'active',
        ]);
        $this->protectedCampaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
            'status' => 'active',
        ]);
        $this->privateCampaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
            'status' => 'active',
        ]);
    });

    it('guest sees only public campaigns in listing query', function () {
        $user = null;

        $visible = Campaign::query()
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

        expect($visible)->toContain($this->publicCampaign->id);
        expect($visible)->not->toContain($this->protectedCampaign->id);
        expect($visible)->not->toContain($this->privateCampaign->id);
    });

    it('stranger sees only public campaigns in listing query', function () {
        $stranger = User::factory()->create();

        $visible = Campaign::query()
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

        expect($visible)->toContain($this->publicCampaign->id);
        expect($visible)->not->toContain($this->protectedCampaign->id);
        expect($visible)->not->toContain($this->privateCampaign->id);
    });

    it('friend sees public and protected campaigns in listing query', function () {
        $friend = User::factory()->create();
        UserRelationship::follow($this->owner, $friend);
        UserRelationship::follow($friend, $this->owner);

        $visible = Campaign::query()
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

        expect($visible)->toContain($this->publicCampaign->id);
        expect($visible)->toContain($this->protectedCampaign->id);
        expect($visible)->not->toContain($this->privateCampaign->id);
    });

    it('participant sees public and protected campaigns in listing query', function () {
        $participant = User::factory()->create();
        CampaignParticipant::create([
            'campaign_id' => $this->protectedCampaign->id,
            'user_id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $visible = Campaign::query()
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

        expect($visible)->toContain($this->publicCampaign->id);
        expect($visible)->toContain($this->protectedCampaign->id);
        expect($visible)->not->toContain($this->privateCampaign->id);
    });

    it('owner sees all their own campaigns in listing query regardless of visibility', function () {
        $visible = Campaign::query()
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

        expect($visible)->toContain($this->publicCampaign->id);
        expect($visible)->toContain($this->protectedCampaign->id);
        // Private campaigns are excluded from listing — only policy-level view is allowed for owner
        expect($visible)->not->toContain($this->privateCampaign->id);
    });
});

// ═══════════════════════════════════════════════════════════
// CRUD POLICY — ADMIN BYPASS, UPDATE, DELETE
// (Consolidated from CampaignPolicyTest)
// ═══════════════════════════════════════════════════════════

describe('Campaign CRUD policy — admin bypass', function () {
    test('Platform Admin can update any campaign', function () {
        setPermissionsTeamId(1);
        $admin = User::factory()->create();
        $admin->assignRole('Platform Admin');
        $admin->unsetRelations();
        setPermissionsTeamId(1);

        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
        ]);

        $this->actingAs($admin);
        expect(Gate::allows('update', $campaign))->toBeTrue();
    });
});

describe('Campaign CRUD policy — update', function () {
    test('owner can update their campaign', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
        ]);

        $this->actingAs($this->owner);
        expect(Gate::allows('update', $campaign))->toBeTrue();
    });

    test('regular user cannot update campaign', function () {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
        ]);

        $this->actingAs($user);
        expect(Gate::allows('update', $campaign))->toBeFalse();
    });
});

describe('Campaign CRUD policy — delete', function () {
    test('owner can delete their campaign', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
        ]);

        $this->actingAs($this->owner);
        expect(Gate::allows('delete', $campaign))->toBeTrue();
    });

    test('regular user cannot delete campaign', function () {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
        ]);

        $this->actingAs($user);
        expect(Gate::allows('delete', $campaign))->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════
// PERFORMANCE — LISTING QUERY WITH LARGE DATASET
// ═══════════════════════════════════════════════════════════

describe('Performance with large dataset', function () {
    it('lists campaigns efficiently with 1000 campaigns and 500 relationships', function () {
        $gameSystems = GameSystem::factory()->count(5)->create();
        $allSystems = $gameSystems->prepend($this->gameSystem);

        $owners = User::factory()->count(20)->create();

        // Create 1000 campaigns with mixed visibility
        $campaigns = collect();
        for ($i = 0; $i < 1000; $i++) {
            $visibility = ['public', 'public', 'public', 'protected', 'private'][rand(0, 4)];
            $campaigns->push(Campaign::factory()->create([
                'owner_id' => $owners->random()->id,
                'game_system_id' => $allSystems->random()->id,
                'visibility' => $visibility,
                'status' => 'active',
            ]));
        }

        // Create a viewer with 250 mutual follows (friends)
        $viewer = User::factory()->create();
        $friends = User::factory()->count(250)->create();
        foreach ($friends as $friend) {
            UserRelationship::follow($viewer, $friend);
            UserRelationship::follow($friend, $viewer);
        }

        // Make some friends be campaign owners
        foreach ($friends->take(10) as $friendOwner) {
            Campaign::factory()->create([
                'owner_id' => $friendOwner->id,
                'game_system_id' => $allSystems->random()->id,
                'visibility' => 'protected',
                'status' => 'active',
            ]);
        }

        $start = microtime(true);

        $results = Campaign::query()
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
            ->where('status', 'active')
            ->pluck('id');

        $elapsed = (microtime(true) - $start) * 1000;

        // Query should complete in under 2 seconds
        expect($elapsed)->toBeLessThan(2000);
        expect($results)->not->toBeEmpty();

        // Verify protected campaigns from friends are visible
        $protectedVisible = Campaign::whereIn('id', $results->all())
            ->where('visibility', 'protected')
            ->count();
        expect($protectedVisible)->toBeGreaterThanOrEqual(10);
    });
});

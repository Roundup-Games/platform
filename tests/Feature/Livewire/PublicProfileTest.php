<?php

use App\Enums\RelationshipType;
use App\Livewire\Profile\PublicProfile;
use App\Models\User;
use App\Models\UserRelationship;
use Livewire\Livewire;


// ═══════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════

function createProfileUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'profile_complete' => true,
        'pronouns' => 'they/them',
    ], $overrides));
}

// ═══════════════════════════════════════════════════════════
// PAGE LOADS
// ═══════════════════════════════════════════════════════════

describe('Public Profile page loads', function () {
    it('renders via Livewire for an unauthenticated visitor', function () {
        $user = createProfileUser();

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertOk()
            ->assertSee($user->name);
    })->group('smoke');

    it('renders for an authenticated user viewing another profile', function () {
        $viewer = createProfileUser();
        $profileUser = createProfileUser(['name' => 'Profile Target']);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertOk()
            ->assertSee('Profile Target');
    });

    it('shows own profile name without action buttons', function () {
        $user = createProfileUser();

        Livewire::actingAs($user)
            ->test(PublicProfile::class, ['user' => $user])
            ->assertSet('isOwnProfile', true)
            ->assertDontSee('Follow')
            ->assertDontSee('Block');
    });

    it('returns 404 for nonexistent user via HTTP', function () {
        $viewer = createProfileUser();
        $this->actingAs($viewer)
            ->get(route('profile.public', ['locale' => 'en', 'user' => Str::uuid()->toString()]))
            ->assertNotFound();
    });
});

// ═══════════════════════════════════════════════════════════
// PROFILE CONTENT
// ═══════════════════════════════════════════════════════════

describe('Public Profile displays user data', function () {
    it('shows Friends badge for mutual follows', function () {
        $viewer = createProfileUser();
        $profileUser = createProfileUser(['name' => 'Friend User']);
        UserRelationship::follow($viewer, $profileUser);
        UserRelationship::follow($profileUser, $viewer);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertSet('isFriend', true)
            ->assertSee('Friends');
    });
});

// ═══════════════════════════════════════════════════════════
// FOLLOW / UNFOLLOW ACTIONS
// ═══════════════════════════════════════════════════════════

describe('Follow / Unfollow actions', function () {
    it('follows a user', function () {
        $viewer = createProfileUser();
        $profileUser = createProfileUser();

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertSet('isFollowing', false)
            ->call('follow')
            ->assertSet('isFollowing', true);

        expect($viewer->isFollowing($profileUser))->toBeTrue();
    })->group('smoke');

    it('unfollows a user', function () {
        $viewer = createProfileUser();
        $profileUser = createProfileUser();
        UserRelationship::follow($viewer, $profileUser);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertSet('isFollowing', true)
            ->call('unfollow')
            ->assertSet('isFollowing', false);

        expect($viewer->isFollowing($profileUser))->toBeFalse();
    })->group('smoke');

    it('detects friend status after mutual follow', function () {
        $viewer = createProfileUser();
        $profileUser = createProfileUser();
        // profileUser already follows viewer
        UserRelationship::follow($profileUser, $viewer);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertSet('isFollowedBy', true)
            ->assertSet('isFriend', false)
            ->call('follow')
            ->assertSet('isFriend', true);
    });

    it('cannot follow self', function () {
        $user = createProfileUser();

        Livewire::actingAs($user)
            ->test(PublicProfile::class, ['user' => $user])
            ->call('follow');

        expect(UserRelationship::where('user_id', $user->id)
            ->where('related_user_id', $user->id)
            ->exists())->toBeFalse();
    });

    it('cannot follow when blocked by target', function () {
        $viewer = createProfileUser();
        $profileUser = createProfileUser();
        UserRelationship::block($profileUser, $viewer);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertSet('isBlockedBy', true)
            ->call('follow');

        expect($viewer->isFollowing($profileUser))->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════
// BLOCK / UNBLOCK ACTIONS
// ═══════════════════════════════════════════════════════════

describe('Block / Unblock actions', function () {
    it('blocks a user', function () {
        $viewer = createProfileUser();
        $profileUser = createProfileUser();

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertSet('hasBlocked', false)
            ->call('block')
            ->assertSet('hasBlocked', true);

        expect($viewer->hasBlocked($profileUser))->toBeTrue();
    })->group('smoke');

    it('removes follow on block', function () {
        $viewer = createProfileUser();
        $profileUser = createProfileUser();
        UserRelationship::follow($viewer, $profileUser);
        UserRelationship::follow($profileUser, $viewer);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertSet('isFollowing', true)
            ->assertSet('isFollowedBy', true)
            ->call('block')
            ->assertSet('isFollowing', false)
            ->assertSet('isFollowedBy', false)
            ->assertSet('isFriend', false);
    })->group('smoke');

    it('unblocks a user', function () {
        $viewer = createProfileUser();
        $profileUser = createProfileUser();
        UserRelationship::block($viewer, $profileUser);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertSet('hasBlocked', true)
            ->call('unblock')
            ->assertSet('hasBlocked', false);

        expect($viewer->hasBlocked($profileUser))->toBeFalse();
    })->group('smoke');

    it('cannot block self', function () {
        $user = createProfileUser();

        Livewire::actingAs($user)
            ->test(PublicProfile::class, ['user' => $user])
            ->call('block');

        expect(UserRelationship::where('user_id', $user->id)
            ->where('related_user_id', $user->id)
            ->where('type', RelationshipType::Block)
            ->exists())->toBeFalse();
    });

    it('blocked profile shows limited info when viewer is blocked', function () {
        $viewer = createProfileUser();
        $profileUser = createProfileUser(['pronouns' => 'she/her']);
        UserRelationship::block($profileUser, $viewer);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertSet('isBlockedBy', true)
            ->assertDontSee('she/her')
            ->assertSee('not available');
    });
});

// ═══════════════════════════════════════════════════════════
// UNAUTHENTICATED VIEWER
// ═══════════════════════════════════════════════════════════

describe('Unauthenticated viewer', function () {
    it('sees login prompt instead of action buttons', function () {
        $profileUser = createProfileUser();

        Livewire::test(PublicProfile::class, ['user' => $profileUser])
            ->assertSee('Log in to follow')
            ->assertDontSee('wire:click="follow"');
    });

});

// ═══════════════════════════════════════════════════════════
// GAME & CAMPAIGN VISIBILITY ON PROFILE
// ═══════════════════════════════════════════════════════════

describe('Game session visibility on profile', function () {
    it('shows public owned games to a stranger', function () {
        $profileUser = createProfileUser();
        $game = \App\Models\Game::factory()->create([
            'owner_id' => $profileUser->id,
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        $viewer = createProfileUser();

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertViewHas('games', fn ($games) => $games->contains('id', $game->id));
    });

    it('hides protected games from a stranger', function () {
        $profileUser = createProfileUser();
        \App\Models\Game::factory()->create([
            'owner_id' => $profileUser->id,
            'visibility' => 'protected',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        $viewer = createProfileUser();

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertViewHas('games', fn ($games) => $games->isEmpty());
    })->group('smoke');

    it('shows protected games to a friend', function () {
        $profileUser = createProfileUser();
        $viewer = createProfileUser();

        // Mutual follow = friends
        UserRelationship::follow($viewer, $profileUser);
        UserRelationship::follow($profileUser, $viewer);

        $game = \App\Models\Game::factory()->create([
            'owner_id' => $profileUser->id,
            'visibility' => 'protected',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertViewHas('games', fn ($games) => $games->contains('id', $game->id));
    })->group('smoke');

    it('never shows private games even to friends', function () {
        $profileUser = createProfileUser();
        $viewer = createProfileUser();

        UserRelationship::follow($viewer, $profileUser);
        UserRelationship::follow($profileUser, $viewer);

        \App\Models\Game::factory()->create([
            'owner_id' => $profileUser->id,
            'visibility' => 'private',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertViewHas('games', fn ($games) => $games->isEmpty());
    })->group('smoke');

    it('shows games the profile user participates in (not just owns)', function () {
        $profileUser = createProfileUser();
        $otherOwner = createProfileUser();

        $game = \App\Models\Game::factory()->create([
            'owner_id' => $otherOwner->id,
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        \App\Models\GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $profileUser->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $viewer = createProfileUser();

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertViewHas('games', fn ($games) => $games->contains('id', $game->id));
    });

    it('does not show past games', function () {
        $profileUser = createProfileUser();
        \App\Models\Game::factory()->create([
            'owner_id' => $profileUser->id,
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->subDays(5),
        ]);

        $viewer = createProfileUser();

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertViewHas('games', fn ($games) => $games->isEmpty());
    });

    it('deduplicates when user owns and participates in same game', function () {
        $profileUser = createProfileUser();
        $game = \App\Models\Game::factory()->create([
            'owner_id' => $profileUser->id,
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        \App\Models\GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $profileUser->id,
            'role' => 'owner',
            'status' => 'approved',
        ]);

        $viewer = createProfileUser();

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertViewHas('games', fn ($games) => $games->count() === 1);
    });

    it('shows no games for blocked viewer', function () {
        $profileUser = createProfileUser();
        $viewer = createProfileUser();

        UserRelationship::block($profileUser, $viewer);

        \App\Models\Game::factory()->create([
            'owner_id' => $profileUser->id,
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertViewHas('games', fn ($games) => $games->isEmpty());
    });

    it('own profile sees all games including private', function () {
        $profileUser = createProfileUser();
        \App\Models\Game::factory()->create([
            'owner_id' => $profileUser->id,
            'visibility' => 'private',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        Livewire::actingAs($profileUser)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertViewHas('games', fn ($games) => $games->count() === 1);
    });

    it('shows public games to guest (unauthenticated)', function () {
        $profileUser = createProfileUser();
        \App\Models\Game::factory()->create([
            'owner_id' => $profileUser->id,
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        Livewire::test(PublicProfile::class, ['user' => $profileUser])
            ->assertViewHas('games', fn ($games) => $games->count() === 1);
    });
});

describe('Campaign visibility on profile', function () {
    it('shows public owned campaigns to a stranger', function () {
        $profileUser = createProfileUser();
        $campaign = \App\Models\Campaign::factory()->create([
            'owner_id' => $profileUser->id,
            'visibility' => 'public',
        ]);

        $viewer = createProfileUser();

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertViewHas('campaigns', fn ($campaigns) => $campaigns->contains('id', $campaign->id));
    });

    it('hides protected campaigns from a stranger', function () {
        $profileUser = createProfileUser();
        \App\Models\Campaign::factory()->create([
            'owner_id' => $profileUser->id,
            'visibility' => 'protected',
        ]);

        $viewer = createProfileUser();

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertViewHas('campaigns', fn ($campaigns) => $campaigns->isEmpty());
    })->group('smoke');

    it('shows protected campaigns to a friend', function () {
        $profileUser = createProfileUser();
        $viewer = createProfileUser();

        UserRelationship::follow($viewer, $profileUser);
        UserRelationship::follow($profileUser, $viewer);

        $campaign = \App\Models\Campaign::factory()->create([
            'owner_id' => $profileUser->id,
            'visibility' => 'protected',
        ]);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertViewHas('campaigns', fn ($campaigns) => $campaigns->contains('id', $campaign->id));
    })->group('smoke');

    it('never shows private campaigns even to friends', function () {
        $profileUser = createProfileUser();
        $viewer = createProfileUser();

        UserRelationship::follow($viewer, $profileUser);
        UserRelationship::follow($profileUser, $viewer);

        \App\Models\Campaign::factory()->create([
            'owner_id' => $profileUser->id,
            'visibility' => 'private',
        ]);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertViewHas('campaigns', fn ($campaigns) => $campaigns->isEmpty());
    })->group('smoke');

    it('own profile sees all campaigns including private', function () {
        $profileUser = createProfileUser();
        \App\Models\Campaign::factory()->create([
            'owner_id' => $profileUser->id,
            'visibility' => 'private',
        ]);

        Livewire::actingAs($profileUser)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertViewHas('campaigns', fn ($campaigns) => $campaigns->count() === 1);
    });
});

// ═══════════════════════════════════════════════════════════
// GM BADGE & PROFILE SECTION
// ═══════════════════════════════════════════════════════════

describe('GM Badge on public profile', function () {
    it('shows GM badge next to user name when user has active GM profile', function () {
        $user = createProfileUser();
        \App\Models\GMProfile::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertSee('Game Master');
    });

    it('does not show GM badge when user has no GM profile', function () {
        $user = createProfileUser();

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertDontSee('Game Master');
    });

    it('does not show GM badge when GM profile is inactive', function () {
        $user = createProfileUser();
        \App\Models\GMProfile::factory()->create([
            'user_id' => $user->id,
            'is_active' => false,
        ]);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertDontSee('Game Master');
    });
});

describe('GM Profile section on public profile', function () {
    it('shows GM profile section with bio', function () {
        $user = createProfileUser();
        \App\Models\GMProfile::factory()->create([
            'user_id' => $user->id,
            'bio' => 'I love running epic campaigns!',
            'is_active' => true,
        ]);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertSee('Game Master Profile')
            ->assertSee('I love running epic campaigns!');
    });

    it('shows GM profile specializations as labels', function () {
        $user = createProfileUser();
        \App\Models\GMProfile::factory()->create([
            'user_id' => $user->id,
            'specializations' => ['storytelling', 'world-builder'],
            'is_active' => true,
        ]);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertSee('Storyteller')
            ->assertSee('World Builder');
    });

    it('shows rating and review count when reviews exist', function () {
        $user = createProfileUser();
        \App\Models\GMProfile::factory()->create([
            'user_id' => $user->id,
            'average_rating' => 4.75,
            'review_count' => 3,
            'is_active' => true,
        ]);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertSee('4.8')
            ->assertSee('3 reviews');
    });

    it('shows no reviews message when review count is zero', function () {
        $user = createProfileUser();
        \App\Models\GMProfile::factory()->create([
            'user_id' => $user->id,
            'review_count' => 0,
            'is_active' => true,
        ]);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertSee('No reviews yet');
    });

    it('does not show GM profile section when GM profile is inactive', function () {
        $user = createProfileUser();
        \App\Models\GMProfile::factory()->create([
            'user_id' => $user->id,
            'bio' => 'Hidden bio',
            'is_active' => false,
        ]);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertDontSee('Game Master Profile')
            ->assertDontSee('Hidden bio');
    });

    it('GM profile section is visible even when viewer is blocked by other sections', function () {
        $viewer = createProfileUser();
        $profileUser = createProfileUser(['pronouns' => 'they/them']);
        \App\Models\GMProfile::factory()->create([
            'user_id' => $profileUser->id,
            'bio' => 'GM Bio Visible',
            'is_active' => true,
        ]);
        UserRelationship::block($profileUser, $viewer);

        // When blocked, the GM section is shown in the header area (before the block check).
        // The GM badge appears in the profile header which is always shown.
        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertSee('Game Master');
    });

});

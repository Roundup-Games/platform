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

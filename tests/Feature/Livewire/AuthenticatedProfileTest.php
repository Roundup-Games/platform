<?php

use App\Livewire\Profile\AuthenticatedProfile;
use App\Models\User;
use App\Models\UserRelationship;
use Livewire\Livewire;


// ═══════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════

function createAuthProfileUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'profile_complete' => true,
        'pronouns' => 'they/them',
    ], $overrides));
}

// ═══════════════════════════════════════════════════════════
// PAGE LOADS
// ═══════════════════════════════════════════════════════════

describe('Authenticated Profile page loads', function () {
    it('renders for an authenticated user viewing another profile', function () {
        $viewer = createAuthProfileUser();
        $profileUser = createAuthProfileUser(['name' => 'Profile Target']);

        Livewire::actingAs($viewer)
            ->test(AuthenticatedProfile::class, ['user' => $profileUser])
            ->assertOk()
            ->assertSee('Profile Target');
    });

    it('shows own profile name without action buttons', function () {
        $user = createAuthProfileUser();

        Livewire::actingAs($user)
            ->test(AuthenticatedProfile::class, ['user' => $user])
            ->assertSet('isOwnProfile', true)
            ->assertDontSee('Follow')
            ->assertDontSee('Block');
    });

    it('never shows login prompt (authenticated context)', function () {
        $viewer = createAuthProfileUser();
        $profileUser = createAuthProfileUser();

        Livewire::actingAs($viewer)
            ->test(AuthenticatedProfile::class, ['user' => $profileUser])
            ->assertDontSee('Log in to follow');
    });
});

// ═══════════════════════════════════════════════════════════
// PROFILE CONTENT
// ═══════════════════════════════════════════════════════════

describe('Authenticated Profile displays user data', function () {
    it('shows Friends badge for mutual follows', function () {
        $viewer = createAuthProfileUser();
        $profileUser = createAuthProfileUser(['name' => 'Friend User']);
        UserRelationship::follow($viewer, $profileUser);
        UserRelationship::follow($profileUser, $viewer);

        Livewire::actingAs($viewer)
            ->test(AuthenticatedProfile::class, ['user' => $profileUser])
            ->assertSet('isFriend', true)
            ->assertSee('Friends');
    });
});

// ═══════════════════════════════════════════════════════════
// FOLLOW / UNFOLLOW ACTIONS
// ═══════════════════════════════════════════════════════════

describe('Follow / Unfollow actions (authenticated)', function () {
    it('follows a user', function () {
        $viewer = createAuthProfileUser();
        $profileUser = createAuthProfileUser();

        Livewire::actingAs($viewer)
            ->test(AuthenticatedProfile::class, ['user' => $profileUser])
            ->assertSet('isFollowing', false)
            ->call('follow')
            ->assertSet('isFollowing', true);

        expect($viewer->isFollowing($profileUser))->toBeTrue();
    });

    it('unfollows a user', function () {
        $viewer = createAuthProfileUser();
        $profileUser = createAuthProfileUser();
        UserRelationship::follow($viewer, $profileUser);

        Livewire::actingAs($viewer)
            ->test(AuthenticatedProfile::class, ['user' => $profileUser])
            ->assertSet('isFollowing', true)
            ->call('unfollow')
            ->assertSet('isFollowing', false);

        expect($viewer->isFollowing($profileUser))->toBeFalse();
    });

    it('cannot follow self', function () {
        $user = createAuthProfileUser();

        Livewire::actingAs($user)
            ->test(AuthenticatedProfile::class, ['user' => $user])
            ->call('follow');

        expect(UserRelationship::where('user_id', $user->id)
            ->where('related_user_id', $user->id)
            ->exists())->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════
// BLOCK / UNBLOCK ACTIONS
// ═══════════════════════════════════════════════════════════

describe('Block / Unblock actions (authenticated)', function () {
    it('blocks a user', function () {
        $viewer = createAuthProfileUser();
        $profileUser = createAuthProfileUser();

        Livewire::actingAs($viewer)
            ->test(AuthenticatedProfile::class, ['user' => $profileUser])
            ->assertSet('hasBlocked', false)
            ->call('block')
            ->assertSet('hasBlocked', true);

        expect($viewer->hasBlocked($profileUser))->toBeTrue();
    });

    it('unblocks a user', function () {
        $viewer = createAuthProfileUser();
        $profileUser = createAuthProfileUser();
        UserRelationship::block($viewer, $profileUser);

        Livewire::actingAs($viewer)
            ->test(AuthenticatedProfile::class, ['user' => $profileUser])
            ->assertSet('hasBlocked', true)
            ->call('unblock')
            ->assertSet('hasBlocked', false);

        expect($viewer->hasBlocked($profileUser))->toBeFalse();
    });

    it('blocked profile shows limited info', function () {
        $viewer = createAuthProfileUser();
        $profileUser = createAuthProfileUser(['pronouns' => 'she/her']);
        UserRelationship::block($profileUser, $viewer);

        Livewire::actingAs($viewer)
            ->test(AuthenticatedProfile::class, ['user' => $profileUser])
            ->assertSet('isBlockedBy', true)
            ->assertDontSee('she/her')
            ->assertSee('not available');
    });
});

// ═══════════════════════════════════════════════════════════
// GAME & CAMPAIGN VISIBILITY
// ═══════════════════════════════════════════════════════════

describe('Game session visibility on authenticated profile', function () {
    it('shows public owned games to a stranger', function () {
        $profileUser = createAuthProfileUser();
        $game = \App\Models\Game::factory()->create([
            'owner_id' => $profileUser->id,
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        $viewer = createAuthProfileUser();

        Livewire::actingAs($viewer)
            ->test(AuthenticatedProfile::class, ['user' => $profileUser])
            ->assertViewHas('games', fn ($games) => $games->contains('id', $game->id));
    });

    it('hides protected games from a stranger', function () {
        $profileUser = createAuthProfileUser();
        \App\Models\Game::factory()->create([
            'owner_id' => $profileUser->id,
            'visibility' => 'protected',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        $viewer = createAuthProfileUser();

        Livewire::actingAs($viewer)
            ->test(AuthenticatedProfile::class, ['user' => $profileUser])
            ->assertViewHas('games', fn ($games) => $games->isEmpty());
    });

    it('shows protected games to a friend', function () {
        $profileUser = createAuthProfileUser();
        $viewer = createAuthProfileUser();

        UserRelationship::follow($viewer, $profileUser);
        UserRelationship::follow($profileUser, $viewer);

        $game = \App\Models\Game::factory()->create([
            'owner_id' => $profileUser->id,
            'visibility' => 'protected',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        Livewire::actingAs($viewer)
            ->test(AuthenticatedProfile::class, ['user' => $profileUser])
            ->assertViewHas('games', fn ($games) => $games->contains('id', $game->id));
    });

    it('own profile sees all games including private', function () {
        $profileUser = createAuthProfileUser();
        \App\Models\Game::factory()->create([
            'owner_id' => $profileUser->id,
            'visibility' => 'private',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        Livewire::actingAs($profileUser)
            ->test(AuthenticatedProfile::class, ['user' => $profileUser])
            ->assertViewHas('games', fn ($games) => $games->count() === 1);
    });
});

// ═══════════════════════════════════════════════════════════
// ROUTE VERIFICATION
// ═══════════════════════════════════════════════════════════

describe('Authenticated profile route', function () {
    it('requires authentication', function () {
        $profileUser = createAuthProfileUser();

        $this->get(route('profile.show-authenticated', ['locale' => 'en', 'user' => $profileUser]))
            ->assertRedirect(route('login', ['locale' => 'en']));
    });

    it('is accessible to authenticated users', function () {
        $viewer = createAuthProfileUser();
        $profileUser = createAuthProfileUser();

        $this->actingAs($viewer)
            ->get(route('profile.show-authenticated', ['locale' => 'en', 'user' => $profileUser]))
            ->assertOk();
    });
});

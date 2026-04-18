<?php

use App\Enums\RelationshipType;
use App\Livewire\Profile\PublicProfile;
use App\Models\Campaign;
use App\Models\GameSystem;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRelationship;
use App\Models\UserVibePreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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
    });

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
            ->get(route('profile.public', ['locale' => 'en', 'user' => 999999]))
            ->assertNotFound();
    });
});

// ═══════════════════════════════════════════════════════════
// PROFILE CONTENT
// ═══════════════════════════════════════════════════════════

describe('Public Profile displays user data', function () {
    it('shows pronouns when set', function () {
        $user = createProfileUser(['pronouns' => 'she/her']);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertSee('she/her');
    });

    it('shows follower and following counts', function () {
        $user = createProfileUser();
        $follower = createProfileUser();
        UserRelationship::follow($follower, $user);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertSet('followerCount', 1)
            ->assertSet('followingCount', 0);
    });

    it('shows game systems', function () {
        $user = createProfileUser();
        $system = GameSystem::factory()->create(['name' => 'D&D 5e']);
        $user->favoriteGameSystems()->attach($system->id, ['preference_type' => 'favorite']);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertSee('D&D 5e');
    });

    it('shows vibes', function () {
        $user = createProfileUser();
        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'cooperative',
            'preference_type' => 'favorite',
        ]);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertSee('Cooperative');
    });

    it('shows campaigns', function () {
        $user = createProfileUser();
        $system = GameSystem::factory()->create(['name' => 'Test System']);
        Campaign::factory()->create([
            'owner_id' => $user->id,
            'name' => 'Epic Campaign',
            'visibility' => 'public',
            'game_system_id' => $system->id,
        ]);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertSee('Epic Campaign');
    });

    it('shows teams', function () {
        $user = createProfileUser();
        $team = Team::factory()->create(['name' => 'Test Team']);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertSee('Test Team')
            ->assertSee('captain');
    });

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
    });

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
    });

    it('updates follower count after follow', function () {
        $viewer = createProfileUser();
        $profileUser = createProfileUser();

        $component = Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser]);

        expect($component->get('followerCount'))->toBe(0);

        $component->call('follow');

        expect($component->get('followerCount'))->toBe(1);
    });

    it('updates follower count after unfollow', function () {
        $viewer = createProfileUser();
        $profileUser = createProfileUser();
        UserRelationship::follow($viewer, $profileUser);

        $component = Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser]);

        expect($component->get('followerCount'))->toBe(1);

        $component->call('unfollow');

        expect($component->get('followerCount'))->toBe(0);
    });

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
    });

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
    });

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
    });

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
// SESSION FLASH FEEDBACK
// ═══════════════════════════════════════════════════════════

describe('Session flash feedback', function () {
    it('flashes success after follow', function () {
        $viewer = createProfileUser();
        $profileUser = createProfileUser(['name' => 'Alice']);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->call('follow')
            ->assertSee('You are now following Alice');
    });

    it('flashes success after unfollow', function () {
        $viewer = createProfileUser();
        $profileUser = createProfileUser(['name' => 'Bob']);
        UserRelationship::follow($viewer, $profileUser);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->call('unfollow')
            ->assertSee('You unfollowed Bob');
    });

    it('flashes success after block', function () {
        $viewer = createProfileUser();
        $profileUser = createProfileUser(['name' => 'Charlie']);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->call('block')
            ->assertSee('You blocked Charlie');
    });

    it('flashes success after unblock', function () {
        $viewer = createProfileUser();
        $profileUser = createProfileUser(['name' => 'Dana']);
        UserRelationship::block($viewer, $profileUser);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->call('unblock')
            ->assertSee('You unblocked Dana');
    });
});

// ═══════════════════════════════════════════════════════════
// ACTION LOGGING
// ═══════════════════════════════════════════════════════════

describe('Action logging', function () {
    it('logs follow action via model', function () {
        $viewer = createProfileUser();
        $profileUser = createProfileUser();

        Log::shouldReceive('info')
            ->once()
            ->with('user.relationship.follow', \Mockery::on(fn ($ctx) => $ctx['user_id'] === $viewer->id && $ctx['target_id'] === $profileUser->id));

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->call('follow');
    });

    it('logs unfollow action via model', function () {
        $viewer = createProfileUser();
        $profileUser = createProfileUser();
        UserRelationship::follow($viewer, $profileUser);

        Log::shouldReceive('info')
            ->once()
            ->with('user.relationship.unfollow', \Mockery::on(fn ($ctx) => $ctx['user_id'] === $viewer->id && $ctx['target_id'] === $profileUser->id));

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->call('unfollow');
    });

    it('logs block action via model', function () {
        $viewer = createProfileUser();
        $profileUser = createProfileUser();

        Log::shouldReceive('info')
            ->once()
            ->with('user.relationship.block', \Mockery::on(fn ($ctx) => $ctx['user_id'] === $viewer->id && $ctx['target_id'] === $profileUser->id));

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->call('block');
    });

    it('logs unblock action via model', function () {
        $viewer = createProfileUser();
        $profileUser = createProfileUser();
        UserRelationship::block($viewer, $profileUser);

        Log::shouldReceive('info')
            ->once()
            ->with('user.relationship.unblock', \Mockery::on(fn ($ctx) => $ctx['user_id'] === $viewer->id && $ctx['target_id'] === $profileUser->id));

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->call('unblock');
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

    it('does not see Follow or Block buttons', function () {
        $profileUser = createProfileUser();

        Livewire::test(PublicProfile::class, ['user' => $profileUser])
            ->assertDontSee('Unfollow')
            ->assertDontSee('Block');
    });
});

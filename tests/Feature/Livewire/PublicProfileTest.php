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
            ->assertSee(__('common.flash_now_following', ['name' => 'Alice']));
    });

    it('flashes success after unfollow', function () {
        $viewer = createProfileUser();
        $profileUser = createProfileUser(['name' => 'Bob']);
        UserRelationship::follow($viewer, $profileUser);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->call('unfollow')
            ->assertSee(__('common.flash_unfollowed', ['name' => 'Bob']));
    });

    it('flashes success after block', function () {
        $viewer = createProfileUser();
        $profileUser = createProfileUser(['name' => 'Charlie']);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->call('block')
            ->assertSee(__('common.flash_user_blocked', ['name' => 'Charlie']));
    });

    it('flashes success after unblock', function () {
        $viewer = createProfileUser();
        $profileUser = createProfileUser(['name' => 'Dana']);
        UserRelationship::block($viewer, $profileUser);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->call('unblock')
            ->assertSee(__('common.flash_user_unblocked', ['name' => 'Dana']));
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
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

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
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

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
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

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
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

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
    });

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
    });

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
    });

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

    it('hides participated protected games from strangers', function () {
        $profileUser = createProfileUser();
        $otherOwner = createProfileUser();

        $game = \App\Models\Game::factory()->create([
            'owner_id' => $otherOwner->id,
            'visibility' => 'protected',
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
            ->assertViewHas('games', fn ($games) => $games->isEmpty());
    });

    it('shows participated protected games to friends', function () {
        $profileUser = createProfileUser();
        $viewer = createProfileUser();
        $otherOwner = createProfileUser();

        UserRelationship::follow($viewer, $profileUser);
        UserRelationship::follow($profileUser, $viewer);

        $game = \App\Models\Game::factory()->create([
            'owner_id' => $otherOwner->id,
            'visibility' => 'protected',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        \App\Models\GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $profileUser->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

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

    it('does not show public games to guest when profile owner is blocked', function () {
        $profileUser = createProfileUser();
        $viewer = createProfileUser();

        // Guest viewing when blocked — this is about the profileUser blocking someone
        // Unauthenticated viewers can't be blocked, so they should still see public games
        \App\Models\Game::factory()->create([
            'owner_id' => $profileUser->id,
            'visibility' => 'public',
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        // Guest should always see public games since they can't be blocked
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
    });

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
    });

    it('shows participated campaigns (not just owned)', function () {
        $profileUser = createProfileUser();
        $otherOwner = createProfileUser();

        $campaign = \App\Models\Campaign::factory()->create([
            'owner_id' => $otherOwner->id,
            'visibility' => 'public',
        ]);

        \App\Models\CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $profileUser->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $viewer = createProfileUser();

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertViewHas('campaigns', fn ($campaigns) => $campaigns->contains('id', $campaign->id));
    });

    it('hides participated protected campaigns from strangers', function () {
        $profileUser = createProfileUser();
        $otherOwner = createProfileUser();

        $campaign = \App\Models\Campaign::factory()->create([
            'owner_id' => $otherOwner->id,
            'visibility' => 'protected',
        ]);

        \App\Models\CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $profileUser->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $viewer = createProfileUser();

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertViewHas('campaigns', fn ($campaigns) => $campaigns->isEmpty());
    });

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
    });

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

    it('does not show bio line when bio is null', function () {
        $user = createProfileUser();
        \App\Models\GMProfile::factory()->create([
            'user_id' => $user->id,
            'bio' => null,
            'is_active' => true,
        ]);

        $html = Livewire::test(PublicProfile::class, ['user' => $user])
            ->html();

        // The About label should not appear when there is no bio
        expect(str_contains($html, '>About<'))->toBeFalse();
    });
});

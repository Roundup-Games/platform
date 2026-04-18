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
use Illuminate\Support\Facades\Log;

// ═══════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════

function createPublicProfileUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'profile_complete' => true,
        'pronouns' => 'they/them',
    ], $overrides));
}

// ═══════════════════════════════════════════════════════════
// RENDER
// ═══════════════════════════════════════════════════════════

describe('PublicProfile render', function () {
    it('renders for guest', function () {
        $user = createPublicProfileUser();

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertOk()
            ->assertSee($user->name);
    });

    it('renders for authenticated viewer', function () {
        $viewer = createPublicProfileUser();
        $profileUser = createPublicProfileUser(['name' => 'Profile Target']);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertOk()
            ->assertSee('Profile Target');
    });

    it('returns 404 for nonexistent user via HTTP', function () {
        $viewer = createPublicProfileUser();
        $this->actingAs($viewer)
            ->get(route('profile.public', ['locale' => 'en', 'user' => 999999]))
            ->assertNotFound();
    });
});

// ═══════════════════════════════════════════════════════════
// FOLLOW / UNFOLLOW
// ═══════════════════════════════════════════════════════════

describe('PublicProfile follow/unfollow', function () {
    it('follows a user', function () {
        $viewer = createPublicProfileUser();
        $target = createPublicProfileUser();

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $target])
            ->call('follow')
            ->assertSet('isFollowing', true);

        expect($viewer->isFollowing($target))->toBeTrue();
    });

    it('unfollows a user', function () {
        $viewer = createPublicProfileUser();
        $target = createPublicProfileUser();
        UserRelationship::follow($viewer, $target);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $target])
            ->call('unfollow')
            ->assertSet('isFollowing', false);

        expect($viewer->isFollowing($target))->toBeFalse();
    });

    it('updates follower count after follow', function () {
        $viewer = createPublicProfileUser();
        $target = createPublicProfileUser();

        $c = Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $target]);

        expect($c->get('followerCount'))->toBe(0);
        $c->call('follow');
        expect($c->get('followerCount'))->toBe(1);
    });

    it('updates follower count after unfollow', function () {
        $viewer = createPublicProfileUser();
        $target = createPublicProfileUser();
        UserRelationship::follow($viewer, $target);

        $c = Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $target]);

        expect($c->get('followerCount'))->toBe(1);
        $c->call('unfollow');
        expect($c->get('followerCount'))->toBe(0);
    });

    it('detects friend status after mutual follow', function () {
        $viewer = createPublicProfileUser();
        $target = createPublicProfileUser();
        UserRelationship::follow($target, $viewer);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $target])
            ->assertSet('isFriend', false)
            ->call('follow')
            ->assertSet('isFriend', true);
    });

    it('cannot follow self', function () {
        $user = createPublicProfileUser();

        Livewire::actingAs($user)
            ->test(PublicProfile::class, ['user' => $user])
            ->call('follow');

        expect(UserRelationship::where('user_id', $user->id)
            ->where('related_user_id', $user->id)
            ->exists())->toBeFalse();
    });

    it('cannot follow when blocked by target', function () {
        $viewer = createPublicProfileUser();
        $target = createPublicProfileUser();
        UserRelationship::block($target, $viewer);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $target])
            ->assertSet('isBlockedBy', true)
            ->call('follow');

        expect($viewer->isFollowing($target))->toBeFalse();
    });

    it('cannot follow when viewer has blocked target', function () {
        $viewer = createPublicProfileUser();
        $target = createPublicProfileUser();
        UserRelationship::block($viewer, $target);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $target])
            ->assertSet('hasBlocked', true)
            ->call('follow');

        // Should not create a follow relationship while blocked
        expect(UserRelationship::where('user_id', $viewer->id)
            ->where('related_user_id', $target->id)
            ->where('type', RelationshipType::Follow)
            ->exists())->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════
// BLOCK / UNBLOCK
// ═══════════════════════════════════════════════════════════

describe('PublicProfile block/unblock', function () {
    it('blocks a user', function () {
        $viewer = createPublicProfileUser();
        $target = createPublicProfileUser();

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $target])
            ->call('block')
            ->assertSet('hasBlocked', true);

        expect($viewer->hasBlocked($target))->toBeTrue();
    });

    it('removes follow on block', function () {
        $viewer = createPublicProfileUser();
        $target = createPublicProfileUser();
        UserRelationship::follow($viewer, $target);
        UserRelationship::follow($target, $viewer);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $target])
            ->assertSet('isFollowing', true)
            ->assertSet('isFollowedBy', true)
            ->call('block')
            ->assertSet('isFollowing', false)
            ->assertSet('isFollowedBy', false)
            ->assertSet('isFriend', false);
    });

    it('unblocks a user', function () {
        $viewer = createPublicProfileUser();
        $target = createPublicProfileUser();
        UserRelationship::block($viewer, $target);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $target])
            ->call('unblock')
            ->assertSet('hasBlocked', false);

        expect($viewer->hasBlocked($target))->toBeFalse();
    });

    it('cannot block self', function () {
        $user = createPublicProfileUser();

        Livewire::actingAs($user)
            ->test(PublicProfile::class, ['user' => $user])
            ->call('block');

        expect(UserRelationship::where('user_id', $user->id)
            ->where('related_user_id', $user->id)
            ->where('type', RelationshipType::Block)
            ->exists())->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════
// BLOCKED VIEW
// ═══════════════════════════════════════════════════════════

describe('PublicProfile blocked view', function () {
    it('shows limited info when viewer is blocked', function () {
        $viewer = createPublicProfileUser();
        $target = createPublicProfileUser(['pronouns' => 'she/her']);
        UserRelationship::block($target, $viewer);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $target])
            ->assertSet('isBlockedBy', true)
            ->assertDontSee('she/her')
            ->assertSee('not available');
    });

    it('blocked user cannot follow the blocker', function () {
        $viewer = createPublicProfileUser();
        $blocker = createPublicProfileUser();
        UserRelationship::block($blocker, $viewer);

        // The blocked user views the blocker's profile and tries to follow
        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $blocker])
            ->assertSet('isBlockedBy', true)
            ->call('follow');

        expect($viewer->isFollowing($blocker))->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════
// GUEST VIEW
// ═══════════════════════════════════════════════════════════

describe('PublicProfile guest view', function () {
    it('shows login prompt instead of action buttons', function () {
        $target = createPublicProfileUser();

        Livewire::test(PublicProfile::class, ['user' => $target])
            ->assertSee('Log in to follow')
            ->assertDontSee('wire:click="follow"');
    });

    it('does not see Follow or Block buttons', function () {
        $target = createPublicProfileUser();

        Livewire::test(PublicProfile::class, ['user' => $target])
            ->assertDontSee('Unfollow')
            ->assertDontSee('Block');
    });
});

// ═══════════════════════════════════════════════════════════
// PROFILE CONTENT
// ═══════════════════════════════════════════════════════════

describe('PublicProfile content', function () {
    it('shows pronouns when set', function () {
        $user = createPublicProfileUser(['pronouns' => 'she/her']);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertSee('she/her');
    });

    it('shows follower and following counts', function () {
        $user = createPublicProfileUser();
        $follower = createPublicProfileUser();
        UserRelationship::follow($follower, $user);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertSet('followerCount', 1)
            ->assertSet('followingCount', 0);
    });

    it('shows Friends badge for mutual follows', function () {
        $viewer = createPublicProfileUser();
        $target = createPublicProfileUser();
        UserRelationship::follow($viewer, $target);
        UserRelationship::follow($target, $viewer);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $target])
            ->assertSet('isFriend', true)
            ->assertSee('Friends');
    });
});

// ═══════════════════════════════════════════════════════════
// SESSION FLASH
// ═══════════════════════════════════════════════════════════

describe('PublicProfile flash feedback', function () {
    it('flashes after follow', function () {
        $viewer = createPublicProfileUser();
        $target = createPublicProfileUser(['name' => 'Alice']);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $target])
            ->call('follow')
            ->assertSee('You are now following Alice');
    });

    it('flashes after unfollow', function () {
        $viewer = createPublicProfileUser();
        $target = createPublicProfileUser(['name' => 'Bob']);
        UserRelationship::follow($viewer, $target);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $target])
            ->call('unfollow')
            ->assertSee('You unfollowed Bob');
    });

    it('flashes after block', function () {
        $viewer = createPublicProfileUser();
        $target = createPublicProfileUser(['name' => 'Charlie']);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $target])
            ->call('block')
            ->assertSee('You blocked Charlie');
    });

    it('flashes after unblock', function () {
        $viewer = createPublicProfileUser();
        $target = createPublicProfileUser(['name' => 'Dana']);
        UserRelationship::block($viewer, $target);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $target])
            ->call('unblock')
            ->assertSee('You unblocked Dana');
    });
});

// ═══════════════════════════════════════════════════════════
// ACTION LOGGING
// ═══════════════════════════════════════════════════════════

describe('PublicProfile action logging', function () {
    it('logs follow via model', function () {
        $viewer = createPublicProfileUser();
        $target = createPublicProfileUser();

        Log::shouldReceive('info')
            ->once()
            ->with('user.relationship.follow', \Mockery::on(fn ($ctx) => $ctx['user_id'] === $viewer->id && $ctx['target_id'] === $target->id));

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $target])
            ->call('follow');
    });

    it('logs unfollow via model', function () {
        $viewer = createPublicProfileUser();
        $target = createPublicProfileUser();
        UserRelationship::follow($viewer, $target);

        Log::shouldReceive('info')
            ->once()
            ->with('user.relationship.unfollow', \Mockery::on(fn ($ctx) => $ctx['user_id'] === $viewer->id && $ctx['target_id'] === $target->id));

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $target])
            ->call('unfollow');
    });

    it('logs block via model', function () {
        $viewer = createPublicProfileUser();
        $target = createPublicProfileUser();

        Log::shouldReceive('info')
            ->once()
            ->with('user.relationship.block', \Mockery::on(fn ($ctx) => $ctx['user_id'] === $viewer->id && $ctx['target_id'] === $target->id));

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $target])
            ->call('block');
    });

    it('logs unblock via model', function () {
        $viewer = createPublicProfileUser();
        $target = createPublicProfileUser();
        UserRelationship::block($viewer, $target);

        Log::shouldReceive('info')
            ->once()
            ->with('user.relationship.unblock', \Mockery::on(fn ($ctx) => $ctx['user_id'] === $viewer->id && $ctx['target_id'] === $target->id));

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $target])
            ->call('unblock');
    });
});

// ═══════════════════════════════════════════════════════════
// ROUTE INTEGRATION
// ═══════════════════════════════════════════════════════════

describe('PublicProfile route integration', function () {
    it('profile link points to correct route', function () {
        $user = createPublicProfileUser(['name' => 'Route User']);

        $this->actingAs($user)
            ->get(route('profile.public', ['locale' => 'en', 'user' => $user->id]))
            ->assertOk()
            ->assertSee('Route User');
    });
});

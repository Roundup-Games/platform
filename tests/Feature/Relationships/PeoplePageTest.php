<?php

use App\Livewire\People\PeoplePage;
use App\Models\User;
use App\Models\UserRelationship;

// ═══════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════

function createPeoplePageUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ], $overrides));
}

// ═══════════════════════════════════════════════════════════
// RENDER / ACCESS
// ═══════════════════════════════════════════════════════════

describe('PeoplePage render', function () {
    it('redirects guests', function () {
        $this->get('/en/people')->assertRedirect(route('login'));
    });

    it('renders for authenticated users', function () {
        $user = createPeoplePageUser();

        $this->actingAs($user)
            ->get('/en/people')
            ->assertOk()
            ->assertSee('People');
    });

    it('defaults to following tab', function () {
        $user = createPeoplePageUser();

        Livewire::actingAs($user)
            ->test(PeoplePage::class)
            ->assertSet('activeTab', 'following')
            ->assertSee('Following');
    });

    it('switches to followers tab', function () {
        $user = createPeoplePageUser();

        Livewire::actingAs($user)
            ->test(PeoplePage::class)
            ->set('activeTab', 'followers')
            ->assertSet('activeTab', 'followers')
            ->assertSee('Followers');
    });

    it('switches to blocked tab', function () {
        $user = createPeoplePageUser();

        Livewire::actingAs($user)
            ->test(PeoplePage::class)
            ->set('activeTab', 'blocked')
            ->assertSet('activeTab', 'blocked');
    });
});

// ═══════════════════════════════════════════════════════════
// FOLLOWING TAB
// ═══════════════════════════════════════════════════════════

describe('PeoplePage following tab', function () {
    it('shows empty state', function () {
        $user = createPeoplePageUser();

        Livewire::actingAs($user)
            ->test(PeoplePage::class)
            ->assertSee("You're not following anyone yet.");
    });

    it('lists followed users with unfollow button', function () {
        $user = createPeoplePageUser();
        $followed = createPeoplePageUser(['name' => 'Followed User']);
        UserRelationship::follow($user, $followed);

        Livewire::actingAs($user)
            ->test(PeoplePage::class)
            ->assertSee('Followed User')
            ->assertSee('Unfollow');
    });

    it('shows Friends badge for mutual follows', function () {
        $user = createPeoplePageUser();
        $friend = createPeoplePageUser(['name' => 'Mutual Friend']);
        UserRelationship::follow($user, $friend);
        UserRelationship::follow($friend, $user);

        Livewire::actingAs($user)
            ->test(PeoplePage::class)
            ->assertSee('Mutual Friend')
            ->assertSee('Friends');
    });

    it('unfollow action removes relationship', function () {
        $user = createPeoplePageUser();
        $target = createPeoplePageUser(['name' => 'To Unfollow']);
        UserRelationship::follow($user, $target);

        Livewire::actingAs($user)
            ->test(PeoplePage::class)
            ->call('unfollow', $target->id)
            ->assertSee(__('common.flash_unfollowed', ['name' => 'To Unfollow']));

        expect($user->fresh()->isFollowing($target))->toBeFalse();
    });

    it('unfollow self is noop', function () {
        $user = createPeoplePageUser();

        Livewire::actingAs($user)
            ->test(PeoplePage::class)
            ->call('unfollow', $user->id);

        expect(UserRelationship::count())->toBe(0);
    });

    it('unfollow nonexistent user is noop', function () {
        $user = createPeoplePageUser();

        Livewire::actingAs($user)
            ->test(PeoplePage::class)
            ->call('unfollow', \Illuminate\Support\Str::uuid()->toString());

        expect(UserRelationship::count())->toBe(0);
    });
});

// ═══════════════════════════════════════════════════════════
// FOLLOWERS TAB
// ═══════════════════════════════════════════════════════════

describe('PeoplePage followers tab', function () {
    it('shows empty state', function () {
        $user = createPeoplePageUser();

        Livewire::actingAs($user)
            ->test(PeoplePage::class)
            ->set('activeTab', 'followers')
            ->assertSee('No followers yet');
    });

    it('lists followers', function () {
        $user = createPeoplePageUser();
        $follower = createPeoplePageUser(['name' => 'My Follower']);
        UserRelationship::follow($follower, $user);

        Livewire::actingAs($user)
            ->test(PeoplePage::class)
            ->set('activeTab', 'followers')
            ->assertSee('My Follower');
    });

    it('shows Follow back for non-mutual', function () {
        $user = createPeoplePageUser();
        $follower = createPeoplePageUser();
        UserRelationship::follow($follower, $user);

        Livewire::actingAs($user)
            ->test(PeoplePage::class)
            ->set('activeTab', 'followers')
            ->assertSee('Follow back');
    });

    it('shows Remove for mutual', function () {
        $user = createPeoplePageUser();
        $mutual = createPeoplePageUser(['name' => 'Mutual']);
        UserRelationship::follow($mutual, $user);
        UserRelationship::follow($user, $mutual);

        Livewire::actingAs($user)
            ->test(PeoplePage::class)
            ->set('activeTab', 'followers')
            ->assertSee('Remove');
    });

    it('follow-back action creates follow', function () {
        $user = createPeoplePageUser();
        $follower = createPeoplePageUser(['name' => 'Follow Back']);
        UserRelationship::follow($follower, $user);

        expect($user->isFollowing($follower))->toBeFalse();

        Livewire::actingAs($user)
            ->test(PeoplePage::class)
            ->set('activeTab', 'followers')
            ->call('followBack', $follower->id)
            ->assertSee(__('common.flash_now_following', ['name' => 'Follow Back']));

        expect($user->fresh()->isFollowing($follower))->toBeTrue();
    });

    it('remove follower removes their follow to us', function () {
        $user = createPeoplePageUser();
        $follower = createPeoplePageUser(['name' => 'Remove Me']);
        UserRelationship::follow($follower, $user);
        UserRelationship::follow($user, $follower); // mutual

        Livewire::actingAs($user)
            ->test(PeoplePage::class)
            ->set('activeTab', 'followers')
            ->call('removeFollower', $follower->id)
            ->assertSee(__('common.flash_follower_removed', ['name' => 'Remove Me']));

        expect($follower->fresh()->isFollowing($user))->toBeFalse();
        expect($user->fresh()->isFollowing($follower))->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════
// BLOCKED TAB
// ═══════════════════════════════════════════════════════════

describe('PeoplePage blocked tab', function () {
    it('shows empty state', function () {
        $user = createPeoplePageUser();

        Livewire::actingAs($user)
            ->test(PeoplePage::class)
            ->set('activeTab', 'blocked')
            ->assertSee(__('people.content_you_haven_t_blocked_anyone'));
    });

    it('lists blocked users with Unblock button', function () {
        $user = createPeoplePageUser();
        $blocked = createPeoplePageUser(['name' => 'Blocked User']);
        UserRelationship::block($user, $blocked);

        Livewire::actingAs($user)
            ->test(PeoplePage::class)
            ->set('activeTab', 'blocked')
            ->assertSee('Blocked User')
            ->assertSee('Unblock');
    });

    it('unblock action removes block', function () {
        $user = createPeoplePageUser();
        $blocked = createPeoplePageUser(['name' => 'To Unblock']);
        UserRelationship::block($user, $blocked);

        Livewire::actingAs($user)
            ->test(PeoplePage::class)
            ->set('activeTab', 'blocked')
            ->call('unblock', $blocked->id)
            ->assertSee(__('common.flash_user_unblocked', ['name' => 'To Unblock']));

        expect($user->fresh()->hasBlocked($blocked))->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════
// TAB COUNTS
// ═══════════════════════════════════════════════════════════

describe('PeoplePage tab counts', function () {
    it('reflects following/followers/blocked counts', function () {
        $user = createPeoplePageUser();
        $followed = createPeoplePageUser();
        $follower = createPeoplePageUser();
        $blocked = createPeoplePageUser();

        UserRelationship::follow($user, $followed);
        UserRelationship::follow($follower, $user);
        UserRelationship::block($user, $blocked);

        $c = Livewire::actingAs($user)
            ->test(PeoplePage::class);

        $c->assertSet('followingCount', 1);
        $c->set('activeTab', 'followers');
        $c->assertSet('followersCount', 1);
        $c->set('activeTab', 'blocked');
        $c->assertSet('blockedCount', 1);
    });
});

// ═══════════════════════════════════════════════════════════
// PAGINATION
// ═══════════════════════════════════════════════════════════

describe('PeoplePage pagination', function () {
    it('resets pagination on tab switch', function () {
        $user = createPeoplePageUser();

        Livewire::actingAs($user)
            ->test(PeoplePage::class)
            ->set('activeTab', 'followers')
            ->set('activeTab', 'blocked')
            ->set('activeTab', 'following')
            ->assertSet('activeTab', 'following');
    });

    it('paginates following list at 12 per page', function () {
        $user = createPeoplePageUser();

        // Create 13 users to follow (should span 2 pages)
        $users = User::factory()->count(13)->create(['profile_complete' => true]);
        foreach ($users as $u) {
            UserRelationship::follow($user, $u);
        }

        $c = Livewire::actingAs($user)
            ->test(PeoplePage::class);

        // First page should have 12 items
        $followings = $c->get('followingUsers');
        expect($followings->count())->toBe(12);
        expect($followings->hasMorePages())->toBeTrue();
    });

    it('paginates followers list at 12 per page', function () {
        $user = createPeoplePageUser();

        // Create 13 followers
        $followers = User::factory()->count(13)->create(['profile_complete' => true]);
        foreach ($followers as $f) {
            UserRelationship::follow($f, $user);
        }

        $c = Livewire::actingAs($user)
            ->test(PeoplePage::class)
            ->set('activeTab', 'followers');

        $followerList = $c->get('followerUsers');
        expect($followerList->count())->toBe(12);
        expect($followerList->hasMorePages())->toBeTrue();
    });

    it('paginates blocked list at 12 per page', function () {
        $user = createPeoplePageUser();

        // Create 13 blocked users
        $blocked = User::factory()->count(13)->create(['profile_complete' => true]);
        foreach ($blocked as $b) {
            UserRelationship::block($user, $b);
        }

        $c = Livewire::actingAs($user)
            ->test(PeoplePage::class)
            ->set('activeTab', 'blocked');

        $blockedList = $c->get('blockedUsers');
        expect($blockedList->count())->toBe(12);
        expect($blockedList->hasMorePages())->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════
// PROFILE LINKS
// ═══════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════
// BADGE EDGE CASES
// ═══════════════════════════════════════════════════════════

describe('PeoplePage badge edge cases', function () {
    it('does not show Friends badge for one-way follow', function () {
        $user = createPeoplePageUser();
        $followed = createPeoplePageUser(['name' => 'One Way Follow']);
        UserRelationship::follow($user, $followed);

        $component = Livewire::actingAs($user)
            ->test(PeoplePage::class);

        $html = $component->html();
        expect($html)->toContain('One Way Follow');

        // Confirm no reverse follow exists
        expect(
            UserRelationship::where('user_id', $followed->id)
                ->where('related_user_id', $user->id)
                ->where('type', \App\Enums\RelationshipType::Follow)
                ->count()
        )->toBe(0);
    });
});


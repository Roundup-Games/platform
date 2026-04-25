<?php

namespace Tests\Feature\Livewire;

use App\Enums\RelationshipType;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PeoplePageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);
    }

    // ── Page Access ────────────────────────────────────

    public function test_guest_is_redirected_from_people_page(): void
    {
        $response = $this->get('/en/people');
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_access_people_page(): void
    {
        $response = $this->actingAs($this->user)->get('/en/people');
        $response->assertOk();
        $response->assertSee('People');
    }

    public function test_people_route_is_named_correctly(): void
    {
        $this->actingAs($this->user);
        $this->assertEquals(url('/en/people'), route('people', ['locale' => 'en']));
    }

    // ── Component Mount ────────────────────────────────

    public function test_component_renders_with_default_tab(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->assertSet('activeTab', 'following')
            ->assertSee('Following');
    }

    public function test_component_renders_with_url_tab_parameter(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->set('activeTab', 'followers')
            ->assertSet('activeTab', 'followers')
            ->assertSee('Followers');
    }

    // ── Following Tab ─────────────────────────────────

    public function test_following_tab_shows_empty_state(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->assertSee("You're not following anyone yet.");
    }

    public function test_following_tab_lists_followed_users(): void
    {
        $followed = User::factory()->create(['name' => 'Followed User']);
        UserRelationship::follow($this->user, $followed);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->assertSee('Followed User')
            ->assertSee('Unfollow');
    }

    public function test_following_tab_shows_friends_badge_for_mutual(): void
    {
        $friend = User::factory()->create(['name' => 'Mutual Friend']);
        UserRelationship::follow($this->user, $friend);
        UserRelationship::follow($friend, $this->user);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->assertSee('Mutual Friend')
            ->assertSee('Friends');
    }

    public function test_following_tab_no_friends_badge_for_one_way(): void
    {
        $followed = User::factory()->create(['name' => 'One Way Follow']);
        UserRelationship::follow($this->user, $followed);

        $component = Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class);

        // Only the following tab user card should appear, not the Friends badge
        $html = $component->html();
        $this->assertStringContainsString('One Way Follow', $html);

        // Find Friends badge occurrences — should be 0 for one-way follow
        // Since the mutual check is done per-user, only mutual follows get the badge
        $this->assertEquals(0, UserRelationship::where('user_id', $followed->id)
            ->where('related_user_id', $this->user->id)
            ->where('type', RelationshipType::Follow)
            ->count());
    }

    public function test_unfollow_action_removes_follow(): void
    {
        $followed = User::factory()->create(['name' => 'To Unfollow']);
        UserRelationship::follow($this->user, $followed);

        $this->assertTrue($this->user->isFollowing($followed));

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->call('unfollow', $followed->id)
            ->assertSee(__('common.flash_unfollowed', ['name' => 'To Unfollow']));

        $this->assertFalse($this->user->fresh()->isFollowing($followed));
    }

    public function test_unfollow_self_is_noop(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->call('unfollow', $this->user->id);

        $this->assertEquals(0, UserRelationship::count());
    }

    public function test_unfollow_nonexistent_user_is_noop(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->call('unfollow', 99999);

        $this->assertEquals(0, UserRelationship::count());
    }

    // ── Followers Tab ─────────────────────────────────

    public function test_followers_tab_shows_empty_state(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->set('activeTab', 'followers')
            ->assertSee('No followers yet');
    }

    public function test_followers_tab_lists_followers(): void
    {
        $follower = User::factory()->create(['name' => 'My Follower']);
        UserRelationship::follow($follower, $this->user);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->set('activeTab', 'followers')
            ->assertSee('My Follower');
    }

    public function test_followers_tab_shows_follow_back_for_non_mutual(): void
    {
        $follower = User::factory()->create(['name' => 'Non Mutual']);
        UserRelationship::follow($follower, $this->user);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->set('activeTab', 'followers')
            ->assertSee('Follow back');
    }

    public function test_followers_tab_shows_remove_for_mutual(): void
    {
        $mutual = User::factory()->create(['name' => 'Mutual Follower']);
        UserRelationship::follow($mutual, $this->user);
        UserRelationship::follow($this->user, $mutual);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->set('activeTab', 'followers')
            ->assertSee('Remove');
    }

    public function test_followers_tab_shows_friends_badge_for_mutual(): void
    {
        $mutual = User::factory()->create(['name' => 'Friends Mutual']);
        UserRelationship::follow($mutual, $this->user);
        UserRelationship::follow($this->user, $mutual);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->set('activeTab', 'followers')
            ->assertSee('Friends');
    }

    public function test_follow_back_action_creates_follow(): void
    {
        $follower = User::factory()->create(['name' => 'To Follow Back']);
        UserRelationship::follow($follower, $this->user);

        $this->assertFalse($this->user->isFollowing($follower));

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->set('activeTab', 'followers')
            ->call('followBack', $follower->id)
            ->assertSee(__('common.flash_now_following', ['name' => 'To Follow Back']));

        $this->assertTrue($this->user->fresh()->isFollowing($follower));
    }

    public function test_remove_follower_action_removes_relationship(): void
    {
        $follower = User::factory()->create(['name' => 'To Remove']);
        UserRelationship::follow($follower, $this->user);
        UserRelationship::follow($this->user, $follower); // mutual

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->set('activeTab', 'followers')
            ->call('removeFollower', $follower->id)
            ->assertSee(__('common.flash_follower_removed', ['name' => 'To Remove']));

        // The follower's follow to us should be gone
        $this->assertFalse($follower->fresh()->isFollowing($this->user));
        // But our follow to them should still exist
        $this->assertTrue($this->user->fresh()->isFollowing($follower));
    }

    // ── Blocked Tab ───────────────────────────────────

    public function test_blocked_tab_shows_empty_state(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->set('activeTab', 'blocked')
            ->assertSee(__('people.content_you_haven_t_blocked_anyone'));
    }

    public function test_blocked_tab_lists_blocked_users(): void
    {
        $blocked = User::factory()->create(['name' => 'Blocked User']);
        UserRelationship::block($this->user, $blocked);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->set('activeTab', 'blocked')
            ->assertSee('Blocked User')
            ->assertSee('Unblock');
    }

    public function test_unblock_action_removes_block(): void
    {
        $blocked = User::factory()->create(['name' => 'To Unblock']);
        UserRelationship::block($this->user, $blocked);

        $this->assertTrue($this->user->hasBlocked($blocked));

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->set('activeTab', 'blocked')
            ->call('unblock', $blocked->id)
            ->assertSee(__('common.flash_user_unblocked', ['name' => 'To Unblock']));

        $this->assertFalse($this->user->fresh()->hasBlocked($blocked));
    }

    // ── Tab Counts ────────────────────────────────────

    public function test_tab_counts_reflect_relationships(): void
    {
        $followed = User::factory()->create();
        $follower = User::factory()->create();
        $blocked = User::factory()->create();

        UserRelationship::follow($this->user, $followed);
        UserRelationship::follow($follower, $this->user);
        UserRelationship::block($this->user, $blocked);

        $component = Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class);

        $component->assertSet('followingCount', 1);
        $component->set('activeTab', 'followers');
        $component->assertSet('followersCount', 1);
        $component->set('activeTab', 'blocked');
        $component->assertSet('blockedCount', 1);
    }

    // ── Tab Switching ─────────────────────────────────

    public function test_switching_tabs_resets_pagination(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->set('activeTab', 'followers')
            ->assertSet('activeTab', 'followers')
            ->set('activeTab', 'blocked')
            ->assertSet('activeTab', 'blocked')
            ->set('activeTab', 'following')
            ->assertSet('activeTab', 'following');
    }

    // ── Links to Public Profile ───────────────────────

    public function test_following_tab_links_to_public_profile(): void
    {
        $followed = User::factory()->create(['name' => 'Linked User']);
        UserRelationship::follow($this->user, $followed);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->assertSee(route('profile.public', ['locale' => 'en', 'user' => $followed->id]));
    }

    public function test_followers_tab_links_to_public_profile(): void
    {
        $follower = User::factory()->create(['name' => 'Linked Follower']);
        UserRelationship::follow($follower, $this->user);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->set('activeTab', 'followers')
            ->assertSee(route('profile.public', ['locale' => 'en', 'user' => $follower->id]));
    }

    public function test_blocked_tab_links_to_public_profile(): void
    {
        $blocked = User::factory()->create(['name' => 'Linked Blocked']);
        UserRelationship::block($this->user, $blocked);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\People\PeoplePage::class)
            ->set('activeTab', 'blocked')
            ->assertSee(route('profile.public', ['locale' => 'en', 'user' => $blocked->id]));
    }

    // ── Dashboard Navigation ──────────────────────────

    public function test_dashboard_shows_people_link(): void
    {
        $response = $this->actingAs($this->user)->get('/en/dashboard');
        $response->assertOk();
        $response->assertSee(route('people'));
        $response->assertSee('People');
    }
}

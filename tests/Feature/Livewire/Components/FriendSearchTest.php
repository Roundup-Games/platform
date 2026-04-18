<?php

namespace Tests\Feature\Livewire\Components;

use App\Enums\RelationshipType;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FriendSearchTest extends TestCase
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

    // ── Helper: create mutual follow (friendship) ──────

    private function makeFriend(User $a, User $b): void
    {
        UserRelationship::create([
            'user_id' => $a->id,
            'related_user_id' => $b->id,
            'type' => RelationshipType::Follow,
        ]);
        UserRelationship::create([
            'user_id' => $b->id,
            'related_user_id' => $a->id,
            'type' => RelationshipType::Follow,
        ]);
    }

    // ── Rendering ──────────────────────────────────────

    public function test_component_renders_successfully(): void
    {
        Livewire::actingAs($this->user)
            ->test('components.friend-search')
            ->assertOk()
            ->assertSeeHtml('friend-search-input');
    }

    public function test_component_renders_with_preselected_ids(): void
    {
        $friend = User::factory()->create(['name' => 'Alice']);
        $this->makeFriend($this->user, $friend);

        Livewire::actingAs($this->user)
            ->test('components.friend-search', ['selectedIds' => [$friend->id]])
            ->assertSee('Alice');
    }

    // ── Search ─────────────────────────────────────────

    public function test_search_returns_mutual_follows_only(): void
    {
        $friend = User::factory()->create(['name' => 'Alice Friend']);
        $stranger = User::factory()->create(['name' => 'Alice Stranger']);

        // Only mutual follow with $friend
        $this->makeFriend($this->user, $friend);
        // One-way follow with $stranger (not a friend)
        UserRelationship::create([
            'user_id' => $this->user->id,
            'related_user_id' => $stranger->id,
            'type' => RelationshipType::Follow,
        ]);

        Livewire::actingAs($this->user)
            ->test('components.friend-search')
            ->set('search', 'Alice')
            ->assertSee('Alice Friend')
            ->assertDontSee('Alice Stranger');
    }

    public function test_search_returns_empty_for_short_query(): void
    {
        Livewire::actingAs($this->user)
            ->test('components.friend-search')
            ->set('search', 'A')
            ->assertSet('search', 'A');

        // Compute searchResults manually — they should be empty
        $component = Livewire::actingAs($this->user)
            ->test('components.friend-search');
        $component->instance()->search = 'A';
        $this->assertTrue($component->instance()->searchResults->isEmpty());
    }

    public function test_search_matches_name_and_email(): void
    {
        $friend = User::factory()->create([
            'name' => 'Bob Builder',
            'email' => 'bob@example.com',
        ]);
        $this->makeFriend($this->user, $friend);

        // Search by name
        Livewire::actingAs($this->user)
            ->test('components.friend-search')
            ->set('search', 'Bob')
            ->assertSee('Bob Builder');

        // Search by email
        Livewire::actingAs($this->user)
            ->test('components.friend-search')
            ->set('search', 'bob@example')
            ->assertSee('Bob Builder');
    }

    public function test_search_excludes_blocked_users(): void
    {
        $friend = User::factory()->create(['name' => 'Charlie Blocked']);
        $this->makeFriend($this->user, $friend);

        // Block each other
        UserRelationship::create([
            'user_id' => $this->user->id,
            'related_user_id' => $friend->id,
            'type' => RelationshipType::Block,
        ]);

        Livewire::actingAs($this->user)
            ->test('components.friend-search')
            ->set('search', 'Charlie')
            ->assertDontSee('Charlie Blocked');
    }

    public function test_search_excludes_already_selected_friends(): void
    {
        $friend = User::factory()->create(['name' => 'Diana Selected']);
        $this->makeFriend($this->user, $friend);

        $component = Livewire::actingAs($this->user)
            ->test('components.friend-search', ['selectedIds' => [$friend->id]]);
        $component->instance()->search = 'Diana';

        // Already-selected friend should not appear in searchResults
        $results = $component->instance()->searchResults;
        $this->assertTrue($results->isEmpty(), 'Already-selected friend should not appear in search results');
    }

    // ── Selection ──────────────────────────────────────

    public function test_select_friend_adds_to_selected_ids(): void
    {
        $friend = User::factory()->create(['name' => 'Eve']);
        $this->makeFriend($this->user, $friend);

        Livewire::actingAs($this->user)
            ->test('components.friend-search')
            ->call('selectFriend', $friend->id)
            ->assertSet('selectedIds', [$friend->id])
            ->assertSet('search', '')
            ->assertDispatched('friends-selected', ids: [$friend->id]);
    }

    public function test_select_friend_does_not_duplicate(): void
    {
        $friend = User::factory()->create(['name' => 'Eve']);
        $this->makeFriend($this->user, $friend);

        Livewire::actingAs($this->user)
            ->test('components.friend-search', ['selectedIds' => [$friend->id]])
            ->call('selectFriend', $friend->id)
            ->assertSet('selectedIds', [$friend->id]);
    }

    public function test_remove_friend_removes_from_selected_ids(): void
    {
        $friend1 = User::factory()->create(['name' => 'Alice']);
        $friend2 = User::factory()->create(['name' => 'Bob']);
        $this->makeFriend($this->user, $friend1);
        $this->makeFriend($this->user, $friend2);

        Livewire::actingAs($this->user)
            ->test('components.friend-search', ['selectedIds' => [$friend1->id, $friend2->id]])
            ->call('removeFriend', $friend1->id)
            ->assertSet('selectedIds', [$friend2->id])
            ->assertDispatched('friends-selected', ids: [$friend2->id]);
    }

    public function test_remove_friend_dispatches_empty_array_when_last_removed(): void
    {
        $friend = User::factory()->create(['name' => 'Solo']);
        $this->makeFriend($this->user, $friend);

        Livewire::actingAs($this->user)
            ->test('components.friend-search', ['selectedIds' => [$friend->id]])
            ->call('removeFriend', $friend->id)
            ->assertSet('selectedIds', [])
            ->assertDispatched('friends-selected', ids: []);
    }

    // ── Dropdown behavior ──────────────────────────────

    public function test_open_dropdown_on_search_update(): void
    {
        Livewire::actingAs($this->user)
            ->test('components.friend-search')
            ->set('search', 'test')
            ->assertSet('isOpen', true);
    }

    public function test_close_dropdown(): void
    {
        Livewire::actingAs($this->user)
            ->test('components.friend-search')
            ->set('isOpen', true)
            ->call('closeDropdown')
            ->assertSet('isOpen', false);
    }

    public function test_open_dropdown_on_focus(): void
    {
        Livewire::actingAs($this->user)
            ->test('components.friend-search')
            ->call('setOpen')
            ->assertSet('isOpen', true);
    }
}

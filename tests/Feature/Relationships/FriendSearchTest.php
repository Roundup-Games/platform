<?php

use App\Enums\RelationshipType;
use App\Models\User;
use App\Models\UserRelationship;
use Livewire\Livewire;

// ── Helper: create mutual follow (friendship) ──────────

function makeTestFriend(User $a, User $b): void
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

beforeEach(function () {
    $this->user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);
});

// ── Mutual Follow Enforcement ──────────────────────

describe('FriendSearch — Mutual Follow Enforcement', function () {
    test('search returns only mutual follows', function () {
        $friend = User::factory()->create(['name' => 'Alice Friend']);
        $oneWay = User::factory()->create(['name' => 'Bob OneWay']);
        $stranger = User::factory()->create(['name' => 'Charlie Stranger']);

        makeTestFriend($this->user, $friend);

        // Only I follow Bob, he doesn't follow me
        UserRelationship::create([
            'user_id' => $this->user->id,
            'related_user_id' => $oneWay->id,
            'type' => RelationshipType::Follow,
        ]);

        // Stranger follows me, but I don't follow them
        UserRelationship::create([
            'user_id' => $stranger->id,
            'related_user_id' => $this->user->id,
            'type' => RelationshipType::Follow,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test('components.friend-search');
        $component->instance()->search = 'Alice';

        $results = $component->instance()->searchResults;
        expect($results)->toHaveCount(1);
        expect($results->first()->id)->toBe($friend->id);
    });

});

// ── Query Length / Debounce ─────────────────────────

describe('FriendSearch — Query Length Threshold', function () {
    test('search works with two character query', function () {
        $friend = User::factory()->create(['name' => 'Amy']);
        makeTestFriend($this->user, $friend);

        $component = Livewire::actingAs($this->user)
            ->test('components.friend-search');
        $component->instance()->search = 'Am';

        expect($component->instance()->searchResults)->toHaveCount(1);
    });
});

// ── Blocked User Exclusion ──────────────────────────

describe('FriendSearch — Blocked Users', function () {
    test('search excludes user blocked by me', function () {
        $friend = User::factory()->create(['name' => 'Blocked By Me']);
        makeTestFriend($this->user, $friend);

        UserRelationship::create([
            'user_id' => $this->user->id,
            'related_user_id' => $friend->id,
            'type' => RelationshipType::Block,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test('components.friend-search');
        $component->instance()->search = 'Blocked';

        expect($component->instance()->searchResults)->toBeEmpty();
    });

    test('search excludes user who blocked me', function () {
        $friend = User::factory()->create(['name' => 'Blocked Me']);
        makeTestFriend($this->user, $friend);

        UserRelationship::create([
            'user_id' => $friend->id,
            'related_user_id' => $this->user->id,
            'type' => RelationshipType::Block,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test('components.friend-search');
        $component->instance()->search = 'Blocked';

        expect($component->instance()->searchResults)->toBeEmpty();
    });
});

// ── No Results for Non-Friends ──────────────────────

describe('FriendSearch — Non-Friends Excluded', function () {
    test('search does not return random users', function () {
        User::factory()->create(['name' => 'Random Person', 'email' => 'random@test.com']);

        $component = Livewire::actingAs($this->user)
            ->test('components.friend-search');
        $component->instance()->search = 'Random';

        expect($component->instance()->searchResults)->toBeEmpty();
    });
});

// ── Selection State ─────────────────────────────────

describe('FriendSearch — Selection', function () {
    test('friends-selected event dispatched with correct IDs', function () {
        $friend1 = User::factory()->create(['name' => 'Alicia']);
        $friend2 = User::factory()->create(['name' => 'Bruno']);
        makeTestFriend($this->user, $friend1);
        makeTestFriend($this->user, $friend2);

        Livewire::actingAs($this->user)
            ->test('components.friend-search')
            ->call('selectFriend', $friend1->id)
            ->assertDispatched('friends-selected', ids: [$friend1->id]);

        Livewire::actingAs($this->user)
            ->test('components.friend-search', ['selectedIds' => [$friend1->id]])
            ->call('selectFriend', $friend2->id)
            ->assertDispatched('friends-selected', ids: [$friend1->id, $friend2->id]);
    });

});

// ── Already-Selected Exclusion ─────────────────────

describe('FriendSearch — Already-Selected Exclusion', function () {
    test('search excludes already-selected friends', function () {
        $friend = User::factory()->create(['name' => 'Diana Selected']);
        makeTestFriend($this->user, $friend);

        $component = Livewire::actingAs($this->user)
            ->test('components.friend-search', ['selectedIds' => [$friend->id]]);
        $component->instance()->search = 'Diana';

        $results = $component->instance()->searchResults;
        expect($results)->toBeEmpty('Already-selected friend should not appear in search results');
    });
});

// ── Selection Actions ──────────────────────────────

describe('FriendSearch — Selection Actions', function () {
    test('selectFriend adds to selectedIds and clears search', function () {
        $friend = User::factory()->create(['name' => 'Eve']);
        makeTestFriend($this->user, $friend);

        Livewire::actingAs($this->user)
            ->test('components.friend-search')
            ->call('selectFriend', $friend->id)
            ->assertSet('selectedIds', [$friend->id])
            ->assertSet('search', '')
            ->assertDispatched('friends-selected', ids: [$friend->id]);
    });

    test('removeFriend removes from selectedIds', function () {
        $friend1 = User::factory()->create(['name' => 'Alice']);
        $friend2 = User::factory()->create(['name' => 'Bob']);
        makeTestFriend($this->user, $friend1);
        makeTestFriend($this->user, $friend2);

        Livewire::actingAs($this->user)
            ->test('components.friend-search', ['selectedIds' => [$friend1->id, $friend2->id]])
            ->call('removeFriend', $friend1->id)
            ->assertSet('selectedIds', [$friend2->id])
            ->assertDispatched('friends-selected', ids: [$friend2->id]);
    });

    test('removeFriend dispatches empty array when last removed', function () {
        $friend = User::factory()->create(['name' => 'Solo']);
        makeTestFriend($this->user, $friend);

        Livewire::actingAs($this->user)
            ->test('components.friend-search', ['selectedIds' => [$friend->id]])
            ->call('removeFriend', $friend->id)
            ->assertSet('selectedIds', [])
            ->assertDispatched('friends-selected', ids: []);
    });
});

// ── Dropdown Positioning & Rendering ───────────────

describe('FriendSearch — Dropdown Positioning', function () {
    test('dropdown renders inside relative container', function () {
        $friend = User::factory()->create(['name' => 'Alice Friend']);
        makeTestFriend($this->user, $friend);

        $component = Livewire::actingAs($this->user)
            ->test('components.friend-search')
            ->set('search', 'Alice')
            ->call('setOpen');

        $html = $component->html();

        // Find the position of div.relative and the dropdown listbox
        $relativePos = mb_strpos($html, 'class="relative"');
        $listboxPos = mb_strpos($html, 'role="listbox"');

        expect($relativePos)->not->toBeFalse('Expected a div.relative container in the rendered HTML');
        expect($listboxPos)->not->toBeFalse('Expected a role="listbox" dropdown in the rendered HTML');

        // The listbox must appear AFTER the relative container start — meaning it's nested inside it
        expect($listboxPos)->toBeGreaterThan($relativePos, 'Dropdown listbox should appear after the relative container, i.e. be nested inside it');

        // Also verify the dropdown has the correct absolute positioning classes
        $afterRelative = mb_substr($html, $relativePos);
        $listboxInBlock = mb_strpos($afterRelative, 'role="listbox"');
        $topFullInBlock = mb_strpos($afterRelative, 'top-full');
        expect($topFullInBlock)->not->toBeFalse('Dropdown should have top-full class');
        expect($topFullInBlock)->toBeLessThan($listboxInBlock + 500, 'top-full class should be near the listbox element');
    });

    test('dropdown has max-h-80 and overflow-y-auto', function () {
        $friend = User::factory()->create(['name' => 'Alice Friend']);
        makeTestFriend($this->user, $friend);

        $component = Livewire::actingAs($this->user)
            ->test('components.friend-search')
            ->set('search', 'Alice')
            ->call('setOpen');

        $html = $component->html();

        // Assert both scroll-affordance classes are present on the dropdown.
        // Check independently rather than as an ordered adjacent pair —
        // tailwind-merge, Blade class concatenation, or class reordering
        // would break a `max-h-80\s+overflow-y-auto` regex without changing
        // the actual scroll behaviour under test.
        expect($html)->toContain('max-h-80')->toContain('overflow-y-auto');
    });

    test('closeDropdown sets isOpen to false', function () {
        $component = Livewire::actingAs($this->user)
            ->test('components.friend-search')
            ->call('setOpen')
            ->assertSet('isOpen', true)
            ->call('closeDropdown')
            ->assertSet('isOpen', false);
    });
});

// ── Edge Cases ──────────────────────────────────────

describe('FriendSearch — Edge Cases', function () {
    test('search is case insensitive', function () {
        $friend = User::factory()->create(['name' => 'Zoe']);
        makeTestFriend($this->user, $friend);

        $component = Livewire::actingAs($this->user)
            ->test('components.friend-search');
        $component->instance()->search = 'zoe';

        expect($component->instance()->searchResults)->toHaveCount(1);
    });

    test('search matches by email', function () {
        $friend = User::factory()->create([
            'name' => 'Unique Name',
            'email' => 'special-email@example.com',
        ]);
        makeTestFriend($this->user, $friend);

        $component = Livewire::actingAs($this->user)
            ->test('components.friend-search');
        $component->instance()->search = 'special-email';

        expect($component->instance()->searchResults)->toHaveCount(1);
    });

    test('search limits to 10 results', function () {
        for ($i = 0; $i < 15; $i++) {
            $friend = User::factory()->create(['name' => "Friend {$i}"]);
            makeTestFriend($this->user, $friend);
        }

        $component = Livewire::actingAs($this->user)
            ->test('components.friend-search');
        $component->instance()->search = 'Friend';

        expect($component->instance()->searchResults)->toHaveCount(10);
    });
});

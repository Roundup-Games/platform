<?php

use App\Enums\RelationshipType;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\SocialGraphService;
use App\Services\UserAnonymizationService;
use Livewire\Livewire;

// ── Helper: create mutual follow (friendship) ──────────

function anonymTestMakeFriend(User $a, User $b): void
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

// ═══════════════════════════════════════════════════════════
// FriendSearch — Anonymized user exclusion
//
// These tests verify that anonymized users are excluded from
// friend search results. The FriendSearch component already
// has notAnonymized() on searchResults — these tests confirm
// the behavior end-to-end.
// ═══════════════════════════════════════════════════════════

describe('FriendSearch — Anonymized User Exclusion', function () {
    test('search does not return anonymized friends', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);
        $activeFriend = User::factory()->create(['name' => 'Active Alice']);
        $deletedFriend = User::factory()->create(['name' => 'Deleted Bob']);

        anonymTestMakeFriend($user, $activeFriend);
        anonymTestMakeFriend($user, $deletedFriend);

        // Anonymize one friend
        app(UserAnonymizationService::class)->anonymize($deletedFriend);

        $component = Livewire::actingAs($user)
            ->test('components.friend-search');
        $component->instance()->search = 'Alice';

        $results = $component->instance()->searchResults;
        expect($results)->toHaveCount(1);
        expect($results->first()->id)->toBe($activeFriend->id);
    });

    test('search does not return anonymized user even when name matches', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);
        $deletedFriend = User::factory()->create(['name' => 'Deleted User Match']);

        anonymTestMakeFriend($user, $deletedFriend);

        // Anonymize — name becomes "Deleted User"
        app(UserAnonymizationService::class)->anonymize($deletedFriend);

        $component = Livewire::actingAs($user)
            ->test('components.friend-search');
        $component->instance()->search = 'Deleted';

        $results = $component->instance()->searchResults;
        expect($results)->toBeEmpty('Anonymized user should not appear in friend search results');
    });

    test('selected friends computed property excludes anonymized users', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);
        $friend = User::factory()->create(['name' => 'Soon Deleted']);

        anonymTestMakeFriend($user, $friend);

        // First select the friend (while still active)
        $component = Livewire::actingAs($user)
            ->test('components.friend-search');
        $component->instance()->selectFriend($friend->id);

        // Verify friend is in selected list
        expect($component->instance()->selectedIds)->toContain($friend->id);

        // Now anonymize the friend
        app(UserAnonymizationService::class)->anonymize($friend);

        // Rebuild a fresh component with the selected ID — the selectedFriends
        // computed property should exclude the anonymized user
        $fresh = Livewire::actingAs($user)
            ->test('components.friend-search', ['selectedIds' => [$friend->id]]);

        $selected = $fresh->instance()->selectedFriends;
        expect($selected)->toBeEmpty('Anonymized user should be excluded from selected friends via notAnonymized()');
    });
});

// ═══════════════════════════════════════════════════════════
// SocialGraphService — Anonymized user exclusion
//
// These verify the whereNull('anonymized_at') filter on
// getMutualFollows.
// ═══════════════════════════════════════════════════════════

describe('SocialGraphService — Anonymized User Exclusion', function () {
    test('getMutualFollows excludes anonymized users', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $activeFriend = User::factory()->create(['name' => 'Active Friend']);
        $deletedFriend = User::factory()->create(['name' => 'Deleted Friend']);

        anonymTestMakeFriend($user, $activeFriend);
        anonymTestMakeFriend($user, $deletedFriend);

        // Anonymize one friend
        app(UserAnonymizationService::class)->anonymize($deletedFriend);

        $service = app(SocialGraphService::class);
        $mutuals = $service->getMutualFollows($user);

        expect($mutuals)->toHaveCount(1);
        expect($mutuals->first()->id)->toBe($activeFriend->id);
        expect($mutuals->pluck('id'))->not->toContain($deletedFriend->id);
    });

    test('getMutualFollows returns empty when all friends are anonymized', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $friend = User::factory()->create(['name' => 'Only Friend']);

        anonymTestMakeFriend($user, $friend);

        app(UserAnonymizationService::class)->anonymize($friend);

        $service = app(SocialGraphService::class);
        $mutuals = $service->getMutualFollows($user);

        expect($mutuals)->toBeEmpty();
    });

    test('getMutualFollows returns all friends when none are anonymized', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $friend1 = User::factory()->create(['name' => 'Friend One']);
        $friend2 = User::factory()->create(['name' => 'Friend Two']);

        anonymTestMakeFriend($user, $friend1);
        anonymTestMakeFriend($user, $friend2);

        $service = app(SocialGraphService::class);
        $mutuals = $service->getMutualFollows($user);

        expect($mutuals)->toHaveCount(2);
    });
});

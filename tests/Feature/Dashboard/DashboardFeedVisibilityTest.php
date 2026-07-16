<?php

use App\Livewire\Dashboard;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\GameActivityFeedService;

beforeEach(function () {
    Cache::flush();
    $this->user = User::factory()->create(['created_at' => now()->subDays(60)]);
    $this->actingAs($this->user);
    URL::defaults(['locale' => 'en']);
});

/**
 * Regression coverage for the dashboard community-feed visibility leak.
 *
 * Before the fix, {@see GameActivityFeedService} selected games
 * and campaigns owned by followed users with no visibility filter, so a
 * followed user's PRIVATE game surfaced in the viewer's feed. Each activity
 * builder (game_created, game_completed, session_recapped, player_joined,
 * campaign_created, campaign_completed, session_scheduled) must now honor
 * Game::visibleTo() / Campaign::visibleTo().
 */
describe('Community Feed — visibility filtering', function () {
    test('a private game created by a followed user is excluded from the feed', function () {
        $friend = User::factory()->create(['name' => 'PrivateHost']);
        UserRelationship::create([
            'user_id' => $this->user->id,
            'related_user_id' => $friend->id,
            'type' => 'follow',
        ]);

        Game::factory()->create([
            'owner_id' => $friend->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'visibility' => 'private',
            'name' => ['en' => 'Secret Game'],
        ]);

        $feed = Livewire::test(Dashboard::class)
            ->viewData('dashboard')->established->communityFeed->friends;

        expect($feed->contains(fn ($item) => $item->entityName === 'Secret Game'))
            ->toBeFalse('private game must not appear in the community feed');
    });

    test('a public game created by a followed user still appears (no regression)', function () {
        $friend = User::factory()->create(['name' => 'PublicHost']);
        UserRelationship::create([
            'user_id' => $this->user->id,
            'related_user_id' => $friend->id,
            'type' => 'follow',
        ]);

        Game::factory()->create([
            'owner_id' => $friend->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'visibility' => 'public',
            'name' => ['en' => 'Open Game'],
        ]);

        $feed = Livewire::test(Dashboard::class)
            ->viewData('dashboard')->established->communityFeed->friends;

        expect($feed->contains(fn ($item) => $item->entityName === 'Open Game'))
            ->toBeTrue('public game should still appear in the community feed');
    });

    test('a private campaign created by a followed user is excluded from the feed', function () {
        $friend = User::factory()->create(['name' => 'PrivateCampaigner']);
        UserRelationship::create([
            'user_id' => $this->user->id,
            'related_user_id' => $friend->id,
            'type' => 'follow',
        ]);

        Campaign::factory()->create([
            'owner_id' => $friend->id,
            'status' => 'active',
            'visibility' => 'private',
            'name' => ['en' => 'Secret Campaign'],
        ]);

        $feed = Livewire::test(Dashboard::class)
            ->viewData('dashboard')->established->communityFeed->friends;

        expect($feed->contains(fn ($item) => $item->entityName === 'Secret Campaign'))
            ->toBeFalse('private campaign must not appear in the community feed');
    });

    test('a public campaign created by a followed user still appears (no regression)', function () {
        $friend = User::factory()->create(['name' => 'PublicCampaigner']);
        UserRelationship::create([
            'user_id' => $this->user->id,
            'related_user_id' => $friend->id,
            'type' => 'follow',
        ]);

        Campaign::factory()->create([
            'owner_id' => $friend->id,
            'status' => 'active',
            'visibility' => 'public',
            'name' => ['en' => 'Open Campaign'],
        ]);

        $feed = Livewire::test(Dashboard::class)
            ->viewData('dashboard')->established->communityFeed->friends;

        expect($feed->contains(fn ($item) => $item->entityName === 'Open Campaign'))
            ->toBeTrue('public campaign should still appear in the community feed');
    });

    test('a private completed game by a followed user is excluded (recap/completed builders)', function () {
        $friend = User::factory()->create(['name' => 'PrivateFinisher']);
        UserRelationship::create([
            'user_id' => $this->user->id,
            'related_user_id' => $friend->id,
            'type' => 'follow',
        ]);

        Game::factory()->create([
            'owner_id' => $friend->id,
            'status' => 'completed',
            'visibility' => 'private',
            'recap' => 'Top secret recap text',
            'name' => ['en' => 'Finished Secret Game'],
        ]);

        $feed = Livewire::test(Dashboard::class)
            ->viewData('dashboard')->established->communityFeed->friends;

        expect($feed->contains(fn ($item) => $item->entityName === 'Finished Secret Game'))
            ->toBeFalse('private completed game must not appear in the community feed');
    });

    test('a private session scheduled in a followed friend campaign is excluded', function () {
        $friend = User::factory()->create(['name' => 'SessionHost']);
        UserRelationship::create([
            'user_id' => $this->user->id,
            'related_user_id' => $friend->id,
            'type' => 'follow',
        ]);

        $campaign = Campaign::factory()->create([
            'owner_id' => $friend->id,
            'status' => 'active',
            'visibility' => 'private',
        ]);

        // A private session attached to the campaign — inherits private visibility.
        Game::factory()->create([
            'owner_id' => $friend->id,
            'campaign_id' => $campaign->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'visibility' => 'private',
            'name' => ['en' => 'Secret Campaign Session'],
        ]);

        $feed = Livewire::test(Dashboard::class)
            ->viewData('dashboard')->established->communityFeed->friends;

        expect($feed->contains(fn ($item) => $item->entityName === 'Secret Campaign Session'))
            ->toBeFalse('private campaign session must not appear in the community feed');
    });

    test('a protected game by a followed user is excluded (protected requires a mutual follow)', function () {
        // One-way follow is NOT a connection for protected content
        // (SocialGraphService::getAllowedOwnerIdsForProtectedContent uses mutual follows).
        $followed = User::factory()->create(['name' => 'ProtectedHost']);
        UserRelationship::create([
            'user_id' => $this->user->id,
            'related_user_id' => $followed->id,
            'type' => 'follow',
        ]);

        Game::factory()->create([
            'owner_id' => $followed->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'visibility' => 'protected',
            'name' => ['en' => 'Protected One-Way Game'],
        ]);

        $feed = Livewire::test(Dashboard::class)
            ->viewData('dashboard')->established->communityFeed->friends;

        expect($feed->contains(fn ($item) => $item->entityName === 'Protected One-Way Game'))
            ->toBeFalse('protected game by a one-way follow must not appear');
    });

    test('a public session in a private campaign is excluded (campaign visibility gates its sessions)', function () {
        // Regression: getSessionsScheduled scopes the session (game) with
        // visibleTo() but must ALSO scope the parent campaign — otherwise a
        // public session attached to a private campaign leaks the campaign.
        $friend = User::factory()->create(['name' => 'PrivateCampaigner']);
        UserRelationship::create([
            'user_id' => $this->user->id,
            'related_user_id' => $friend->id,
            'type' => 'follow',
        ]);

        $campaign = Campaign::factory()->create([
            'owner_id' => $friend->id,
            'status' => 'active',
            'visibility' => 'private',
        ]);

        // The SESSION itself is public, but its parent campaign is private —
        // the session must still be hidden because the campaign owner's intent
        // (private) gates everything under it.
        Game::factory()->create([
            'owner_id' => $friend->id,
            'campaign_id' => $campaign->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
            'visibility' => 'public',
            'name' => ['en' => 'Public Session In Private Campaign'],
        ]);

        $feed = Livewire::test(Dashboard::class)
            ->viewData('dashboard')->established->communityFeed->friends;

        // The session_scheduled activity (which surfaces the campaign owner) must
        // not fire for a campaign the viewer can't see. The same game may still
        // appear via the generic game_created activity — that's the game's own
        // public visibility, independent of its campaign.
        expect($feed->filter(fn ($item) => $item->type === 'session_scheduled')->isEmpty())
            ->toBeTrue('a session_scheduled activity for a private campaign must not appear');
    });

    test('a protected game by a mutual friend appears in the feed', function () {
        $friend = User::factory()->create(['name' => 'MutualFriend']);
        // Mutual follow: both directions
        UserRelationship::create(['user_id' => $this->user->id, 'related_user_id' => $friend->id, 'type' => 'follow']);
        UserRelationship::create(['user_id' => $friend->id, 'related_user_id' => $this->user->id, 'type' => 'follow']);

        Game::factory()->create([
            'owner_id' => $friend->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'visibility' => 'protected',
            'name' => ['en' => 'Protected Mutual Game'],
        ]);

        $feed = Livewire::test(Dashboard::class)
            ->viewData('dashboard')->established->communityFeed->friends;

        expect($feed->contains(fn ($item) => $item->entityName === 'Protected Mutual Game'))
            ->toBeTrue('protected game by a mutual friend should appear in the community feed');
    });
});

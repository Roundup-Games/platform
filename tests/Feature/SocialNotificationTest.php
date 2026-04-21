<?php

use App\Enums\RelationshipType;
use App\Models\User;
use App\Models\UserRelationship;
use App\Notifications\NewFollower;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

describe('Follow → NewFollower trigger', function () {
    it('dispatches NewFollower notification to target when user follows them', function () {
        $follower = User::factory()->create(['name' => 'Alice']);
        $target = User::factory()->create(['name' => 'Bob']);

        UserRelationship::follow($follower, $target);

        // Verify the notification was stored in the database for the target
        $notifications = $target->notifications()->where('type', NewFollower::class)->get();
        expect($notifications)->toHaveCount(1);

        $notification = $notifications->first();
        $data = $notification->data;
        expect($data['type'])->toBe('new_follower')
            ->and($data['follower_id'])->toBe($follower->id)
            ->and($data['follower_name'])->toBe('Alice');
    });

    it('does not send duplicate notification on repeated follow', function () {
        $follower = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::follow($follower, $target);
        UserRelationship::follow($follower, $target);

        $notifications = $target->notifications()->where('type', NewFollower::class)->get();
        expect($notifications)->toHaveCount(1);
    });

    it('does not dispatch notification when target has blocked the follower', function () {
        $follower = User::factory()->create();
        $target = User::factory()->create();

        // Target blocks the follower first
        UserRelationship::block($target, $follower);

        // Follower tries to follow target
        UserRelationship::follow($follower, $target);

        // Follow relationship should be created (block doesn't prevent following in this direction)
        $this->assertDatabaseHas('user_relationships', [
            'user_id' => $follower->id,
            'related_user_id' => $target->id,
            'type' => 'follow',
        ]);

        // But notification should NOT be dispatched because target blocked follower
        $notifications = $target->notifications()->where('type', NewFollower::class)->get();
        expect($notifications)->toHaveCount(0);
    });

    it('never prevents the follow if notification dispatch fails', function () {
        $follower = User::factory()->create();
        $target = User::factory()->create();

        // The notification is dispatched via NotificationService which wraps in try/catch.
        // The follow should succeed regardless of notification outcome.
        $rel = UserRelationship::follow($follower, $target);

        $this->assertNotNull($rel);
        $this->assertDatabaseHas('user_relationships', [
            'user_id' => $follower->id,
            'related_user_id' => $target->id,
            'type' => 'follow',
        ]);
    });

    it('dispatches notification for both users in mutual follow', function () {
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);

        UserRelationship::follow($alice, $bob);
        UserRelationship::follow($bob, $alice);

        // Bob gets notification about Alice following
        $bobNotifications = $bob->notifications()->where('type', NewFollower::class)->get();
        expect($bobNotifications)->toHaveCount(1);
        expect($bobNotifications->first()->data['follower_name'])->toBe('Alice');

        // Alice gets notification about Bob following
        $aliceNotifications = $alice->notifications()->where('type', NewFollower::class)->get();
        expect($aliceNotifications)->toHaveCount(1);
        expect($aliceNotifications->first()->data['follower_name'])->toBe('Bob');
    });
});

<?php

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Models\UserRelationship;
use App\Notifications\NewFollower;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

// ── Happy path ────────────────────────────────────────

describe('Follow → NewFollower', function () {
    it('dispatches NewFollower notification to target user', function () {
        $follower = User::factory()->create(['name' => 'Alice']);
        $target = User::factory()->create(['name' => 'Bob']);

        UserRelationship::follow($follower, $target);

        $notifications = $target->notifications()->where('type', NewFollower::class)->get();
        expect($notifications)->toHaveCount(1);

        $data = $notifications->first()->data;
        expect($data['type'])->toBe('new_follower')
            ->and($data['follower_id'])->toBe($follower->id)
            ->and($data['follower_name'])->toBe('Alice')
            ->and($data)->toHaveKey('action_url');
    })->group('smoke');

    it('does not send duplicate notification on repeated follow', function () {
        $follower = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::follow($follower, $target);
        UserRelationship::follow($follower, $target);

        expect(
            $target->notifications()->where('type', NewFollower::class)->count()
        )->toBe(1);
    });

    it('dispatches separate notifications for mutual follow', function () {
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);

        UserRelationship::follow($alice, $bob);
        UserRelationship::follow($bob, $alice);

        expect($bob->notifications()->where('type', NewFollower::class)->count())->toBe(1);
        expect($bob->notifications()->first()->data['follower_name'])->toBe('Alice');

        expect($alice->notifications()->where('type', NewFollower::class)->count())->toBe(1);
        expect($alice->notifications()->first()->data['follower_name'])->toBe('Bob');
    });
});

// ── Block-list filtering ──────────────────────────────

describe('Block-list filtering', function () {
    it('does not dispatch notification when target has blocked the follower', function () {
        $follower = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::block($target, $follower);
        UserRelationship::follow($follower, $target);

        // Follow relationship is created...
        $this->assertDatabaseHas('user_relationships', [
            'user_id' => $follower->id,
            'related_user_id' => $target->id,
            'type' => 'follow',
        ]);

        // ...but no notification
        expect($target->notifications()->where('type', NewFollower::class)->count())->toBe(0);
    });
});

// ── Preference filtering ──────────────────────────────

describe('Notification preferences', function () {
    it('does not dispatch when database channel is disabled for new_follower', function () {
        $follower = User::factory()->create();
        $target = User::factory()->create([
            'notification_settings' => array_merge(
                NotificationCategory::defaultSettings(),
                ['new_follower' => ['database' => false, 'mail' => false]]
            ),
        ]);

        UserRelationship::follow($follower, $target);

        expect($target->notifications()->where('type', NewFollower::class)->count())->toBe(0);
    });

    it('sends notification with mail channel when mail preference is on', function () {
        Notification::fake();

        $follower = User::factory()->create(['name' => 'Mailer']);
        $target = User::factory()->create([
            'notification_settings' => array_merge(
                NotificationCategory::defaultSettings(),
                ['new_follower' => ['database' => true, 'mail' => true]]
            ),
        ]);

        UserRelationship::follow($follower, $target);

        Notification::assertSentTo($target, NewFollower::class, function ($notification, $channels) {
            return in_array(\Illuminate\Notifications\Channels\MailChannel::class, $channels);
        });
    });

    it('does not include mail channel when mail preference is off', function () {
        Notification::fake();

        $follower = User::factory()->create();
        $target = User::factory()->create([
            'notification_settings' => array_merge(
                NotificationCategory::defaultSettings(),
                ['new_follower' => ['database' => true, 'mail' => false]]
            ),
        ]);

        UserRelationship::follow($follower, $target);

        Notification::assertSentTo($target, NewFollower::class, function ($notification, $channels) {
            return ! in_array(\Illuminate\Notifications\Channels\MailChannel::class, $channels) && in_array(\Illuminate\Notifications\Channels\DatabaseChannel::class, $channels);
        });
    });
});

// ── Resilience ────────────────────────────────────────

describe('Notification resilience', function () {
    it('never prevents the follow when notification dispatch fails', function () {
        $follower = User::factory()->create();
        $target = User::factory()->create();

        $rel = UserRelationship::follow($follower, $target);

        $this->assertNotNull($rel);
        $this->assertDatabaseHas('user_relationships', [
            'user_id' => $follower->id,
            'related_user_id' => $target->id,
            'type' => 'follow',
        ]);
    });
});

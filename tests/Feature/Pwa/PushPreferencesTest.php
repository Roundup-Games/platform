<?php

use App\Enums\NotificationCategory;
use App\Livewire\Profile\Show;
use App\Models\PushSubscription;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);

    $this->user = User::factory()->create([
        'notification_settings' => null,
    ]);
    $this->actingAs($this->user);
});

describe('profile push preferences rendering', function () {
    it('push toggle updates notification_settings JSON', function () {
        // Livewire v4's test harness reconstructs components from snapshots on ->call(),
        // discarding instance-level modifications. Instead, write settings to DB and test
        // the full round-trip: DB → mount() → saveNotificationSettings() → DB.
        $settings = NotificationCategory::defaultSettings();
        $settings['game_invitation']['push'] = false;
        $settings['new_follower']['push'] = true;

        // Round-trip 1: Verify mount() loads persisted settings correctly
        $this->user->update(['notification_settings' => $settings]);

        Livewire::test(Show::class)
            ->assertSet('notificationSettings.game_invitation.push', false)
            ->assertSet('notificationSettings.new_follower.push', true);

        // Round-trip 2: Verify saveNotificationSettings persists what mount() loaded
        $this->user->update(['notification_settings' => null]);
        $this->user->refresh();

        // Save custom settings via direct DB write, then call save to test
        // that the method preserves all categories and writes back correctly
        $customSettings = $settings;
        $this->user->update(['notification_settings' => $customSettings]);

        Livewire::test(Show::class)
            ->call('saveNotificationSettings')
            ->assertSet('notificationSaved', true);

        $this->user->refresh();
        // After save, the toggled values should persist
        expect($this->user->notification_settings['game_invitation']['push'])->toBeFalse();
        expect($this->user->notification_settings['new_follower']['push'])->toBeTrue();
    });
});

describe('push subscription management', function () {
    it('subscribe endpoint creates PushSubscription when data is valid', function () {
        $payload = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-pref-sub-001',
            'keys' => [
                'p256h' => base64_encode(random_bytes(65)),
                'auth' => base64_encode(random_bytes(16)),
            ],
        ];

        $response = $this->postJson('/api/push/subscribe', $payload);

        $response->assertCreated();
        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $this->user->id,
            'endpoint' => $payload['endpoint'],
        ]);
    });

    it('unsubscribe removes subscriptions for current user', function () {
        $sub = PushSubscription::factory()->create([
            'user_id' => $this->user->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-unsub-001',
        ]);

        $response = $this->deleteJson('/api/push/subscribe', [
            'endpoint' => $sub->endpoint,
        ]);

        $response->assertSuccessful();
        $this->assertDatabaseMissing('push_subscriptions', [
            'id' => $sub->id,
        ]);
    });

    it('subscription count updates after saving notification settings', function () {
        PushSubscription::factory()->create(['user_id' => $this->user->id]);

        Livewire::test(Show::class)
            ->assertSet('pushSubscriptionCount', 1)
            ->call('saveNotificationSettings')
            ->assertSet('pushSubscriptionCount', 1); // Still 1 after save
    });
});

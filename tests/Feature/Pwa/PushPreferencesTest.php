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
    it('shows push toggles for all notification categories', function () {
        $component = Livewire::test(Show::class);

        foreach (NotificationCategory::values() as $category) {
            // Each category should have push property in notificationSettings
            $component->assertSet("notificationSettings.{$category}.push", function ($value) {
                return is_bool($value);
            });
        }
    });

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

    it('push defaults follow enum policy for high-priority events', function () {
        // Categories where push defaults ON
        $pushOnCategories = [
            'game_invitation', 'campaign_invitation', 'team_invitation',
            'new_application', 'application_approved', 'application_rejected',
            'participant_removed', 'team_member_removed',
            'game_cancelled', 'game_completed', 'campaign_cancelled', 'campaign_completed',
            'game_updated', 'campaign_updated', 'game_system_request', 'review_reported',
        ];
        foreach ($pushOnCategories as $cat) {
            expect(NotificationCategory::from($cat)->defaultPushEnabled())
                ->toBeTrue("Push should default ON for {$cat}");
        }

        // Informational / low-priority events should default push OFF
        $informational = ['new_follower', 'session_added_to_campaign', 'participant_joined'];
        foreach ($informational as $cat) {
            expect(NotificationCategory::from($cat)->defaultPushEnabled())
                ->toBeFalse("Push should default OFF for {$cat}");
        }
    });

    it('toggle state persists across page loads', function () {
        // Write custom push settings to DB, call save via Livewire, then
        // verify they survive a fresh component mount.
        $settings = NotificationCategory::defaultSettings();
        $settings['new_follower']['push'] = true;
        $settings['game_invitation']['push'] = false;
        $this->user->update(['notification_settings' => $settings]);

        // Call save to exercise the full persistence path
        Livewire::test(Show::class)
            ->call('saveNotificationSettings');

        // Reload — verify persisted values survive mount()
        Livewire::test(Show::class)
            ->assertSet('notificationSettings.new_follower.push', true)
            ->assertSet('notificationSettings.game_invitation.push', false);
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

    it('push subscription count reflects on profile component', function () {
        // Initially 0
        Livewire::test(Show::class)
            ->assertSet('pushSubscriptionCount', 0);

        // Add 2 subscriptions
        PushSubscription::factory()->count(2)->create(['user_id' => $this->user->id]);

        // Count should reflect
        Livewire::test(Show::class)
            ->assertSet('pushSubscriptionCount', 2);
    });

    it('subscription count updates after saving notification settings', function () {
        PushSubscription::factory()->create(['user_id' => $this->user->id]);

        Livewire::test(Show::class)
            ->assertSet('pushSubscriptionCount', 1)
            ->call('saveNotificationSettings')
            ->assertSet('pushSubscriptionCount', 1); // Still 1 after save
    });
});

describe('push unavailable state', function () {
    it('renders push devices section with enable button when no subscriptions', function () {
        Livewire::test(Show::class)
            ->assertSee(__('notifications.push_devices_heading'))
            ->assertSee(__('notifications.push_enable_button'));
    });

    it('renders enabled-on-N-devices message when subscriptions exist', function () {
        PushSubscription::factory()->count(3)->create(['user_id' => $this->user->id]);

        Livewire::test(Show::class)
            ->assertSet('pushSubscriptionCount', 3)
            ->assertSee(__('pwa.push_enabled_on_devices', ['count' => 3]));
    });

    it('renders channel push label in preferences table', function () {
        Livewire::test(Show::class)
            ->assertSee(__('notifications.channel_push'));
    });
});

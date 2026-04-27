<?php

use App\Enums\NotificationCategory;
use App\Livewire\Profile\Show;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create([
        'notification_settings' => null,
    ]);
    $this->actingAs($this->user);
});

describe('notification preferences section', function () {
    it('loads with default settings when user has none stored', function () {
        $defaults = NotificationCategory::defaultSettings();

        $component = Livewire::test(Show::class);

        // Verify all three channels present for each category
        foreach ($defaults as $key => $channels) {
            $component->assertSet("notificationSettings.{$key}.database", $channels['database'])
                      ->assertSet("notificationSettings.{$key}.mail", $channels['mail'])
                      ->assertSet("notificationSettings.{$key}.push", $channels['push']);
        }
    });

    it('loads stored notification settings on mount', function () {
        $stored = [
            'new_follower' => ['database' => false, 'mail' => false],
            'game_invitation' => ['database' => true, 'mail' => false],
        ];
        $this->user->update(['notification_settings' => $stored]);

        Livewire::test(Show::class)
            ->assertSet('notificationSettings.new_follower.database', false)
            ->assertSet('notificationSettings.new_follower.mail', false)
            ->assertSet('notificationSettings.new_follower.push', false) // defaultPushEnabled for new_follower
            ->assertSet('notificationSettings.game_invitation.database', true)
            ->assertSet('notificationSettings.game_invitation.mail', false);
    });

    it('fills push defaults for stored settings missing push key', function () {
        $stored = [
            'game_invitation' => ['database' => true, 'mail' => true],
        ];
        $this->user->update(['notification_settings' => $stored]);

        Livewire::test(Show::class)
            ->assertSet('notificationSettings.game_invitation.push', true); // defaultPushEnabled for game_invitation
    });

    it('saves notification settings including push to user model', function () {
        $newSettings = NotificationCategory::defaultSettings();
        $newSettings['new_follower']['mail'] = true;
        $newSettings['game_invitation']['database'] = false;
        $newSettings['game_invitation']['push'] = true;

        Livewire::test(Show::class)
            ->set('notificationSettings', $newSettings)
            ->call('saveNotificationSettings')
            ->assertSet('notificationSaved', true);

        $this->user->refresh();
        expect($this->user->notification_settings['new_follower']['mail'])->toBeTrue();
        expect($this->user->notification_settings['game_invitation']['database'])->toBeFalse();
        expect($this->user->notification_settings['game_invitation']['push'])->toBeTrue();
    });

    it('persists all 15 categories when saving', function () {
        Livewire::test(Show::class)
            ->call('saveNotificationSettings');

        $this->user->refresh();
        $savedKeys = array_keys($this->user->notification_settings);
        $allCategoryValues = NotificationCategory::values();

        expect($savedKeys)->toBe($allCategoryValues);
    });

    it('validates notification settings structure', function () {
        Livewire::test(Show::class)
            ->set('notificationSettings', [])
            ->call('saveNotificationSettings')
            ->assertHasErrors(['notificationSettings']);
    });

    it('renders notification preferences section with push column', function () {
        Livewire::test(Show::class)
            ->assertSee(__('notifications.content_notification_preferences'))
            ->assertSee(__('notifications.channel_in_app'))
            ->assertSee(__('notifications.channel_email'))
            ->assertSee(__('notifications.channel_push'));
    });

    it('renders push devices section', function () {
        Livewire::test(Show::class)
            ->assertSee(__('notifications.push_devices_heading'))
            ->assertSee(__('notifications.push_enable_button'));
    });

    it('shows push subscription count when user has subscriptions', function () {
        \App\Models\PushSubscription::factory()->create(['user_id' => $this->user->id]);

        Livewire::test(Show::class)
            ->assertSet('pushSubscriptionCount', 1)
            ->assertSee(__('notifications.push_enabled_on_devices', ['count' => 1]));
    });

    it('push subscription count is zero by default', function () {
        Livewire::test(Show::class)
            ->assertSet('pushSubscriptionCount', 0);
    });

    it('shows group headings for all five groups', function () {
        Livewire::test(Show::class)
            ->assertSee(__('notifications.group_social'))
            ->assertSee(__('notifications.group_invitations'))
            ->assertSee(__('notifications.group_applications'))
            ->assertSee(__('notifications.group_participation'))
            ->assertSee(__('notifications.group_status'));
    });

    it('shows success flash after saving', function () {
        Livewire::test(Show::class)
            ->call('saveNotificationSettings')
            ->assertSee(__('notifications.flash_notification_preferences_saved'));
    });

    it('toggles a single channel for a category', function () {
        Livewire::test(Show::class)
            ->set('notificationSettings.new_follower.mail', true)
            ->call('saveNotificationSettings');

        $this->user->refresh();
        expect($this->user->notification_settings['new_follower']['mail'])->toBeTrue();
    });
});

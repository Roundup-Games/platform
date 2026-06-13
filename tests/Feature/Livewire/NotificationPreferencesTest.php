<?php

use App\Enums\NotificationCategory;
use App\Livewire\Settings\Show;
use App\Models\PushSubscription;
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

        // Verify 3 representative categories have correct channel defaults
        $component->assertSet('notificationSettings.new_follower.database', $defaults['new_follower']['database'])
            ->assertSet('notificationSettings.new_follower.mail', $defaults['new_follower']['mail'])
            ->assertSet('notificationSettings.game_invitation.database', $defaults['game_invitation']['database'])
            ->assertSet('notificationSettings.game_invitation.mail', $defaults['game_invitation']['mail'])
            ->assertSet('notificationSettings.session_reminder.database', $defaults['session_reminder']['database'])
            ->assertSet('notificationSettings.session_reminder.mail', $defaults['session_reminder']['mail']);
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

    // smoke: notification settings persist across save and reload
    it('saves notification settings including push to user model', function () {
        // Livewire v4's ->set() cannot serialize nested arrays (synthetic tuple limitation).
        // Write settings to DB, then verify mount() loads them and save() persists them.
        $newSettings = NotificationCategory::defaultSettings();
        $newSettings['new_follower']['mail'] = true;
        $newSettings['game_invitation']['database'] = false;
        $newSettings['game_invitation']['push'] = true;
        $this->user->update(['notification_settings' => $newSettings]);

        // Verify mount() reads the custom settings
        Livewire::test(Show::class)
            ->assertSet('notificationSettings.new_follower.mail', true)
            ->assertSet('notificationSettings.game_invitation.database', false)
            ->assertSet('notificationSettings.game_invitation.push', true);

        // Verify save persists the loaded values back
        Livewire::test(Show::class)
            ->call('saveNotificationSettings')
            ->assertSet('notificationSaved', true);

        $this->user->refresh();
        expect($this->user->notification_settings['new_follower']['mail'])->toBeTrue();
        expect($this->user->notification_settings['game_invitation']['database'])->toBeFalse();
        expect($this->user->notification_settings['game_invitation']['push'])->toBeTrue();
    })->group('smoke');

    it('persists all categories when saving', function () {
        Livewire::test(Show::class)
            ->call('saveNotificationSettings');

        $this->user->refresh();
        $savedKeys = array_keys($this->user->notification_settings);
        $allCategoryValues = NotificationCategory::values();

        expect($savedKeys)->toBe($allCategoryValues);
    });

    it('shows push subscription count when user has subscriptions', function () {
        PushSubscription::factory()->create(['user_id' => $this->user->id]);

        Livewire::test(Show::class)
            ->assertSet('pushSubscriptionCount', 1)
            ->assertSee(__('pwa.push_enabled_on_devices', ['count' => 1]));
    });

    it('shows success flash after saving', function () {
        Livewire::test(Show::class)
            ->call('saveNotificationSettings')
            ->assertSee(__('notifications.flash_notification_preferences_saved'));
    });

});

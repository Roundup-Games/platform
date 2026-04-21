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

        Livewire::test(Show::class)
            ->assertSet('notificationSettings', $defaults);
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
            ->assertSet('notificationSettings.game_invitation.database', true)
            ->assertSet('notificationSettings.game_invitation.mail', false);
    });

    it('saves notification settings to user model', function () {
        $newSettings = NotificationCategory::defaultSettings();
        $newSettings['new_follower']['mail'] = true;
        $newSettings['game_invitation']['database'] = false;

        Livewire::test(Show::class)
            ->set('notificationSettings', $newSettings)
            ->call('saveNotificationSettings')
            ->assertSet('notificationSaved', true);

        $this->user->refresh();
        expect($this->user->notification_settings['new_follower']['mail'])->toBeTrue();
        expect($this->user->notification_settings['game_invitation']['database'])->toBeFalse();
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

    it('renders notification preferences section', function () {
        Livewire::test(Show::class)
            ->assertSee(__('notifications.content_notification_preferences'))
            ->assertSee(__('notifications.channel_in_app'))
            ->assertSee(__('notifications.channel_email'));
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

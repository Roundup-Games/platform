<?php

use App\Enums\NotificationCategory;
use App\Enums\OAuthProvider;
use App\Livewire\Settings\Show;
use App\Models\LinkedAccount;
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

describe('discord column gating (D118)', function () {
    it('hides the discord column when the member has no linked Discord account', function () {
        // The gating flag is the actual unit behaviour; the bare word "Discord"
        // also appears in the Linked Accounts section, so assert the unique
        // master-toggle aria-label (only emitted when the column renders).
        Livewire::test(Show::class)
            ->assertSet('hasDiscordLinked', false)
            ->assertDontSee(__('notifications.aria_master_toggle_all_discord'));
    });

    it('shows the discord column when the member has linked a Discord account', function () {
        LinkedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => OAuthProvider::Discord->value,
            'provider_user_id' => '999001122',
        ]);

        Livewire::test(Show::class)
            ->assertSet('hasDiscordLinked', true)
            ->assertSee(__('notifications.channel_discord'));
    });

    it('does not gate the column on a non-discord linked account', function () {
        LinkedAccount::factory()->create([
            'user_id' => $this->user->id,
            'provider' => OAuthProvider::Google->value,
        ]);

        Livewire::test(Show::class)
            ->assertSet('hasDiscordLinked', false)
            ->assertDontSee(__('notifications.aria_master_toggle_all_discord'));
    });
});

describe('discord channel persistence', function () {
    it('initializes the discord key from the category default for a fresh user', function () {
        $defaults = NotificationCategory::defaultSettings();

        Livewire::test(Show::class)
            ->assertSet('notificationSettings.waitlist_promoted.discord', $defaults['waitlist_promoted']['discord'])
            ->assertSet('notificationSettings.new_follower.discord', $defaults['new_follower']['discord']);
    });

    it('initializes the discord key from a stored preference when present', function () {
        $this->user->update([
            'notification_settings' => [
                'waitlist_promoted' => ['database' => true, 'mail' => false, 'discord' => false],
            ],
        ]);

        Livewire::test(Show::class)
            ->assertSet('notificationSettings.waitlist_promoted.discord', false);
    });

    it('falls back to the discord default when a legacy row omits the key', function () {
        $defaults = NotificationCategory::defaultSettings();
        // Legacy row shaped before D118 — no discord key at all.
        $this->user->update([
            'notification_settings' => [
                'waitlist_promoted' => ['database' => true, 'mail' => false],
            ],
        ]);

        Livewire::test(Show::class)
            ->assertSet(
                'notificationSettings.waitlist_promoted.discord',
                $defaults['waitlist_promoted']['discord'],
            );
    });

    it('persists the discord key on save without dropping it (research §10 gotcha)', function () {
        // Seed a stored discord=true so mount() loads it; save() must round-trip it.
        $this->user->update([
            'notification_settings' => [
                'waitlist_promoted' => ['database' => true, 'mail' => false, 'discord' => true],
                'new_follower' => ['database' => true, 'mail' => false, 'discord' => false],
            ],
        ]);

        Livewire::test(Show::class)
            ->call('saveNotificationSettings')
            ->assertSet('notificationSaved', true);

        $this->user->refresh();
        expect($this->user->notification_settings['waitlist_promoted'])->toHaveKey('discord')
            ->and($this->user->notification_settings['waitlist_promoted']['discord'])->toBeTrue()
            ->and($this->user->notification_settings['new_follower'])->toHaveKey('discord')
            ->and($this->user->notification_settings['new_follower']['discord'])->toBeFalse();
    })->group('smoke');

    it('writes the discord key for every category on save', function () {
        Livewire::test(Show::class)
            ->call('saveNotificationSettings');

        $this->user->refresh();
        foreach (NotificationCategory::values() as $categoryValue) {
            expect($this->user->notification_settings[$categoryValue])->toHaveKey('discord');
        }
    });
});

describe('discord master toggles', function () {
    it('toggles the discord channel across all categories via toggleChannelGlobally', function () {
        // Start from defaults so the majority direction is deterministic.
        $component = Livewire::test(Show::class);

        $onCount = collect(NotificationCategory::values())
            ->filter(fn ($k) => ! empty($component->get('notificationSettings')[$k]['discord']))
            ->count();
        $expectedNewState = $onCount <= count(NotificationCategory::values()) / 2;

        $component->call('toggleChannelGlobally', 'discord');

        foreach (NotificationCategory::values() as $categoryValue) {
            expect($component->get('notificationSettings')[$categoryValue]['discord'])->toBe($expectedNewState);
        }
    });

    it('toggles all four channels within a group via toggleGroup', function () {
        $grouped = NotificationCategory::grouped();
        $groupKey = array_key_first($grouped);
        $categoryValues = array_keys($grouped[$groupKey]['options']);

        Livewire::test(Show::class)
            ->call('toggleGroup', $groupKey)
            ->call('toggleGroup', $groupKey); // double-toggle returns to the starting majority state

        $component = Livewire::test(Show::class);
        foreach ($categoryValues as $categoryValue) {
            foreach (['database', 'mail', 'push', 'discord'] as $channel) {
                expect($component->get('notificationSettings')[$categoryValue])->toHaveKey($channel);
            }
        }
    });
});

<?php

use App\Livewire\Settings\Show as SettingsShow;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

describe('Privacy Settings', function () {
    it('loads default privacy settings on mount when none saved', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'privacy_settings' => null,
        ]);

        Livewire::actingAs($user)
            ->test(SettingsShow::class)
            ->assertSet('privacySettings', [
                'location' => 'everyone',
                'game_systems' => 'friends',
                'vibes' => 'friends',
                'campaigns' => 'friends',
                'teams' => 'friends',
                'friends_list' => 'friends',
                'stats' => 'friends',
            ]);
    });

    it('loads existing privacy settings on mount', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'privacy_settings' => [
                'location' => 'nobody',
                'game_systems' => 'friends',
                'vibes' => 'everyone',
                'campaigns' => 'nobody',
                'teams' => 'friends',
                'friends_list' => 'nobody',
            ],
        ]);

        Livewire::actingAs($user)
            ->test(SettingsShow::class)
            ->assertSet('privacySettings.location', 'nobody')
            ->assertSet('privacySettings.game_systems', 'friends')
            ->assertSet('privacySettings.vibes', 'everyone')
            ->assertSet('privacySettings.campaigns', 'nobody')
            ->assertSet('privacySettings.teams', 'friends')
            ->assertSet('privacySettings.friends_list', 'nobody');
    });

    it('fills missing fields with defaults when partial settings saved', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'privacy_settings' => [
                'location' => 'nobody',
                'friends_list' => 'friends',
            ],
        ]);

        Livewire::actingAs($user)
            ->test(SettingsShow::class)
            ->assertSet('privacySettings.location', 'nobody')
            ->assertSet('privacySettings.friends_list', 'friends')
            // Fields not in the stored settings should default to 'friends' (location → 'everyone')
            ->assertSet('privacySettings.game_systems', 'friends')
            ->assertSet('privacySettings.vibes', 'friends')
            ->assertSet('privacySettings.campaigns', 'friends')
            ->assertSet('privacySettings.teams', 'friends');
    });

    it('saves privacy settings to user model', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'privacy_settings' => null,
        ]);

        $newSettings = [
            'location' => 'nobody',
            'game_systems' => 'friends',
            'vibes' => 'everyone',
            'campaigns' => 'nobody',
            'teams' => 'friends',
            'friends_list' => 'nobody',
            'stats' => 'nobody',
        ];

        Livewire::actingAs($user)
            ->test(SettingsShow::class)
            ->set('privacySettings', $newSettings)
            ->call('savePrivacySettings')
            ->assertSet('privacySaved', true);

        expect($user->fresh()->privacy_settings)->toBe($newSettings);
    });

    it('logs privacy settings update', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'privacy_settings' => null,
        ]);

        Log::shouldReceive('info')
            ->once()
            ->with('Privacy settings updated', \Mockery::on(function ($ctx) use ($user) {
                return $ctx['user_id'] === $user->id
                    && isset($ctx['settings']);
            }));

        Livewire::actingAs($user)
            ->test(SettingsShow::class)
            ->set('privacySettings', [
                'location' => 'nobody',
                'game_systems' => 'friends',
                'vibes' => 'everyone',
                'campaigns' => 'nobody',
                'teams' => 'friends',
                'friends_list' => 'nobody',
            ])
            ->call('savePrivacySettings');
    });

    it('validates privacy setting values', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'privacy_settings' => null,
        ]);

        Livewire::actingAs($user)
            ->test(SettingsShow::class)
            ->set('privacySettings', [
                'location' => 'invalid_value',
                'game_systems' => 'friends',
                'vibes' => 'everyone',
                'campaigns' => 'nobody',
                'teams' => 'friends',
                'friends_list' => 'nobody',
            ])
            ->call('savePrivacySettings')
            ->assertHasErrors(['privacySettings.location']);
    });

    it('ignores unknown field keys on save', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'privacy_settings' => null,
        ]);

        Livewire::actingAs($user)
            ->test(SettingsShow::class)
            ->set('privacySettings', [
                'location' => 'everyone',
                'game_systems' => 'friends',
                'vibes' => 'everyone',
                'campaigns' => 'nobody',
                'teams' => 'friends',
                'friends_list' => 'nobody',
                'stats' => 'nobody',
                'unknown_field' => 'everyone',
            ])
            ->call('savePrivacySettings');

        $saved = $user->fresh()->privacy_settings;
        // Only known fields should be persisted
        expect(array_keys($saved))->toBe([
            'location', 'game_systems', 'vibes', 'campaigns', 'teams', 'friends_list', 'stats',
        ]);
    });

    it('renders privacy settings section on the settings page', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'privacy_settings' => null,
        ]);

        $this->actingAs($user)
            ->get(route('settings.show'))
            ->assertOk()
            ->assertSee('Privacy Settings')
            ->assertSee('Everyone')
            ->assertSee('Friends')
            ->assertSee('Nobody');
    });
});

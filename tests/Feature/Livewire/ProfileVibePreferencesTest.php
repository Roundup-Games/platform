<?php

use App\Enums\VibeFlag;
use App\Livewire\Profile\Show;
use App\Models\User;
use App\Models\UserVibePreference;
use Livewire\Livewire;

// ═══════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════

function createVibeUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'profile_complete' => true,
    ], $overrides));
}

function assertVibePreferenceInDb(User $user, string $flagValue, string $preferenceType): void
{
    expect(
        UserVibePreference::where('user_id', $user->id)
            ->where('vibe_preference_value', $flagValue)
            ->where('preference_type', $preferenceType)
            ->exists()
    )->toBeTrue("Expected vibe preference {$flagValue}={$preferenceType} not found in DB");
}

function assertVibePreferenceNotInDb(User $user, string $flagValue): void
{
    expect(
        UserVibePreference::where('user_id', $user->id)
            ->where('vibe_preference_value', $flagValue)
            ->exists()
    )->toBeFalse("Expected no vibe preference for {$flagValue}, but found one in DB");
}

// ═══════════════════════════════════════════════════════════
// PROFILE PAGE LOADS VIBE PREFERENCES SECTION
// ═══════════════════════════════════════════════════════════

describe('Profile page vibe section', function () {
    it('renders the profile page without errors', function () {
        $user = createVibeUser();

        Livewire::actingAs($user)
            ->test(Show::class)
            ->assertOk();
    });

    it('initializes vibePreferences with all flags in neutral state for new user', function () {
        $user = createVibeUser();

        $component = Livewire::actingAs($user)
            ->test(Show::class);

        foreach (VibeFlag::cases() as $flag) {
            $component->assertSet('vibePreferences.'.$flag->value, null);
        }
    });
});

// ═══════════════════════════════════════════════════════════
// LOAD EXISTING PREFERENCES
// ═══════════════════════════════════════════════════════════

describe('Load existing preferences', function () {
    it('loads existing UserVibePreference rows into component state', function () {
        $user = createVibeUser();

        // Create preferences: favorite, avoid, and a third to verify multi-row loading
        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'atmospheric',
            'preference_type' => 'favorite',
        ]);
        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'competitive',
            'preference_type' => 'avoid',
        ]);
        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'rules-light',
            'preference_type' => 'favorite',
        ]);

        $component = Livewire::actingAs($user)
            ->test(Show::class);

        $component
            ->assertSet('vibePreferences.atmospheric', 'favorite')
            ->assertSet('vibePreferences.competitive', 'avoid')
            ->assertSet('vibePreferences.rules-light', 'favorite');
        // Unset flags should be null
        $prefs = $component->get('vibePreferences');
        expect($prefs['exploration'] ?? null)->toBeNull();
    });
});

// ═══════════════════════════════════════════════════════════
// SAVE PREFERENCES ROUND-TRIP
// ═══════════════════════════════════════════════════════════

describe('Save preferences round-trip', function () {
    it('saves vibe preferences to database via savePreferences', function () {
        $user = createVibeUser();

        Livewire::actingAs($user)
            ->test(Show::class)
            // Simulate the picker dispatching vibe-preferences-changed
            ->dispatch('vibe-preferences-changed', preferences: [
                'atmospheric' => 'favorite',
                'competitive' => 'avoid',
                'exploration' => 'favorite',
            ])
            ->call('savePreferences')
            ->assertHasNoErrors();

        assertVibePreferenceInDb($user, 'atmospheric', 'favorite');
        assertVibePreferenceInDb($user, 'competitive', 'avoid');
        assertVibePreferenceInDb($user, 'exploration', 'favorite');
    });

    it('uses delete-and-insert: changing preferences removes old rows', function () {
        $user = createVibeUser();

        // First save
        Livewire::actingAs($user)
            ->test(Show::class)
            ->dispatch('vibe-preferences-changed', preferences: [
                'atmospheric' => 'favorite',
                'horror' => 'avoid',
            ])
            ->call('savePreferences')
            ->assertHasNoErrors();

        assertVibePreferenceInDb($user, 'atmospheric', 'favorite');
        assertVibePreferenceInDb($user, 'horror', 'avoid');

        // Second save with different preferences
        Livewire::actingAs($user)
            ->test(Show::class)
            ->dispatch('vibe-preferences-changed', preferences: [
                'exploration' => 'favorite',
                'tactical' => 'avoid',
            ])
            ->call('savePreferences')
            ->assertHasNoErrors();

        // Old rows should be gone
        assertVibePreferenceNotInDb($user, 'atmospheric');
        assertVibePreferenceNotInDb($user, 'horror');

        // New rows should exist
        assertVibePreferenceInDb($user, 'exploration', 'favorite');
        assertVibePreferenceInDb($user, 'tactical', 'avoid');
    });

    it('neutral/null preferences are NOT inserted into DB', function () {
        $user = createVibeUser();

        // Build a preferences map with many nulls
        $prefs = [];
        foreach (VibeFlag::cases() as $flag) {
            $prefs[$flag->value] = null;
        }
        $prefs['atmospheric'] = 'favorite';

        Livewire::actingAs($user)
            ->test(Show::class)
            ->dispatch('vibe-preferences-changed', preferences: $prefs)
            ->call('savePreferences')
            ->assertHasNoErrors();

        // Only atmospheric should be in DB
        $dbCount = UserVibePreference::where('user_id', $user->id)->count();
        expect($dbCount)->toBe(1);
        assertVibePreferenceInDb($user, 'atmospheric', 'favorite');
    });
});

// ═══════════════════════════════════════════════════════════
// MUTUAL EXCLUSIVITY ON SAVE
// ═══════════════════════════════════════════════════════════

describe('Mutual exclusivity on save', function () {
    it('picker auto-sets partner to avoid, both get persisted', function () {
        $user = createVibeUser();

        // Use the picker's togglePaired to set rules-light as favorite
        // This should auto-set rules-heavy to 'avoid' in the component state
        $prefs = [];
        foreach (VibeFlag::cases() as $flag) {
            $prefs[$flag->value] = null;
        }
        $prefs['rules-light'] = 'favorite';
        $prefs['rules-heavy'] = 'avoid'; // auto-set by picker

        Livewire::actingAs($user)
            ->test(Show::class)
            ->dispatch('vibe-preferences-changed', preferences: $prefs)
            ->call('savePreferences')
            ->assertHasNoErrors();

        // Both the favorite AND the auto-avoided partner should be persisted
        assertVibePreferenceInDb($user, 'rules-light', 'favorite');
        assertVibePreferenceInDb($user, 'rules-heavy', 'avoid');
    });

    it('auto-avoided partner is loaded correctly on next mount', function () {
        $user = createVibeUser();

        // Directly persist the favorite + auto-avoid pair
        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'lighthearted',
            'preference_type' => 'favorite',
        ]);
        UserVibePreference::create([
            'user_id' => $user->id,
            'vibe_preference_value' => 'serious',
            'preference_type' => 'avoid',
        ]);

        // Reload the profile component and verify state
        $component = Livewire::actingAs($user)
            ->test(Show::class);

        $component
            ->assertSet('vibePreferences.lighthearted', 'favorite')
            ->assertSet('vibePreferences.serious', 'avoid');
    });

    it('FamilyFriendly dual-pair: both horror and mature-themes favorites persist FamilyFriendly avoid', function () {
        $user = createVibeUser();

        $prefs = [];
        foreach (VibeFlag::cases() as $flag) {
            $prefs[$flag->value] = null;
        }
        $prefs['horror'] = 'favorite';
        $prefs['mature-themes'] = 'favorite';
        $prefs['family-friendly'] = 'avoid'; // auto-avoided by both pairs

        Livewire::actingAs($user)
            ->test(Show::class)
            ->dispatch('vibe-preferences-changed', preferences: $prefs)
            ->call('savePreferences')
            ->assertHasNoErrors();

        assertVibePreferenceInDb($user, 'horror', 'favorite');
        assertVibePreferenceInDb($user, 'mature-themes', 'favorite');
        assertVibePreferenceInDb($user, 'family-friendly', 'avoid');
    });
});

// ═══════════════════════════════════════════════════════════
// VALIDATION
// ═══════════════════════════════════════════════════════════

describe('Validation', function () {
    it('only valid VibeFlag values are persisted', function () {
        $user = createVibeUser();

        $prefs = [];
        foreach (VibeFlag::cases() as $flag) {
            $prefs[$flag->value] = null;
        }
        $prefs['atmospheric'] = 'favorite';
        $prefs['nonexistent-flag'] = 'favorite'; // invalid flag value

        Livewire::actingAs($user)
            ->test(Show::class)
            ->dispatch('vibe-preferences-changed', preferences: $prefs)
            ->call('savePreferences')
            ->assertHasNoErrors();

        // Only atmospheric should be persisted; the invalid flag should be filtered out
        assertVibePreferenceInDb($user, 'atmospheric', 'favorite');
        assertVibePreferenceNotInDb($user, 'nonexistent-flag');
    });

    it('savePreferences only persists favorite and avoid types, filtering nulls', function () {
        $user = createVibeUser();

        // The vibePreferencesChanged handler receives the full preferences map
        // including null values. savePreferences must only insert rows for non-null,
        // valid types (favorite/avoid).
        $prefs = [];
        foreach (VibeFlag::cases() as $flag) {
            $prefs[$flag->value] = null;
        }
        $prefs['atmospheric'] = 'favorite';
        $prefs['horror'] = 'avoid';
        // Remaining flags are null — should NOT be inserted

        Livewire::actingAs($user)
            ->test(Show::class)
            ->dispatch('vibe-preferences-changed', preferences: $prefs)
            ->call('savePreferences')
            ->assertHasNoErrors();

        // Only 2 rows should exist (the non-null ones)
        $dbCount = UserVibePreference::where('user_id', $user->id)->count();
        expect($dbCount)->toBe(2);
        assertVibePreferenceInDb($user, 'atmospheric', 'favorite');
        assertVibePreferenceInDb($user, 'horror', 'avoid');

        // Verify neutral flags are absent
        assertVibePreferenceNotInDb($user, 'lighthearted');
        assertVibePreferenceNotInDb($user, 'exploration');
    });

    it('savePreferences rejects invalid preference values in the array', function () {
        $user = createVibeUser();

        // Build preferences with an invalid value — Livewire validates before saving
        $prefs = [];
        foreach (VibeFlag::cases() as $flag) {
            $prefs[$flag->value] = null;
        }
        $prefs['atmospheric'] = 'invalid-type';

        Livewire::actingAs($user)
            ->test(Show::class)
            ->dispatch('vibe-preferences-changed', preferences: $prefs)
            ->set('vibePreferences.atmospheric', 'invalid-type')
            ->call('savePreferences')
            ->assertHasErrors('vibePreferences.atmospheric');
    });
});

// ═══════════════════════════════════════════════════════════
// EVENT HANDLING
// ═══════════════════════════════════════════════════════════

describe('Event handling', function () {
    it('vibe-preferences-changed event updates vibePreferences on Show component', function () {
        $user = createVibeUser();

        Livewire::actingAs($user)
            ->test(Show::class)
            ->dispatch('vibe-preferences-changed', preferences: [
                'atmospheric' => 'favorite',
                'horror' => 'avoid',
            ])
            ->assertSet('vibePreferences.atmospheric', 'favorite')
            ->assertSet('vibePreferences.horror', 'avoid');
    });

    it('receiving the event does not immediately persist to DB', function () {
        $user = createVibeUser();

        Livewire::actingAs($user)
            ->test(Show::class)
            ->dispatch('vibe-preferences-changed', preferences: [
                'atmospheric' => 'favorite',
            ]);

        // Should NOT be in DB yet — only after savePreferences()
        assertVibePreferenceNotInDb($user, 'atmospheric');
    });
});

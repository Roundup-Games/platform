<?php

use App\Enums\VibeFlag;
use App\Livewire\Components\VibePreferencePicker;
use Livewire\Livewire;

// ═══════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════

/**
 * All paired flag values (extracted from VibeFlag::mutuallyExclusivePairs).
 */
function vibePairedValues(): array
{
    $values = [];
    foreach (VibeFlag::mutuallyExclusivePairs() as $pair) {
        $values[$pair[0]->value] = true;
        $values[$pair[1]->value] = true;
    }

    return array_keys($values);
}

/**
 * All standalone flag values (those NOT in any mutually exclusive pair).
 */
function vibeStandaloneValues(): array
{
    $paired = array_flip(vibePairedValues());

    return array_filter(VibeFlag::values(), fn ($v) => ! isset($paired[$v]));
}

// ═══════════════════════════════════════════════════════════
// RENDERS WITH CORRECT STRUCTURE
// ═══════════════════════════════════════════════════════════

describe('Rendering', function () {
    it('initializes all flags to null (neutral) by default', function () {
        $component = Livewire::test(VibePreferencePicker::class);

        foreach (VibeFlag::cases() as $flag) {
            $component->assertSet('preferences.'.$flag->value, null);
        }
    });
});

// ═══════════════════════════════════════════════════════════
// PAIRED FLAG EXCLUSIVITY
// ═══════════════════════════════════════════════════════════

describe('Paired flag exclusivity', function () {
    it('clicking favorite on lighthearted sets lighthearted to favorite and serious to avoid', function () {
        Livewire::test(VibePreferencePicker::class)
            ->call('togglePaired', 'lighthearted', 'serious', 'favorite')
            ->assertSet('preferences.lighthearted', 'favorite')
            ->assertSet('preferences.serious', 'avoid');
    });

    it('clicking neutral center on a pair clears both to null', function () {
        Livewire::test(VibePreferencePicker::class)
            ->call('togglePaired', 'lighthearted', 'serious', 'favorite')
            ->call('togglePaired', 'lighthearted', 'serious', null)
            ->assertSet('preferences.lighthearted', null)
            ->assertSet('preferences.serious', null);
    });

    it('clicking favorite on serious sets serious to favorite and lighthearted to avoid', function () {
        Livewire::test(VibePreferencePicker::class)
            ->call('togglePaired', 'serious', 'lighthearted', 'favorite')
            ->assertSet('preferences.serious', 'favorite')
            ->assertSet('preferences.lighthearted', 'avoid');
    });

    it('FamilyFriendly dual-pair: setting Horror to favorite auto-avoids FamilyFriendly', function () {
        Livewire::test(VibePreferencePicker::class)
            ->call('togglePaired', 'horror', 'family-friendly', 'favorite')
            ->assertSet('preferences.horror', 'favorite')
            ->assertSet('preferences.family-friendly', 'avoid');
    });

    it('FamilyFriendly dual-pair: then setting MatureThemes to favorite keeps FamilyFriendly avoided', function () {
        Livewire::test(VibePreferencePicker::class)
            // First: Horror → favorite (auto-avoids FamilyFriendly)
            ->call('togglePaired', 'horror', 'family-friendly', 'favorite')
            ->assertSet('preferences.family-friendly', 'avoid')
            // Second: MatureThemes → favorite (FamilyFriendly is already partner)
            ->call('togglePaired', 'mature-themes', 'family-friendly', 'favorite')
            ->assertSet('preferences.mature-themes', 'favorite')
            // FamilyFriendly should remain avoided
            ->assertSet('preferences.family-friendly', 'avoid');
    });

    it('avoiding one side of a pair does not auto-favorite the other', function () {
        Livewire::test(VibePreferencePicker::class)
            ->call('togglePaired', 'lighthearted', 'serious', 'avoid')
            ->assertSet('preferences.lighthearted', 'avoid')
            ->assertSet('preferences.serious', null);
    });

    it('dispatches vibe-preferences-changed event after togglePaired', function () {
        Livewire::test(VibePreferencePicker::class)
            ->call('togglePaired', 'competitive', 'cooperative', 'favorite')
            ->assertDispatched('vibe-preferences-changed');
    });
});

// ═══════════════════════════════════════════════════════════
// STANDALONE CHIP CYCLING
// ═══════════════════════════════════════════════════════════

describe('Standalone chip cycling', function () {
    it('clicking atmospheric (neutral) sets it to favorite', function () {
        Livewire::test(VibePreferencePicker::class)
            ->call('toggleStandalone', 'atmospheric')
            ->assertSet('preferences.atmospheric', 'favorite');
    });

    it('clicking atmospheric (favorite) sets it to avoid', function () {
        Livewire::test(VibePreferencePicker::class)
            ->call('toggleStandalone', 'atmospheric')
            ->call('toggleStandalone', 'atmospheric')
            ->assertSet('preferences.atmospheric', 'avoid');
    });

    it('clicking atmospheric (avoid) sets it back to neutral (null)', function () {
        Livewire::test(VibePreferencePicker::class)
            ->call('toggleStandalone', 'atmospheric')
            ->call('toggleStandalone', 'atmospheric')
            ->call('toggleStandalone', 'atmospheric')
            ->assertSet('preferences.atmospheric', null);
    });

    it('cycles multiple standalone flags independently', function () {
        Livewire::test(VibePreferencePicker::class)
            ->call('toggleStandalone', 'atmospheric')
            ->call('toggleStandalone', 'exploration')
            ->assertSet('preferences.atmospheric', 'favorite')
            ->assertSet('preferences.exploration', 'favorite')
            // Cycle atmospheric one more step
            ->call('toggleStandalone', 'atmospheric')
            ->assertSet('preferences.atmospheric', 'avoid')
            // Exploration should still be at favorite
            ->assertSet('preferences.exploration', 'favorite');
    });

    it('dispatches vibe-preferences-changed event after toggleStandalone', function () {
        Livewire::test(VibePreferencePicker::class)
            ->call('toggleStandalone', 'atmospheric')
            ->assertDispatched('vibe-preferences-changed');
    });
});

// ═══════════════════════════════════════════════════════════
// MOUNT WITH EXISTING PREFERENCES
// ═══════════════════════════════════════════════════════════

describe('Mount with existing preferences', function () {
    it('loads provided preferences on mount', function () {
        Livewire::test(VibePreferencePicker::class, [
            'preferences' => [
                'atmospheric' => 'favorite',
                'competitive' => 'avoid',
            ],
        ])
            ->assertSet('preferences.atmospheric', 'favorite')
            ->assertSet('preferences.competitive', 'avoid');
    });

    it('initializes unspecified flags to null', function () {
        Livewire::test(VibePreferencePicker::class, [
            'preferences' => [
                'atmospheric' => 'favorite',
            ],
        ])
            ->assertSet('preferences.atmospheric', 'favorite')
            ->assertSet('preferences.horror', null)
            ->assertSet('preferences.lighthearted', null);
    });

    it('computed pairedFlags reflect loaded state', function () {
        $component = Livewire::test(VibePreferencePicker::class, [
            'preferences' => [
                'lighthearted' => 'favorite',
                'serious' => 'avoid',
            ],
        ]);

        $paired = $component->instance()->getPairedFlags;
        $lightheartedPair = collect($paired)->first(fn ($p) => $p['flagA'] === 'lighthearted');

        expect($lightheartedPair)->not->toBeNull();
        expect($lightheartedPair['valueA'])->toBe('favorite');
        expect($lightheartedPair['valueB'])->toBe('avoid');
    });

    it('computed standaloneFlags reflect loaded state', function () {
        $component = Livewire::test(VibePreferencePicker::class, [
            'preferences' => [
                'atmospheric' => 'avoid',
            ],
        ]);

        $standalone = $component->instance()->getStandaloneFlags;
        $atmospheric = collect($standalone)->first(fn ($s) => $s['flag'] === 'atmospheric');

        expect($atmospheric)->not->toBeNull();
        expect($atmospheric['value'])->toBe('avoid');
    });
});

// ═══════════════════════════════════════════════════════════
// GAME TYPE FILTERING
// ═══════════════════════════════════════════════════════════

describe('Game type filtering', function () {
    it('board_game mode clears preferences for non-board-game flags', function () {
        $component = Livewire::test(VibePreferencePicker::class, [
            'gameType' => 'board_game',
            'preferences' => [
                'sandbox' => 'favorite',       // ttrpg-only — should be cleared
                'atmospheric' => 'favorite',   // board_game — should be kept
                'dungeon-crawl' => 'avoid',    // ttrpg-only — should be cleared
            ],
        ]);

        $prefs = $component->get('preferences');
        expect($prefs['atmospheric'])->toBe('favorite');
        expect($prefs['sandbox'])->toBeNull();
        expect($prefs['dungeon-crawl'])->toBeNull();
    });

    it('board_game paired flags still support toggle', function () {
        Livewire::test(VibePreferencePicker::class, [
            'gameType' => 'board_game',
        ])
            ->call('togglePaired', 'lighthearted', 'serious', 'favorite')
            ->assertSet('preferences.lighthearted', 'favorite')
            ->assertSet('preferences.serious', 'avoid');
    });

    it('board_game standalone flags still cycle', function () {
        Livewire::test(VibePreferencePicker::class, [
            'gameType' => 'board_game',
        ])
            ->call('toggleStandalone', 'atmospheric')
            ->assertSet('preferences.atmospheric', 'favorite');
    });

    it('ttrpg mode shows all flags', function () {
        $component = Livewire::test(VibePreferencePicker::class, [
            'gameType' => 'ttrpg',
        ]);

        $paired = $component->instance()->getPairedFlags;
        $standalone = $component->instance()->getStandaloneFlags;

        expect($paired)->toHaveCount(8);

        // All standalone flags (same as unfiltered)
        $standaloneValues = vibeStandaloneValues();
        expect($standalone)->toHaveCount(count($standaloneValues));
    });

});

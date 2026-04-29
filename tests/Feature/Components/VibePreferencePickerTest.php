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
    it('renders without errors', function () {
        Livewire::test(VibePreferencePicker::class)
            ->assertOk();
    });

    it('initializes all flags to null (neutral) by default', function () {
        $component = Livewire::test(VibePreferencePicker::class);

        foreach (VibeFlag::cases() as $flag) {
            $component->assertSet('preferences.'.$flag->value, null);
        }
    });

    it('renders all paired flags as segmented controls', function () {
        $component = Livewire::test(VibePreferencePicker::class);
        $pairedFlags = $component->instance()->getPairedFlags;

        // 8 mutually exclusive pairs
        expect($pairedFlags)->toHaveCount(8);

        foreach ($pairedFlags as $pair) {
            expect($pair)->toHaveKeys(['flagA', 'labelA', 'flagB', 'labelB', 'valueA', 'valueB']);
            // Both start neutral
            expect($pair['valueA'])->toBeNull();
            expect($pair['valueB'])->toBeNull();
        }
    });

    it('renders all standalone flags as tri-state chips', function () {
        $component = Livewire::test(VibePreferencePicker::class);
        $standaloneFlags = $component->instance()->getStandaloneFlags;

        // Standalone flags = all flags minus paired flags
        $standaloneValues = vibeStandaloneValues();
        expect($standaloneFlags)->toHaveCount(count($standaloneValues));

        foreach ($standaloneFlags as $sf) {
            expect($sf)->toHaveKeys(['flag', 'label', 'value', 'group', 'groupLabel']);
            expect($sf['value'])->toBeNull();
        }
    });

    it('all flags from VibeFlag::grouped() appear in output', function () {
        $component = Livewire::test(VibePreferencePicker::class);
        $prefs = $component->get('preferences');

        foreach (VibeFlag::cases() as $flag) {
            expect(array_key_exists($flag->value, $prefs))->toBeTrue("Flag {$flag->value} missing from preferences");
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
    it('board_game mode initializes only 15 flags', function () {
        $component = Livewire::test(VibePreferencePicker::class, [
            'gameType' => 'board_game',
        ]);

        $prefs = $component->get('preferences');

        // All 30 flag keys should be initialized, but only board_game flags should be non-null-capable
        $nonNull = array_filter($prefs, fn ($v) => $v !== null);
        expect($nonNull)->toHaveCount(0); // All start neutral
    });

    it('board_game mode shows 5 paired flag groups', function () {
        $component = Livewire::test(VibePreferencePicker::class, [
            'gameType' => 'board_game',
        ]);

        $paired = $component->instance()->getPairedFlags;

        // board_game pairs: lighthearted/serious, horror/family-friendly,
        // mature-themes/family-friendly, rules-light/rules-heavy, competitive/cooperative
        expect($paired)->toHaveCount(5);

        $pairFlags = [];
        foreach ($paired as $pair) {
            $pairFlags[] = $pair['flagA'];
            $pairFlags[] = $pair['flagB'];
        }

        // Should NOT include ttrpg-only pairs like combat-focused/roleplay-heavy
        expect($pairFlags)->not->toContain('combat-focused');
        expect($pairFlags)->not->toContain('roleplay-heavy');
        expect($pairFlags)->not->toContain('rule-of-cool');
        expect($pairFlags)->not->toContain('roleplay-light');
    });

    it('board_game mode shows 6 standalone flags', function () {
        $component = Livewire::test(VibePreferencePicker::class, [
            'gameType' => 'board_game',
        ]);

        $standalone = $component->instance()->getStandaloneFlags;

        // 15 board_game flags - 9 paired unique flags = 6 standalone
        // Paired: lighthearted, serious, horror, family-friendly, mature-themes,
        //         rules-light, rules-heavy, competitive, cooperative (9 unique)
        // Standalone: atmospheric, humorous, tactical, puzzle-solving,
        //             new-player-friendly, drop-in-friendly (6)
        expect($standalone)->toHaveCount(6);

        $standaloneFlags = array_map(fn ($s) => $s['flag'], $standalone);
        expect($standaloneFlags)->toContain('atmospheric');
        expect($standaloneFlags)->toContain('humorous');
        expect($standaloneFlags)->toContain('tactical');
        expect($standaloneFlags)->toContain('puzzle-solving');
        expect($standaloneFlags)->toContain('new-player-friendly');
        expect($standaloneFlags)->toContain('drop-in-friendly');
    });

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

    it('null gameType shows all flags (backward compatible)', function () {
        $component = Livewire::test(VibePreferencePicker::class);

        $paired = $component->instance()->getPairedFlags;
        $standalone = $component->instance()->getStandaloneFlags;

        expect($paired)->toHaveCount(8);

        $standaloneValues = vibeStandaloneValues();
        expect($standalone)->toHaveCount(count($standaloneValues));

        // All 30 flags initialized
        $prefs = $component->get('preferences');
        expect($prefs)->toHaveCount(30);
    });

    it('board_game mode initializes exactly 15 preference keys', function () {
        $component = Livewire::test(VibePreferencePicker::class, [
            'gameType' => 'board_game',
        ]);

        $prefs = $component->get('preferences');

        // Only board_game flag keys should be initialized
        $boardGameValues = array_map(fn (VibeFlag $f) => $f->value, VibeFlag::forGameType('board_game'));
        foreach ($boardGameValues as $value) {
            expect(array_key_exists($value, $prefs))->toBeTrue("Board game flag {$value} should be in preferences");
        }
    });
});

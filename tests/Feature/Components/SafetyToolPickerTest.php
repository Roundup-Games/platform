<?php

use App\Enums\SafetyTool;
use App\Enums\SafetyToolCategory;
use App\Livewire\Components\SafetyToolPicker;
use Livewire\Livewire;

// ═══════════════════════════════════════════════════════════
// RENDERING
// ═══════════════════════════════════════════════════════════

describe('Rendering', function () {
    it('renders without errors', function () {
        Livewire::test(SafetyToolPicker::class)
            ->assertOk();
    })->group('smoke');

    it('initializes with empty selected, text, and note', function () {
        Livewire::test(SafetyToolPicker::class)
            ->assertSet('selected', [])
            ->assertSet('linesAndVeilsText', '')
            ->assertSet('customNote', '');
    });

    it('renders all 9 tools grouped by 3 categories', function () {
        $component = Livewire::test(SafetyToolPicker::class);
        $grouped = $component->instance()->getGroupedTools;

        expect($grouped)->toHaveCount(3);

        // Before: 3 tools
        expect($grouped[0]['category'])->toBe(SafetyToolCategory::Before);
        expect($grouped[0]['tools'])->toHaveCount(3);

        // During: 4 tools
        expect($grouped[1]['category'])->toBe(SafetyToolCategory::During);
        expect($grouped[1]['tools'])->toHaveCount(4);

        // After: 2 tools
        expect($grouped[2]['category'])->toBe(SafetyToolCategory::After);
        expect($grouped[2]['tools'])->toHaveCount(2);
    });

    it('each tool has required UI properties', function () {
        $component = Livewire::test(SafetyToolPicker::class);
        $grouped = $component->instance()->getGroupedTools;

        foreach ($grouped as $group) {
            foreach ($group['tools'] as $tool) {
                expect($tool)->toHaveKeys([
                    'value', 'label', 'shortDescription', 'fullDescription',
                    'supportsText', 'textPlaceholder', 'isSelected',
                ]);
                expect($tool['label'])->not->toBeEmpty();
                expect($tool['shortDescription'])->not->toBeEmpty();
                expect($tool['fullDescription'])->not->toBeEmpty();
                expect($tool['isSelected'])->toBeFalse();
            }
        }
    });

    it('only Lines & Veils supports text', function () {
        $component = Livewire::test(SafetyToolPicker::class);
        $grouped = $component->instance()->getGroupedTools;

        $textSupportCount = 0;
        foreach ($grouped as $group) {
            foreach ($group['tools'] as $tool) {
                if ($tool['supportsText']) {
                    $textSupportCount++;
                    expect($tool['value'])->toBe('lines-and-veils');
                    expect($tool['textPlaceholder'])->not->toBeEmpty();
                } else {
                    expect($tool['textPlaceholder'])->toBe('');
                }
            }
        }

        expect($textSupportCount)->toBe(1);
    });
});

// ═══════════════════════════════════════════════════════════
// TOOL TOGGLING
// ═══════════════════════════════════════════════════════════

describe('Tool toggling', function () {
    it('selecting a tool adds it to selected array', function () {
        Livewire::test(SafetyToolPicker::class)
            ->call('toggleTool', 'session-zero')
            ->assertSet('selected', ['session-zero']);
    })->group('smoke');

    it('selecting multiple tools accumulates them', function () {
        Livewire::test(SafetyToolPicker::class)
            ->call('toggleTool', 'session-zero')
            ->call('toggleTool', 'x-card')
            ->call('toggleTool', 'stars-and-wishes')
            ->assertSet('selected', ['session-zero', 'x-card', 'stars-and-wishes']);
    });

    it('deselecting a tool removes it from selected array', function () {
        Livewire::test(SafetyToolPicker::class)
            ->call('toggleTool', 'session-zero')
            ->call('toggleTool', 'x-card')
            ->call('toggleTool', 'session-zero')
            ->assertSet('selected', ['x-card']);
    });

    it('toggling an invalid tool does nothing', function () {
        Livewire::test(SafetyToolPicker::class)
            ->call('toggleTool', 'nonexistent-tool')
            ->assertSet('selected', []);
    });

    it('deselecting Lines & Veils clears the text', function () {
        Livewire::test(SafetyToolPicker::class)
            ->call('toggleTool', 'lines-and-veils')
            ->set('linesAndVeilsText', 'No spiders, no gore')
            ->call('toggleTool', 'lines-and-veils')
            ->assertSet('selected', [])
            ->assertSet('linesAndVeilsText', '');
    });

    it('deselecting a non-text tool does not clear Lines & Veils text', function () {
        Livewire::test(SafetyToolPicker::class)
            ->call('toggleTool', 'lines-and-veils')
            ->set('linesAndVeilsText', 'No spiders')
            ->call('toggleTool', 'session-zero')
            ->assertSet('linesAndVeilsText', 'No spiders');
    });

    it('dispatches safety-tools-changed event on toggle', function () {
        Livewire::test(SafetyToolPicker::class)
            ->call('toggleTool', 'session-zero')
            ->assertDispatched('safety-tools-changed');
    });

    it('re-selecting a tool keeps it in the array once (not duplicated)', function () {
        $component = Livewire::test(SafetyToolPicker::class)
            ->call('toggleTool', 'session-zero')
            ->call('toggleTool', 'session-zero')
            ->call('toggleTool', 'session-zero');

        $component->assertSet('selected', ['session-zero']);
    });
});

// ═══════════════════════════════════════════════════════════
// LINES & VEILS TEXT
// ═══════════════════════════════════════════════════════════

describe('Lines & Veils text', function () {
    it('updating Lines & Veils text dispatches change event', function () {
        Livewire::test(SafetyToolPicker::class)
            ->call('toggleTool', 'lines-and-veils')
            ->set('linesAndVeilsText', 'No spiders')
            ->assertDispatched('safety-tools-changed');
    });
});

// ═══════════════════════════════════════════════════════════
// CUSTOM NOTE
// ═══════════════════════════════════════════════════════════

describe('Custom note', function () {
    it('updating custom note dispatches change event', function () {
        Livewire::test(SafetyToolPicker::class)
            ->set('customNote', 'We take breaks every hour')
            ->assertDispatched('safety-tools-changed');
    });
});

// ═══════════════════════════════════════════════════════════
// SAFETY RULES COMPUTED PROPERTY
// ═══════════════════════════════════════════════════════════

describe('Safety rules computed property', function () {
    it('returns structured shape with tools, text, and note', function () {
        $component = Livewire::test(SafetyToolPicker::class)
            ->call('toggleTool', 'session-zero')
            ->call('toggleTool', 'lines-and-veils')
            ->set('linesAndVeilsText', 'No spiders')
            ->set('customNote', 'Take breaks');

        $rules = $component->instance()->getSafetyRules();

        expect($rules)->toHaveKeys(['tools', 'lines_and_veils_text', 'custom_note']);
        expect($rules['tools'])->toBe(['session-zero', 'lines-and-veils']);
        expect($rules['lines_and_veils_text'])->toBe('No spiders');
        expect($rules['custom_note'])->toBe('Take breaks');
    });

    it('returns empty defaults when nothing is selected', function () {
        $component = Livewire::test(SafetyToolPicker::class);
        $rules = $component->instance()->getSafetyRules();

        expect($rules['tools'])->toBe([]);
        expect($rules['lines_and_veils_text'])->toBe('');
        expect($rules['custom_note'])->toBe('');
    });
});

// ═══════════════════════════════════════════════════════════
// GROUPED TOOLS COMPUTED PROPERTY
// ═══════════════════════════════════════════════════════════

describe('Grouped tools computed property', function () {
    it('reflects selected state correctly', function () {
        $component = Livewire::test(SafetyToolPicker::class)
            ->call('toggleTool', 'session-zero')
            ->call('toggleTool', 'x-card');

        $grouped = $component->instance()->getGroupedTools;

        $sessionZero = collect($grouped[0]['tools'])->first(fn ($t) => $t['value'] === 'session-zero');
        $openDoor = collect($grouped[0]['tools'])->first(fn ($t) => $t['value'] === 'open-door');
        $xCard = collect($grouped[1]['tools'])->first(fn ($t) => $t['value'] === 'x-card');
        $breaks = collect($grouped[1]['tools'])->first(fn ($t) => $t['value'] === 'breaks');

        expect($sessionZero['isSelected'])->toBeTrue();
        expect($openDoor['isSelected'])->toBeFalse();
        expect($xCard['isSelected'])->toBeTrue();
        expect($breaks['isSelected'])->toBeFalse();
    });

    it('total tool count across all groups equals 9', function () {
        $component = Livewire::test(SafetyToolPicker::class);
        $grouped = $component->instance()->getGroupedTools;

        $totalTools = array_sum(array_map(fn ($g) => count($g['tools']), $grouped));
        expect($totalTools)->toBe(9);
    });
});

// ═══════════════════════════════════════════════════════════
// MOUNT WITH EXISTING DATA
// ═══════════════════════════════════════════════════════════

describe('Mount with existing data', function () {
    it('loads provided selected tools on mount', function () {
        Livewire::test(SafetyToolPicker::class, [
            'selected' => ['session-zero', 'x-card'],
        ])
            ->assertSet('selected', ['session-zero', 'x-card']);
    });

    it('loads provided Lines & Veils text on mount', function () {
        Livewire::test(SafetyToolPicker::class, [
            'selected' => ['lines-and-veils'],
            'linesAndVeilsText' => 'No gore',
        ])
            ->assertSet('linesAndVeilsText', 'No gore');
    });

    it('loads provided custom note on mount', function () {
        Livewire::test(SafetyToolPicker::class, [
            'customNote' => 'Check in after every session',
        ])
            ->assertSet('customNote', 'Check in after every session');
    });

    it('loads all properties together', function () {
        $component = Livewire::test(SafetyToolPicker::class, [
            'selected' => ['session-zero', 'lines-and-veils', 'stars-and-wishes'],
            'linesAndVeilsText' => 'No spiders, fade-to-black for romance',
            'customNote' => 'We use a safeword',
        ]);

        $component
            ->assertSet('selected', ['session-zero', 'lines-and-veils', 'stars-and-wishes'])
            ->assertSet('linesAndVeilsText', 'No spiders, fade-to-black for romance')
            ->assertSet('customNote', 'We use a safeword');

        $rules = $component->instance()->getSafetyRules();
        expect($rules['tools'])->toBe(['session-zero', 'lines-and-veils', 'stars-and-wishes']);
        expect($rules['lines_and_veils_text'])->toBe('No spiders, fade-to-black for romance');
        expect($rules['custom_note'])->toBe('We use a safeword');
    });
});

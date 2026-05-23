<?php

use App\Livewire\Components\SafetyToolPicker;
use Livewire\Livewire;

// ═══════════════════════════════════════════════════════════
// RENDERING
// ═══════════════════════════════════════════════════════════

describe('Rendering', function () {
    it('each tool has required UI properties (representative check)', function () {
        $component = Livewire::test(SafetyToolPicker::class);
        $grouped = $component->instance()->getGroupedTools;

        // Spot-check 3 representative tools across different groups
        $sessionZero = collect($grouped[0]['tools'])->first(fn ($t) => $t['value'] === 'session-zero');
        $xCard = collect($grouped[1]['tools'])->first(fn ($t) => $t['value'] === 'x-card');
        $stars = collect($grouped[2]['tools'])->first(fn ($t) => $t['value'] === 'stars-and-wishes');

        foreach ([$sessionZero, $xCard, $stars] as $tool) {
            expect($tool)->toHaveKeys([
                'value', 'label', 'shortDescription', 'fullDescription',
                'supportsText', 'textPlaceholder', 'isSelected',
            ]);
            expect($tool['label'])->not->toBeEmpty();
            expect($tool['shortDescription'])->not->toBeEmpty();
            expect($tool['fullDescription'])->not->toBeEmpty();
        }
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

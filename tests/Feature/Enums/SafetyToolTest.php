<?php

use App\Enums\SafetyTool;
use App\Enums\SafetyToolCategory;

describe('SafetyToolCategory', function () {
    it('returns translated labels', function () {
        expect(SafetyToolCategory::Before->label())->toBe(__('safety.category_before'));
        expect(SafetyToolCategory::During->label())->toBe(__('safety.category_during'));
        expect(SafetyToolCategory::After->label())->toBe(__('safety.category_after'));
    });
});

describe('SafetyTool', function () {
    it('returns translated labels', function () {
        expect(SafetyTool::SessionZero->label())->toBe(__('safety.tool_session_zero'));
        expect(SafetyTool::LinesAndVeils->label())->toBe(__('safety.tool_lines_and_veils'));
        expect(SafetyTool::OpenDoor->label())->toBe(__('safety.tool_open_door'));
        expect(SafetyTool::XCard->label())->toBe(__('safety.tool_x_card'));
        expect(SafetyTool::XnoCard->label())->toBe(__('safety.tool_xno_card'));
        expect(SafetyTool::ScriptChange->label())->toBe(__('safety.tool_script_change'));
        expect(SafetyTool::Breaks->label())->toBe(__('safety.tool_breaks'));
        expect(SafetyTool::StarsAndWishes->label())->toBe(__('safety.tool_stars_and_wishes'));
        expect(SafetyTool::Debriefing->label())->toBe(__('safety.tool_debriefing'));
    });

    it('returns non-empty short descriptions for all tools', function () {
        foreach (SafetyTool::cases() as $tool) {
            expect($tool->shortDescription())->not->toBeEmpty("{$tool->value} should have a short description");
        }
    });

    it('returns non-empty full descriptions for all tools', function () {
        foreach (SafetyTool::cases() as $tool) {
            expect($tool->fullDescription())->not->toBeEmpty("{$tool->value} should have a full description");
        }
    });

    it('full descriptions are longer than short descriptions', function () {
        foreach (SafetyTool::cases() as $tool) {
            expect(strlen($tool->fullDescription()))->toBeGreaterThan(
                strlen($tool->shortDescription()),
                "{$tool->value} full description should be longer than short"
            );
        }
    });

    it('maps each tool to the correct category', function () {
        expect(SafetyTool::SessionZero->category())->toBe(SafetyToolCategory::Before);
        expect(SafetyTool::LinesAndVeils->category())->toBe(SafetyToolCategory::Before);
        expect(SafetyTool::OpenDoor->category())->toBe(SafetyToolCategory::Before);

        expect(SafetyTool::XCard->category())->toBe(SafetyToolCategory::During);
        expect(SafetyTool::XnoCard->category())->toBe(SafetyToolCategory::During);
        expect(SafetyTool::ScriptChange->category())->toBe(SafetyToolCategory::During);
        expect(SafetyTool::Breaks->category())->toBe(SafetyToolCategory::During);

        expect(SafetyTool::StarsAndWishes->category())->toBe(SafetyToolCategory::After);
        expect(SafetyTool::Debriefing->category())->toBe(SafetyToolCategory::After);
    });

    it('only LinesAndVeils supports text', function () {
        foreach (SafetyTool::cases() as $tool) {
            $expected = $tool === SafetyTool::LinesAndVeils;
            expect($tool->supportsText())->toBe($expected, "{$tool->value} supportsText should be " . ($expected ? 'true' : 'false'));
        }
    });

    it('LinesAndVeils has a text placeholder', function () {
        expect(SafetyTool::LinesAndVeils->textPlaceholder())->not->toBeEmpty();
    });

    it('non-text tools return empty placeholder', function () {
        foreach (SafetyTool::cases() as $tool) {
            if ($tool !== SafetyTool::LinesAndVeils) {
                expect($tool->textPlaceholder())->toBeEmpty("{$tool->value} should have empty placeholder");
            }
        }
    });

    it('grouped() returns all three categories', function () {
        $grouped = SafetyTool::grouped();
        expect($grouped)->toHaveKeys(['before', 'during', 'after']);
    })->group('smoke');

    it('grouped() has translated category labels', function () {
        $grouped = SafetyTool::grouped();
        expect($grouped['before']['label'])->toBe(__('safety.category_before'));
        expect($grouped['during']['label'])->toBe(__('safety.category_during'));
        expect($grouped['after']['label'])->toBe(__('safety.category_after'));
    });

    it('grouped() distributes tools correctly', function () {
        $grouped = SafetyTool::grouped();
        expect($grouped['before']['options'])->toHaveCount(3);
        expect($grouped['during']['options'])->toHaveCount(4);
        expect($grouped['after']['options'])->toHaveCount(2);
    });

    it('recommended() returns the starter set of 5 tools', function () {
        $recommended = SafetyTool::recommended();
        expect($recommended)->toHaveCount(5);
        expect($recommended)->toContain(
            SafetyTool::SessionZero,
            SafetyTool::LinesAndVeils,
            SafetyTool::XCard,
            SafetyTool::OpenDoor,
            SafetyTool::Breaks,
        );
    });

});

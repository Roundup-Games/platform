<?php

use App\Enums\SafetyTool;
use App\Enums\SafetyToolCategory;

describe('SafetyToolCategory', function () {
    it('has all three cases', function () {
        expect(SafetyToolCategory::cases())->toHaveCount(3);
        expect(SafetyToolCategory::values())->toBe(['before', 'during', 'after']);
    });

    it('returns correct labels', function () {
        expect(SafetyToolCategory::Before->label())->toBe('Before the Game');
        expect(SafetyToolCategory::During->label())->toBe('During the Game');
        expect(SafetyToolCategory::After->label())->toBe('After the Game');
    });

    it('provides values() for validation', function () {
        $values = SafetyToolCategory::values();
        expect($values)->toBeArray();
        expect($values)->toContain('before', 'during', 'after');
    });
});

describe('SafetyTool', function () {
    it('has exactly 9 cases', function () {
        expect(SafetyTool::cases())->toHaveCount(9);
    });

    it('returns correct values for all cases', function () {
        $expected = [
            'session-zero', 'lines-and-veils', 'open-door',
            'x-card', 'xno-card', 'script-change', 'breaks',
            'stars-and-wishes', 'debriefing',
        ];
        expect(SafetyTool::values())->toBe($expected);
    });

    it('returns correct labels', function () {
        expect(SafetyTool::SessionZero->label())->toBe('Session Zero');
        expect(SafetyTool::LinesAndVeils->label())->toBe('Lines & Veils');
        expect(SafetyTool::OpenDoor->label())->toBe('Open Door Policy');
        expect(SafetyTool::XCard->label())->toBe('X-Card');
        expect(SafetyTool::XnoCard->label())->toBe('X·No Card');
        expect(SafetyTool::ScriptChange->label())->toBe('Script Change');
        expect(SafetyTool::Breaks->label())->toBe('Breaks');
        expect(SafetyTool::StarsAndWishes->label())->toBe('Stars & Wishes');
        expect(SafetyTool::Debriefing->label())->toBe('Debriefing');
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
    });

    it('grouped() contains all 9 tools across categories', function () {
        $grouped = SafetyTool::grouped();
        $allValues = [];
        foreach ($grouped as $group) {
            $allValues = array_merge($allValues, array_keys($group['options']));
        }
        expect($allValues)->toHaveCount(9);
        expect($allValues)->toBe(SafetyTool::values());
    });

    it('grouped() has correct category labels', function () {
        $grouped = SafetyTool::grouped();
        expect($grouped['before']['label'])->toBe('Before the Game');
        expect($grouped['during']['label'])->toBe('During the Game');
        expect($grouped['after']['label'])->toBe('After the Game');
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

    it('values() returns flat string array', function () {
        $values = SafetyTool::values();
        expect($values)->toBeArray();
        foreach ($values as $value) {
            expect($value)->toBeString();
        }
    });
});

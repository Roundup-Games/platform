<?php

use App\Enums\SafetyTool;
use App\Enums\SafetyToolCategory;

describe('SafetyTool', function () {
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

    it('grouped() returns all three categories', function () {
        $grouped = SafetyTool::grouped();
        expect($grouped)->toHaveKeys(['before', 'during', 'after']);
    })->group('smoke');

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

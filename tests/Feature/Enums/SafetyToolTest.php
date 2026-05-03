<?php

use App\Enums\SafetyTool;
use App\Enums\SafetyToolCategory;

describe('SafetyTool', function () {
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

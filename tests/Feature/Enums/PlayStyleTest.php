<?php

use App\Enums\PlayStyle;

describe('PlayStyle Enum', function () {
    it('label() returns a translatable string for each case', function () {
        foreach (PlayStyle::cases() as $case) {
            $label = $case->label();
            expect($label)->toBeString();
            expect($label)->not->toBeEmpty("{$case->value} label should not be empty");
            // Labels should come from the translator, not be the raw enum value
            expect($label)->not->toBe($case->value, "{$case->value} label should be a translation, not the raw value");
        }
    });

    it('description() returns a non-empty string for each case', function () {
        foreach (PlayStyle::cases() as $case) {
            $desc = $case->description();
            expect($desc)->toBeString();
            expect($desc)->not->toBeEmpty("{$case->value} description should not be empty");
        }
    });

    it('icon() returns a Material Symbols icon name for each case', function () {
        $expectedIcons = [
            'narrative-first' => 'auto_stories',
            'tactical' => 'target',
            'osr' => 'shield',
            'sandbox' => 'explore',
            'horror' => 'visibility',
        ];

        foreach (PlayStyle::cases() as $case) {
            $icon = $case->icon();
            expect($icon)->toBeString();
            expect($icon)->not->toBeEmpty("{$case->value} icon should not be empty");
            expect($icon)->toBe($expectedIcons[$case->value], "{$case->value} icon should match expected value");
        }
    });

    it('grouped() returns array with required keys', function () {
        $grouped = PlayStyle::grouped();

        expect($grouped)->toHaveKey('play_styles');
        expect($grouped['play_styles'])->toHaveKeys(['label', 'options', 'descriptions']);
    });

    it('grouped() label is a translatable string', function () {
        $grouped = PlayStyle::grouped();
        $label = $grouped['play_styles']['label'];

        expect($label)->toBeString();
        expect($label)->not->toBeEmpty();
    });

    it('categorySlugs() returns non-empty array for each case', function () {
        foreach (PlayStyle::cases() as $case) {
            $slugs = $case->categorySlugs();
            expect($slugs)->toBeArray();
            expect($slugs)->not->toBeEmpty("{$case->value} should have at least one category slug");
        }
    });

    it('categorySlugs() returns only string values', function () {
        foreach (PlayStyle::cases() as $case) {
            $slugs = $case->categorySlugs();
            foreach ($slugs as $slug) {
                expect($slug)->toBeString("{$case->value} categorySlugs should contain only strings");
            }
        }
    });

    it('categorySlugs() values are valid slug format', function () {
        foreach (PlayStyle::cases() as $case) {
            foreach ($case->categorySlugs() as $slug) {
                expect($slug)->toMatch('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', "Slug '{$slug}' in {$case->value} should be kebab-case");
            }
        }
    });

    it('some categories intentionally overlap across play styles', function () {
        $allSlugs = [];
        foreach (PlayStyle::cases() as $case) {
            $allSlugs = array_merge($allSlugs, $case->categorySlugs());
        }

        $uniqueSlugs = array_unique($allSlugs);
        // If count differs, some slugs appear in multiple play styles (intentional overlap)
        $hasOverlap = count($allSlugs) !== count($uniqueSlugs);
        expect($hasOverlap)->toBeTrue('Editorial design intentionally maps some categories to multiple play styles');
    });
});

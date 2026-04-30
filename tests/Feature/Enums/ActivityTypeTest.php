<?php

use App\Enums\ActivityType;

describe('ActivityType', function () {
    it('returns translated labels for all cases', function () {
        foreach (ActivityType::cases() as $type) {
            $label = $type->label();
            expect($label)->not->toBeEmpty("{$type->value} should have a label");
            expect($label)->not->toBe($type->value, "{$type->value} label should be translated");
        }
    });

    it('returns a Material Symbol icon for every case', function () {
        foreach (ActivityType::cases() as $type) {
            $icon = $type->icon();
            expect($icon)->not->toBeEmpty("{$type->value} should have an icon");
            expect($icon)->toBeString();
        }
    });

});

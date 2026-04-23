<?php

use App\Enums\ActivityType;

describe('ActivityType', function () {
    it('has exactly 12 cases', function () {
        expect(ActivityType::cases())->toHaveCount(12);
    });

    it('returns correct values for all cases', function () {
        $expected = [
            'game_created',
            'game_completed',
            'game_canceled',
            'campaign_created',
            'campaign_completed',
            'campaign_canceled',
            'player_joined',
            'review_received',
            'follow_received',
            'invitation_received',
            'invitation_accepted',
            'session_scheduled',
        ];
        expect(ActivityType::values())->toBe($expected);
    });

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

    it('values() returns flat string array', function () {
        $values = ActivityType::values();
        expect($values)->toBeArray();
        foreach ($values as $value) {
            expect($value)->toBeString();
        }
    });

    it('every case has a unique value', function () {
        $values = ActivityType::values();
        expect($values)->toHaveCount(count(array_unique($values)));
    });
});

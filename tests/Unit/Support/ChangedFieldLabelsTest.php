<?php

use App\Support\ChangedFieldLabels;

// Guards the "Changes: location_id" regression (M081 follow-up): machine
// columns are the only thing callers may pass to change notifications, and
// this map is the single place they become human words — per recipient
// locale, de-duplicated, and with a leak-proof fallback.

describe('ChangedFieldLabels', function () {
    it('maps every editable column to a human label', function (string $column, string $key) {
        app()->setLocale('en');

        expect(ChangedFieldLabels::labelKey($column))->toBe($key)
            ->and(__(ChangedFieldLabels::labelKey($column)))->not->toBe($key);
    })->with([
        ['name', 'common.field_name'],
        ['description', 'common.field_description'],
        ['expected_duration', 'common.content_duration_hours'],
        ['session_duration', 'campaigns.field_duration'],
        ['visibility', 'common.content_visibility'],
        ['location', 'common.field_location'],
        ['location_id', 'common.field_location'],
        ['location_instructions', 'venues.field_location_instructions'],
    ]);

    it('de-duplicates the location family so a venue move reads once', function () {
        app()->setLocale('en');

        $labels = ChangedFieldLabels::forLocale(['location', 'location_id']);

        expect($labels)->toBe([__('common.field_location')]);
    });

    it('resolves labels in the requested locale', function () {
        expect(ChangedFieldLabels::forLocale(['location_id'], 'de'))
            ->toBe([__('common.field_location', [], 'de')]);
    });

    it('falls back to a generic Details label for unmapped columns — never the raw name', function () {
        app()->setLocale('en');

        $labels = ChangedFieldLabels::forLocale(['location_id', 'some_future_column']);

        expect($labels)->toBe([__('common.field_location'), __('common.field_details')])
            ->and($labels)->not->toContain('some_future_column');
    });
});

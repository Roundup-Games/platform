<?php

use App\Support\ChangedFieldLabels;
use App\Support\Edit\FieldTiers;
use Illuminate\Support\Facades\Lang;

// Guards the "Changes: location_id" regression (M081 follow-up): machine
// columns are the only thing callers may pass to change notifications, and
// this map is the single place they become human words — per recipient
// locale, de-duplicated, and with a leak-proof fallback.
//
// Since M084 (decision D313) every field FieldTiers classifies can change
// after participants confirmed, so each of them must be named here: the
// coverage pin below turns "a field added to FieldTiers without a label"
// into a failing test instead of a "Changes: Details" mail.

/**
 * Every column an edit surface may write: the two FieldTiers tier maps plus
 * the direction-dependent fields (its docblock: together they are the
 * complete set).
 *
 * @return list<string>
 */
function changedFieldLabelEditableColumns(): array
{
    return array_keys([
        ...FieldTiers::GAME_TIERS,
        ...FieldTiers::CAMPAIGN_TIERS,
        ...FieldTiers::DIRECTION_DEPENDENT,
    ]);
}

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
        ['date_time', 'common.field_date_time'],
        ['location', 'common.field_location'],
        ['location_id', 'common.field_location'],
        ['location_instructions', 'venues.field_location_instructions'],
        // Games and campaigns.
        ['game_system_id', 'games.content_game_system'],
        ['game_systems', 'games.content_game_system'],
        ['game_type', 'games.field_game_type'],
        ['min_players', 'games.field_min_players'],
        ['max_players', 'games.field_max_players'],
        ['language', 'common.content_language'],
        ['bench_mode', 'games.label_bench_mode'],
        ['safety_rules', 'safety.content_safety_tools'],
        ['experience_level', 'discovery.content_experience_level'],
        ['complexity', 'games.content_complexity_1_5'],
        ['minimum_requirements', 'games.field_minimum_requirements'],
        ['host_note', 'games.field_host_note'],
        ['cover_image', 'games.field_cover_image'],
        ['vibe_flags', 'common.content_vibe_flags'],
        // Games only.
        ['price', 'billing.content_price_eur'],
        ['min_reliability_preference', 'games.field_attendance_tolerance'],
        ['signup_cutoff_at', 'games.label_signup_cutoff'],
        // Campaigns only.
        ['price_per_session', 'campaigns.field_price_per_session_eur'],
        ['recurrence', 'campaigns.content_recurrence'],
        ['time_of_day', 'common.field_time_of_day'],
    ]);

    it('names every field FieldTiers classifies — none degrades to the generic Details label', function (string $column) {
        expect(ChangedFieldLabels::labelKey($column))->not->toBe('common.field_details');
    })->with(changedFieldLabelEditableColumns());

    // __() alone cannot prove this: a key missing from lang/de/ silently
    // resolves to its English line, so "not the raw key" holds either way.
    // hasForLocale() answers per locale, with that fallback disabled.
    it('resolves every mapped label key in English and German without leaning on the English fallback', function (string $locale, string $column) {
        $key = ChangedFieldLabels::labelKey($column);

        expect(Lang::hasForLocale($key, $locale))->toBeTrue()
            ->and(__($key, [], $locale))->toBeString()->not->toBe('');
    })->with(collect(['en', 'de'])
        ->crossJoin(changedFieldLabelEditableColumns())
        ->all());

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

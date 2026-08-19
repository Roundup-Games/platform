<?php

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\VenueType;

/**
 * Raw-key leak guard: when a translation key is missing, Laravel's translator
 * returns the dotted key itself (e.g. "games.status_scheduled"), which then
 * renders verbatim in the UI. These tests fail whenever an enum label home or
 * a dynamically-built key family loses its keys — the exact regression class
 * behind the i18n deduplication pass.
 */

// ── Datasets ──────────────────────────────────────────────────────────

/**
 * Every enum case of every app/Enums enum that defines label(), across both
 * locales. Discovered by reflection so new enums are covered automatically.
 *
 * @return array<string, array<int, string>>
 */
function enumLabelDataset(): array
{
    $datasets = [];

    foreach (['en', 'de'] as $locale) {
        foreach (glob(dirname(__DIR__, 3).'/app/Enums/*.php') as $file) {
            $enum = 'App\\Enums\\'.basename($file, '.php');

            if (! enum_exists($enum) || ! method_exists($enum, 'label')) {
                continue;
            }

            foreach ($enum::cases() as $case) {
                $datasets["{$locale} {$enum}::{$case->name}"] = [$locale, $enum, $case->value];
            }
        }
    }

    return $datasets;
}

/**
 * Exact key strings mirroring the dynamic constructions in blades and form
 * components. Each family here is built with string concatenation somewhere in
 * app/ or resources/, so static key checkers cannot verify it.
 *
 * @return array<string, array<int, string>>
 */
function dynamicFamilyDataset(): array
{
    $families = [
        // _game-sidebar.blade.php / campaign-detail.blade.php application chips
        'common.status_' => ParticipantStatus::cases(),
        // _participant-list.blade.php role chips
        'games.field_role_' => ParticipantRole::cases(),
        // VenueType enum label home (venue picker fallback path uses tryFrom)
        'venue.type_' => VenueType::cases(),
    ];

    $datasets = [];

    foreach (['en', 'de'] as $locale) {
        foreach ($families as $prefix => $cases) {
            foreach ($cases as $case) {
                $key = $prefix.$case->value;
                $datasets["{$locale} {$key}"] = [$locale, $key];
            }
        }
    }

    return $datasets;
}

// ── Enum label homes ──────────────────────────────────────────────────

describe('Enum Label Translation', function () {
    it('renders a translated label for every case of every enum with a label() method in :locale', function (string $locale, string $enum, string $value) {
        app()->setLocale($locale);

        $label = $enum::from($value)->label();

        $domains = collect(glob(lang_path('en/*.php')))
            ->map(fn (string $file) => basename($file, '.php'))
            ->implode('|');

        expect($label)->toBeString()
            ->and($label)->not->toBeEmpty()
            // A leak always starts with a real translation domain followed by a dot.
            ->and($label)->not->toMatch("#^(?:{$domains})\\.#");
    })->with(fn () => enumLabelDataset());
});

// ── Dynamically-built blade/form key families ─────────────────────────

describe('Dynamic Key Families', function () {
    it('resolves :key (used by a blade/form key builder) in :locale', function (string $locale, string $key) {
        app()->setLocale($locale);

        expect(__($key))->not->toBe($key);
    })->with(fn () => dynamicFamilyDataset());
});

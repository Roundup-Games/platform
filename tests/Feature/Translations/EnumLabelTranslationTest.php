<?php

use App\Enums\ParticipantStatus;
use App\Enums\Recurrence;

/**
 * Raw-key leak guard: when a translation key is missing, Laravel's translator
 * returns the dotted key itself (e.g. "games.status_scheduled"), which then
 * renders verbatim in the UI. These tests fail whenever an enum label home or
 * a dynamically-built key family loses its keys — the exact regression class
 * behind the i18n deduplication pass.
 */

// ── Datasets ──────────────────────────────────────────────────────────

/**
 * Every translated-string method of every app/Enums enum, across both
 * locales. Discovered by reflection so new enums and new translated methods
 * are covered automatically.
 *
 * textPlaceholder() is deliberately excluded: it intentionally returns an
 * empty string for cases without a placeholder hint.
 *
 * @return array<string, array<int, string>>
 */
function enumLabelDataset(): array
{
    $methods = ['label', 'description', 'shortDescription', 'fullDescription'];

    $datasets = [];

    foreach (['en', 'de'] as $locale) {
        foreach (glob(dirname(__DIR__, 3).'/app/Enums/*.php') as $file) {
            $enum = 'App\\Enums\\'.basename($file, '.php');

            if (! enum_exists($enum)) {
                continue;
            }

            foreach ($methods as $method) {
                if (! method_exists($enum, $method)) {
                    continue;
                }

                foreach ($enum::cases() as $case) {
                    $datasets["{$locale} {$enum}::{$case->name}::{$method}"] = [$locale, $enum, $case->value, $method];
                }
            }
        }
    }

    return $datasets;
}

/**
 * Every key the string-backed labelFor() helpers can construct. The source
 * columns are plain strings (not enum-cast), so these families cannot be
 * verified through the enum dataset.
 *
 * @return array<string, array<int, string>>
 */
function labelForDataset(): array
{
    $datasets = [];

    foreach (['en', 'de'] as $locale) {
        foreach (ParticipantStatus::cases() as $case) {
            $key = "common.status_{$case->value}";
            $datasets["{$locale} {$key}"] = [$locale, ParticipantStatus::class, $key];
        }
        foreach (Recurrence::cases() as $case) {
            $key = "campaigns.content_{$case->value}";
            $datasets["{$locale} {$key}"] = [$locale, Recurrence::class, $key];
        }
    }

    return $datasets;
}

/**
 * Every key the entity-type interpolation in notifications can construct.
 * RoutesGameOrCampaign::getEntityType() returns 'game' or 'campaign', and the
 * notification classes interpolate it as __("..._{$type}_..."). These are the
 * only translation keys still built by runtime interpolation.
 *
 * @return array<string, array<int, string>>
 */
function entityTypeInterpolationDataset(): array
{
    $suffixes = [
        'notifications.subject_{}_invitation',
        'notifications.body_{}_invitation',
        'notifications.category_{}_invitation',
        'notifications.push_body_{}_invitation',
        'notifications.subject_{}_completed',
        'notifications.body_{}_completed',
        'notifications.subject_{}_updated',
        'notifications.body_{}_updated',
        'notifications.subject_{}_cancelled',
        'notifications.action_view_{}',
    ];

    $datasets = [];

    foreach (['en', 'de'] as $locale) {
        foreach (['game', 'campaign'] as $type) {
            foreach ($suffixes as $suffix) {
                $key = str_replace('{}', $type, $suffix);
                $datasets["{$locale} {$key}"] = [$locale, $key];
            }
        }
    }

    return $datasets;
}

// ── Enum label homes ──────────────────────────────────────────────────

describe('Enum Label Translation', function () {
    it('renders a translated label for every case of every translated enum method in :locale', function (string $locale, string $enum, string $value, string $method) {
        app()->setLocale($locale);

        $label = $enum::from($value)->{$method}();

        $domains = collect(glob(lang_path('en/*.php')))
            ->map(fn (string $file) => basename($file, '.php'))
            ->implode('|');

        expect($label)->toBeString()
            ->and($label)->not->toBeEmpty()
            // A leak always starts with a real translation domain followed by a dot.
            ->and($label)->not->toMatch("#^(?:{$domains})\\.#");
    })->with(fn () => enumLabelDataset());
});

// ── String-column families resolved via labelFor() ────────────────────

describe('LabelFor Families', function () {
    it('resolves :key for :enum::labelFor() in :locale', function (string $locale, string $enum, string $key) {
        app()->setLocale($locale);

        expect($enum::labelFor(substr($key, strpos($key, '.') + 1)))
            ->not->toBe($key);
    })->with(fn () => labelForDataset());
});

// ── Notification entity-type interpolations ───────────────────────────

describe('Entity-Type Interpolated Keys', function () {
    it('resolves :key (notification interpolation) in :locale', function (string $locale, string $key) {
        app()->setLocale($locale);

        expect(__($key))->not->toBe($key);
    })->with(fn () => entityTypeInterpolationDataset());
});

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

// ── Enum label homes ───────────────────────────────────────────────────────

describe('Enum Label Translation', function () {
    it('renders a translated label for every case of every translated enum method in every locale', function () {
        // One test looping the whole reflection matrix: identical coverage
        // per case (every key still checked on every run), but a single app
        // boot instead of ~538 — and a failure now reports EVERY leaked key
        // at once instead of one per red dataset row (harness performance
        // report, 2026-10-31).
        $domains = collect(glob(lang_path('en/*.php')))
            ->map(fn (string $file) => basename($file, '.php'))
            ->implode('|');

        $leaks = [];

        foreach (enumLabelDataset() as [$locale, $enum, $value, $method]) {
            app()->setLocale($locale);

            $label = $enum::from($value)->{$method}();

            if (! is_string($label)
                || $label === ''
                // A leak always starts with a real translation domain followed by a dot.
                || preg_match("#^(?:{$domains})\\.#", $label) === 1) {
                $leaks[] = "{$enum}::{$value}::{$method} [{$locale}] => ".(is_string($label) ? $label : gettype($label));
            }
        }

        expect($leaks)->toBeEmpty();
    });
});

// ── String-column families resolved via labelFor() ─────────────────────

describe('LabelFor Families', function () {
    it('resolves every labelFor() family key in every locale', function () {
        $leaks = [];

        foreach (labelForDataset() as [$locale, $enum, $key]) {
            app()->setLocale($locale);

            $resolved = $enum::labelFor(substr($key, strpos($key, '.') + 1));

            if ($resolved === $key) {
                $leaks[] = "{$key} [{$locale}]";
            }
        }

        expect($leaks)->toBeEmpty();
    });
});

// ── Notification entity-type interpolations ─────────────────────────────────

describe('Entity-Type Interpolated Keys', function () {
    it('resolves every notification entity-type interpolated key in every locale', function () {
        $leaks = [];

        foreach (entityTypeInterpolationDataset() as [$locale, $key]) {
            app()->setLocale($locale);

            if (__($key) === $key) {
                $leaks[] = "{$key} [{$locale}]";
            }
        }

        expect($leaks)->toBeEmpty();
    });
});

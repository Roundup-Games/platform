<?php

/**
 * Guards the granular Google Calendar sync consent copy (M083/S01/T02).
 *
 * K004 discipline: every assertion works on resolved values — no literal
 * string pins on lang/ content. A key resolves when __() returns a
 * non-empty string that differs from the key itself (Laravel echoes the
 * key back when a translation is missing).
 *
 * Key parity is asserted straight from the lang files, NOT through __():
 * with fallback_locale=en a key missing from de silently resolves to the
 * English line, so only the raw key-set comparison catches a German gap
 * before S02 renders a mixed-locale consent surface. The mirrored key
 * order between locales is pinned too — the plan asks for it, and it
 * keeps the EN/DE diff reviewable.
 *
 * Coherence checks pin the surface S02/T03 build against: the per-source
 * toggles enumerate exactly the three sync sources (games,
 * campaign_sessions, events), and the three gate-surface keys exist in
 * both locales.
 */

/**
 * @return array<int, string>
 */
function calendarSyncCopyKeys(string $locale): array
{
    /** @var array<string, string> $lines */
    $lines = require __DIR__."/../../../lang/{$locale}/calendar_sync.php";

    return array_keys($lines);
}

it('mirrors the exact key set and order between en and de', function () {
    expect(calendarSyncCopyKeys('de'))->toBe(calendarSyncCopyKeys('en'));
});

it('resolves every key to non-empty copy in the locale', function (string $locale) {
    foreach (calendarSyncCopyKeys($locale) as $key) {
        $resolved = __("calendar_sync.{$key}", [], $locale);

        expect($resolved)
            ->toBeString()
            ->not->toBe('')
            ->not->toBe("calendar_sync.{$key}");
    }
})->with(['en', 'de']);

it('enumerates exactly the three sync sources with a label and description each', function () {
    $expectedSources = ['campaign_sessions', 'events', 'games'];

    foreach (['en', 'de'] as $locale) {
        $keys = calendarSyncCopyKeys($locale);

        $sources = array_values(array_filter(array_map(
            fn (string $key): ?string => str_starts_with($key, 'label_source_') ? substr($key, strlen('label_source_')) : null,
            $keys,
        )));

        sort($sources);

        expect($sources)->toBe($expectedSources);

        foreach ($sources as $source) {
            expect($keys)->toContain("description_source_{$source}");
        }
    }
});

it('ships the three gate-surface keys in both locales', function () {
    foreach (['en', 'de'] as $locale) {
        $keys = calendarSyncCopyKeys($locale);

        expect($keys)
            ->toContain('error_connect_refused_disabled')
            ->and($keys)->toContain('error_connect_refused_not_allowlisted')
            ->and($keys)->toContain('label_mode_test_user_note');
    }
});

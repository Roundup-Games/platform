<?php

namespace App\Support\Edit;

use App\Models\Campaign;
use App\Models\Game;

/**
 * The columns a just-saved game or campaign edit really changed — the list
 * an edit announcement names (M084, decision D316).
 *
 * Eloquent keeps two records of the last save on the model, and this needs
 * both. `getChanges()` names the columns that save wrote. `getPrevious()`
 * holds each of those columns' raw stored value from BEFORE the save:
 * `syncChanges()` captures it while the originals are still the old row,
 * and `finishSave()` only then syncs them to the new values. So in any
 * after-save hook, `getOriginal()` / `getRawOriginal()` is NOT a "before"
 * — each already is the new value, and a before/after comparison read from
 * them compares the new value with itself and calls every column unchanged.
 *
 * Three things are dropped from the candidate list:
 *
 *  - `updated_at`: every save touches it; it is bookkeeping, not content.
 *  - A translatable column whose translations did not actually change. The
 *    Filament admin resources write every translatable column once per
 *    configured locale, so the first save of an English-only record adds an
 *    empty `de` key to `name` / `description` / `seo_*`. That is a storage
 *    artifact — the text a person reads is identical in every locale — and
 *    must never be announced as a "name changed" edit.
 *  - A JSON column that went from null to the empty document ({} / []) or back. An
 *    empty multi-select submits `[]`, so the first save of a record whose
 *    `vibe_flags` is null rewrites it as `[]`: the same "nothing selected".
 *    Emptying a column that HELD something is a real edit and is announced.
 *
 * Classifies only: reads the model, writes nothing, knows no lock and no
 * tiers (that is FieldTiers) and resolves no labels (that is
 * ChangedFieldLabels).
 */
final class ChangedColumns
{
    /**
     * @return list<string> Machine column names, in Eloquent's change order.
     */
    public static function of(Game|Campaign $record): array
    {
        $changed = array_keys(array_diff_key($record->getChanges(), ['updated_at' => true]));

        $storedBeforeSave = $record->getPrevious();

        $translatable = $record->getTranslatableAttributes();

        return array_values(array_filter(
            $changed,
            fn (string $column): bool => ! (in_array($column, $translatable, true)
                && self::sameTranslations($storedBeforeSave[$column] ?? null, $record, $column))
                && ! self::isEmptyJsonArtifact($storedBeforeSave[$column] ?? null, $record, $column),
        ));
    }

    /**
     * Whether a JSON column is reported as changed only because it went from
     * null to the empty document ({} / []) or back — only for columns cast to
     * array/json, where those three values all mean "nothing set".
     *
     * @param  mixed  $storedBeforeSave  The column's raw stored value before the save.
     */
    private static function isEmptyJsonArtifact(mixed $storedBeforeSave, Game|Campaign $record, string $column): bool
    {
        $cast = $record->getCasts()[$column] ?? null;

        if (! is_string($cast) || ! in_array($cast, ['array', 'json'], true)) {
            return false;
        }

        return self::isEmptyJson($storedBeforeSave)
            && self::isEmptyJson($record->getAttributes()[$column] ?? null);
    }

    private static function isEmptyJson(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === 'null') {
            return true;
        }

        return is_string($value) && json_decode($value, true) === [];
    }

    /**
     * Whether a translatable column's text is the same before and after the
     * save, locale by locale, with empty translations (null, '' or a missing
     * locale) read as "not translated".
     *
     * @param  mixed  $storedBeforeSave  The column's raw stored value (a JSON locale map) before the save.
     */
    private static function sameTranslations(mixed $storedBeforeSave, Game|Campaign $record, string $column): bool
    {
        $before = is_string($storedBeforeSave) ? json_decode($storedBeforeSave, true) : $storedBeforeSave;

        return self::translationMap($before) === self::translationMap($record->getTranslations($column));
    }

    /**
     * A locale => translation map with empty translations dropped and its keys
     * sorted, so two maps that say the same thing compare equal.
     *
     * @return array<string, string>
     */
    private static function translationMap(mixed $translations): array
    {
        if (! is_array($translations)) {
            return [];
        }

        $map = [];

        foreach ($translations as $locale => $translation) {
            if (is_string($locale) && is_string($translation) && $translation !== '') {
                $map[$locale] = $translation;
            }
        }

        ksort($map);

        return $map;
    }
}

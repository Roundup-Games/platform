<?php

namespace App\Support\Edit;

use App\Models\Campaign;
use App\Models\Game;

/**
 * The columns a just-saved game or campaign edit really changed — the list
 * an edit announcement names (M084, decision D316).
 *
 * Eloquent keeps the last save's changes on the model (`getChanges()`), and
 * that list is the input here. Two things are dropped from it:
 *
 *  - `updated_at`: every save touches it; it is bookkeeping, not content.
 *  - A translatable column whose translations did not actually change. The
 *    Filament admin resources write every translatable column once per
 *    configured locale, so the first save of an English-only record adds an
 *    empty `de` key to `name` / `description` / `seo_*`. That is a storage
 *    artifact — the text a person reads is identical in every locale — and
 *    must never be announced as a "name changed" edit.
 *  - A JSON column that went from null to the empty document (or back). An
 *    empty multi-select submits `[]`, so the first save of a record whose
 *    `vibe_flags` is null rewrites it as `[]`: the same "nothing selected".
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

        $translatable = $record->getTranslatableAttributes();

        return array_values(array_filter(
            $changed,
            fn (string $column): bool => ! (in_array($column, $translatable, true) && self::sameTranslations($record, $column))
                && ! self::isEmptyJsonArtifact($record, $column),
        ));
    }

    /**
     * Whether a JSON column is reported as changed only because it went from
     * null to the empty document ({} / []) or back — only for columns cast to
     * array/json, where those three values all mean "nothing set".
     */
    private static function isEmptyJsonArtifact(Game|Campaign $record, string $column): bool
    {
        $cast = $record->getCasts()[$column] ?? null;

        if (! is_string($cast) || ! in_array($cast, ['array', 'json'], true)) {
            return false;
        }

        return self::isEmptyJson($record->getRawOriginal($column))
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
     */
    private static function sameTranslations(Game|Campaign $record, string $column): bool
    {
        $before = $record->getOriginal($column);
        $before = is_string($before) ? json_decode($before, true) : $before;

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

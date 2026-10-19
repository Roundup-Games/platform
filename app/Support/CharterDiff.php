<?php

namespace App\Support;

use App\Dto\CharterContent;

/**
 * Field-level diff between two charter content snapshots (M070/S03).
 *
 * The single deterministic engine behind both amendment diff surfaces:
 * the GM's review-step preview in the CharterComposer and the participant
 * re-agreement view on the published charter. Both sides are normalized
 * through CharterContent::fromArray first, so raw row shapes, legacy
 * snapshots, and already-normalized content all diff on equal footing.
 *
 * Rows are emitted in a fixed order — sections in CharterContent::SECTIONS
 * order, fields ksorted within a section — so tests, the GM's material
 * badge (CharterContent::isMaterialChange), and the rendered diff always
 * agree. String-list fields (themes, boundary categories, safety tools,
 * etiquette, comms) diff as sets: an order-only shuffle produces no rows,
 * membership changes emit items_added / items_removed rows instead of a
 * single opaque changed row.
 *
 * @phpstan-type CharterDiffChange array{section: string, field: string, type: 'changed'|'added'|'removed'|'items_added'|'items_removed', old: mixed, new: mixed}
 */
final class CharterDiff
{
    /**
     * Diff two charter content snapshots into an ordered list of field-level
     * change rows. Identical content (post-normalization) yields [].
     *
     * @param  array<string, mixed>  $old  previous published content (raw or normalized)
     * @param  array<string, mixed>  $new  amended content (raw or normalized)
     * @return list<CharterDiffChange>
     */
    public static function changes(array $old, array $new): array
    {
        $old = CharterContent::fromArray($old)->toArray();
        $new = CharterContent::fromArray($new)->toArray();

        $changes = [];

        foreach (CharterContent::SECTIONS as $section) {
            $changes = array_merge(
                $changes,
                self::sectionChanges($section, self::sectionOf($old, $section), self::sectionOf($new, $section))
            );
        }

        return $changes;
    }

    /**
     * Fetch a normalized section array from mixed content, defaulting to [].
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private static function sectionOf(array $content, string $section): array
    {
        $value = $content[$section] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * Field-level diff of one normalized section. Schema v1 normalizes both
     * sides to identical key sets, so the added/removed branches only fire
     * under future schema evolution (a field that exists in one snapshot's
     * schema but not the other's) — kept so the engine never silently drops
     * a cross-schema change.
     *
     * @param  array<string, mixed>  $oldSection
     * @param  array<string, mixed>  $newSection
     * @return list<CharterDiffChange>
     */
    private static function sectionChanges(string $section, array $oldSection, array $newSection): array
    {
        $fields = array_unique([...array_keys($oldSection), ...array_keys($newSection)]);
        sort($fields);

        $changes = [];

        foreach ($fields as $field) {
            $inOld = array_key_exists($field, $oldSection);
            $inNew = array_key_exists($field, $newSection);

            if ($inNew && ! $inOld) {
                $changes[] = [
                    'section' => $section,
                    'field' => $field,
                    'type' => 'added',
                    'old' => null,
                    'new' => $newSection[$field],
                ];

                continue;
            }

            if ($inOld && ! $inNew) {
                $changes[] = [
                    'section' => $section,
                    'field' => $field,
                    'type' => 'removed',
                    'old' => $oldSection[$field],
                    'new' => null,
                ];

                continue;
            }

            $oldValue = $oldSection[$field];
            $newValue = $newSection[$field];

            if ($oldValue === $newValue) {
                continue;
            }

            if (self::isStringList($oldValue) && self::isStringList($newValue)) {
                $added = array_values(array_diff($newValue, $oldValue));
                $removed = array_values(array_diff($oldValue, $newValue));

                if ($added !== []) {
                    $changes[] = [
                        'section' => $section,
                        'field' => $field,
                        'type' => 'items_added',
                        'old' => [],
                        'new' => $added,
                    ];
                }

                if ($removed !== []) {
                    $changes[] = [
                        'section' => $section,
                        'field' => $field,
                        'type' => 'items_removed',
                        'old' => $removed,
                        'new' => [],
                    ];
                }

                continue;
            }

            $changes[] = [
                'section' => $section,
                'field' => $field,
                'type' => 'changed',
                'old' => $oldValue,
                'new' => $newValue,
            ];
        }

        return $changes;
    }

    /**
     * True when the value is a list of strings (possibly empty) — the shape
     * of every vocabulary field in the charter schema.
     *
     * @phpstan-assert-if-true =array<int, string> $value
     */
    private static function isStringList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value)
            && array_all($value, static fn (mixed $item): bool => is_string($item));
    }
}

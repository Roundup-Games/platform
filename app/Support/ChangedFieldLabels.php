<?php

namespace App\Support;

/**
 * Machine column → human label resolution for entity-change notifications
 * (M081 follow-up: the "Changes: location_id" mail).
 *
 * Change mails used to receive pre-rendered label strings from the calling
 * component, which left two holes a live UAT probe fell straight through:
 * the labels were resolved in the EDITOR's session locale (a German
 * participant of an English-language host read "Änderungen: Location"),
 * and nothing stopped a caller from handing raw column names to the mail
 * ("The game X has been updated. Changes: location_id."). This map is the
 * single translation point: callers pass machine columns, the notification
 * resolves labels per RECIPIENT locale at render time, and an unmapped
 * column can never echo through — it degrades to a generic "Details"
 * label instead of leaking a schema name at a human being.
 */
final class ChangedFieldLabels
{
    /**
     * Editable columns → label keys. The location family (linked venue,
     * freeform details, arrival instructions) keeps its distinct labels so
     * participants can tell a venue move from new doorbell instructions.
     *
     * @var array<string, string>
     */
    private const LABEL_KEYS = [
        'name' => 'common.field_name',
        'description' => 'common.field_description',
        'expected_duration' => 'common.content_duration_hours',
        'session_duration' => 'campaigns.field_duration',
        'visibility' => 'common.content_visibility',
        'date_time' => 'common.field_date_time',
        'location' => 'common.field_location',
        'location_id' => 'common.field_location',
        'location_instructions' => 'venues.field_location_instructions',
    ];

    /**
     * Label key for one column; unmapped columns fall back to the generic
     * details label — never the raw column name.
     */
    public static function labelKey(string $column): string
    {
        return self::LABEL_KEYS[$column] ?? 'common.field_details';
    }

    /**
     * Locale-aware, de-duplicated labels for a list of changed columns.
     * Dedup keeps "location"+"location_id" edits from reading "Location, Location".
     *
     * @param  list<string>  $columns
     * @return list<string>
     */
    public static function forLocale(array $columns, ?string $locale = null): array
    {
        $labels = [];

        foreach ($columns as $column) {
            $label = __(self::labelKey($column), [], $locale);

            if (! in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }

        return $labels;
    }
}

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
 *
 * Since M084 (decision D313) the map names every field FieldTiers classifies:
 * tiered editing made the game system, capacity, price, language, safety
 * tools and the rest changeable after participants confirmed, and each of
 * them must read as a word, not as "Details". The M084 labels reuse the key
 * the create form shows for the same field (canon K001: a mail never
 * disagrees with the form) — except the game type, whose picker labels each
 * type separately, and minimum_requirements, which no form field renders;
 * those two carry their own games.field_* keys.
 * tests/Unit/Support/ChangedFieldLabelsTest.php pins the coverage: a field
 * added to FieldTiers without a label fails there.
 */
final class ChangedFieldLabels
{
    /**
     * Editable columns → label keys. The location family (linked venue,
     * freeform details, arrival instructions) keeps its distinct labels so
     * participants can tell a venue move from new doorbell instructions.
     *
     * `game_system_id` and `game_systems` share one label on purpose: they
     * are the single- and the multi-system form key for the same pivot, so
     * forLocale() collapses an edit to both into a single entry.
     *
     * @var array<string, string>
     */
    private const LABEL_KEYS = [
        // Games and campaigns.
        'name' => 'common.field_name',
        'description' => 'common.field_description',
        'visibility' => 'common.content_visibility',
        'date_time' => 'common.field_date_time',
        'location_id' => 'common.field_location',
        'location_instructions' => 'venues.field_location_instructions',
        'game_system_id' => 'games.content_game_system',
        'game_systems' => 'games.content_game_system',
        'game_type' => 'games.field_game_type',
        'min_players' => 'games.field_min_players',
        'max_players' => 'games.field_max_players',
        'language' => 'common.content_language',
        'bench_mode' => 'games.label_bench_mode',
        'safety_rules' => 'safety.content_safety_tools',
        'experience_level' => 'discovery.content_experience_level',
        'complexity' => 'games.content_complexity_1_5',
        'minimum_requirements' => 'games.field_minimum_requirements',
        'host_note' => 'games.field_host_note',
        'cover_image' => 'games.field_cover_image',
        'vibe_flags' => 'common.content_vibe_flags',

        // Games only. `location` shares location_id's label on purpose:
        // forLocale() collapses a venue move into one entry.
        'location' => 'common.field_location',
        'expected_duration' => 'common.content_duration_hours',
        'price' => 'billing.content_price_eur',
        'min_reliability_preference' => 'games.field_attendance_tolerance',
        'signup_cutoff_at' => 'games.label_signup_cutoff',

        // Campaigns only.
        'session_duration' => 'campaigns.field_duration',
        'price_per_session' => 'campaigns.field_price_per_session_eur',
        'recurrence' => 'campaigns.content_recurrence',
        'time_of_day' => 'common.field_time_of_day',
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

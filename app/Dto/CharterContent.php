<?php

namespace App\Dto;

use App\Enums\SafetyTool;
use App\Models\Campaign;
use Illuminate\Support\Str;

/**
 * Campaign charter content — schema v1 value object (M070).
 *
 * The single typed home for the charter's seven stored sections (the eighth,
 * The Agreement, is signing-time copy, not stored content). Owns:
 * the blank defaults, the quick-start prefill from a Campaign, the tolerant
 * normalizer for persisted snapshots (old schemas must keep rendering per
 * docs/spec/campaign-charter-concept.md §6), the Laravel validation rules,
 * and the per-section completion checks behind the composer's stepper dots.
 *
 * Storage shape: snake_case keys, sections keyed overview / boundaries /
 * safety / policies / ai_tools / logistics / character. All vocabulary
 * constants are public so views (T04/T05) and the signing flow (S02) reuse
 * the exact same key sets instead of drifting.
 */
class CharterContent
{
    /** Content schema version persisted on session_zero_survey_versions.content_schema. */
    public const int SCHEMA_VERSION = 1;

    /** Stored sections, in stepper order (agreement section is signing-time copy). */
    public const array SECTIONS = [
        'overview', 'boundaries', 'safety', 'policies', 'ai_tools', 'logistics', 'character',
    ];

    /** §1 theme chips (fixed vocabulary). */
    public const array THEMES = [
        'politics', 'horror', 'intrigue', 'war', 'mystery', 'exploration',
        'heist', 'romance', 'survival', 'found_family', 'sandbox',
    ];

    /** §2 lines/veils category checklist (Consent-in-Gaming / TTRPG Safety Toolkit vocabulary). */
    public const array BOUNDARY_CATEGORIES = [
        'violence', 'sexual_content', 'harm_to_children', 'substance_use', 'bigotry',
        'phobias', 'self_harm', 'animal_harm', 'real_world_trauma',
    ];

    /** §4 etiquette chips (fixed vocabulary). */
    public const array ETIQUETTE = [
        'phones_away', 'gm_rules_final', 'no_split_party', 'stay_in_character',
        'prep_expected', 'respect_time',
    ];

    /**
     * §8 The Agreement — the five signing checkboxes: the read attestation
     * plus the four [AGREE] items from §3 safety, §4 policies, §5 AI & digital
     * tools, §6 logistics. Mirrors the lang keys charter.confirm_read_attestation
     * and charter.confirm_{safety,policies,ai_tools,logistics} (S01 copy, reused
     * verbatim by the S02 signing checkboxes) — keep both sides in sync.
     */
    public const array AGREEMENT_ITEMS = [
        'read_attestation', 'safety', 'policies', 'ai_tools', 'logistics',
    ];

    /** §6 communication channels — the single deliberate digital exception (R086). */
    public const array COMMS = ['platform', 'discord', 'whatsapp', 'telegram', 'other'];

    /**
     * Amendment materiality (M070/S03): sections whose change is always
     * material — §2 table standard, §3 safety, §4 policies, §5 AI policy.
     * A material amendment flips every signed confirmation (participants and
     * the GM countersignature) back to pending re-agreement.
     */
    public const array MATERIAL_SECTIONS = ['boundaries', 'safety', 'policies', 'ai_tools'];

    /**
     * Amendment materiality (M070/S03): §1 overview fields whose change is
     * material — the theme set and the three tone axes. Pitch, system notes,
     * expected shape, and table size are minor (logistics-level) changes.
     */
    public const array MATERIAL_OVERVIEW_FIELDS = [
        'themes', 'tone_serious_lighthearted', 'tone_gritty_heroic', 'tone_political_dungeon',
    ];

    /** @var array<string, mixed> normalized schema-v1 content */
    public readonly array $content;

    /**
     * @param  array<string, mixed>  $content  normalized content (build via blank/quickStart/fromArray)
     */
    private function __construct(array $content)
    {
        $this->content = $content;
    }

    /**
     * Empty charter: schema defaults only, nothing prefilled. Choice fields
     * that have a safe default (tone axes 3, open_door on, revisit each
     * session, venue_mode location) carry it; every authoring choice the GM
     * must make starts empty.
     */
    public static function blank(): self
    {
        return new self(self::defaults());
    }

    /**
     * Quick-start charter: blank defaults + prefill from the campaign
     * (pitch, system notes, table size, venue, cadence, session length,
     * recommended safety tools) + sensible mid defaults for the
     * policies / ai_tools / logistics choice fields so a GM can reach
     * publish with minimal editing.
     */
    public static function quickStart(Campaign $campaign): self
    {
        $content = self::defaults();

        // §1 overview — derived from campaign facts.
        $content['overview']['pitch'] = self::clip(
            trim(strip_tags((string) $campaign->description)),
            2000,
        );
        $systems = $campaign->loadMissing('gameSystems')->gameSystems;
        if ($systems->isNotEmpty()) {
            $content['overview']['system_notes'] = $systems->pluck('name')->join(', ');
        }
        $content['overview']['table_size'] = self::clampTableSize($campaign->max_players);
        // A set recurrence implies an ongoing campaign; anything else
        // (irregular / unset) still runs, just without a fixed rhythm —
        // open-ended is the honest default for it.
        $content['overview']['expected_shape'] = self::shapeFromRecurrence($campaign->recurrence);

        // §3 safety — the recommended starter set.
        $content['safety']['tools'] = array_map(
            static fn (SafetyTool $tool): string => $tool->value,
            SafetyTool::recommended(),
        );

        // §4 policies — mid defaults.
        $content['policies']['pvp'] = 'limited';
        $content['policies']['character_death'] = 'possible';
        $content['policies']['advancement'] = 'milestone';
        $content['policies']['homebrew'] = 'gm_approval';

        // §5 AI & digital tools — consent-first defaults.
        $content['ai_tools']['ai_prep'] = 'case_by_case';
        $content['ai_tools']['ai_transcription'] = 'not_at_table';
        $content['ai_tools']['dice'] = 'physical';
        $content['ai_tools']['sheets'] = 'either';
        $content['ai_tools']['photography'] = 'ask_every_time';
        $content['ai_tools']['recording'] = 'none';

        // §6 logistics — venue/cadence/length from the campaign, the rest mid defaults.
        if ($campaign->location_id !== null) {
            $content['logistics']['venue_mode'] = 'location';
            $content['logistics']['location_id'] = (string) $campaign->location_id;
        } else {
            $content['logistics']['venue_mode'] = 'rotate';
        }
        $content['logistics']['food_drink'] = 'venue_rules';
        $content['logistics']['cadence'] = self::cadenceFromRecurrence($campaign->recurrence);
        $content['logistics']['session_length'] = self::lengthFromDuration($campaign->session_duration);
        $content['logistics']['absence_notice'] = '24h';
        $content['logistics']['missing_player'] = 'skip_session';
        $content['logistics']['comms'] = ['platform'];

        return new self($content);
    }

    /**
     * Tolerant normalizer for persisted content snapshots. Unknown sections
     * and unknown field keys are dropped, missing keys fall back to the
     * schema defaults, and types are coerced — old snapshots with different
     * shapes must keep normalizing and rendering (concept §6).
     *
     * @param  array<string, mixed>  $content  raw persisted/user content
     */
    public static function fromArray(array $content): self
    {
        $normalized = self::defaults();

        $overview = self::section($content, 'overview');
        $normalized['overview'] = [
            'pitch' => self::text($overview['pitch'] ?? null),
            'system_notes' => self::text($overview['system_notes'] ?? null),
            'tone_serious_lighthearted' => self::tone($overview['tone_serious_lighthearted'] ?? null),
            'tone_gritty_heroic' => self::tone($overview['tone_gritty_heroic'] ?? null),
            'tone_political_dungeon' => self::tone($overview['tone_political_dungeon'] ?? null),
            'themes' => self::vocabList($overview['themes'] ?? null, self::THEMES),
            'expected_shape' => self::choice($overview['expected_shape'] ?? null, ['short_arc', 'ongoing', 'open_ended']),
            'table_size' => self::tableSize($overview['table_size'] ?? null),
            'may_add_players' => self::boolean($overview['may_add_players'] ?? null),
        ];

        $boundaries = self::section($content, 'boundaries');
        $normalized['boundaries'] = [
            'lines_categories' => self::vocabList($boundaries['lines_categories'] ?? null, self::BOUNDARY_CATEGORIES),
            'veils_categories' => self::vocabList($boundaries['veils_categories'] ?? null, self::BOUNDARY_CATEGORIES),
            'lines_text' => self::text($boundaries['lines_text'] ?? null),
            'veils_text' => self::text($boundaries['veils_text'] ?? null),
            'intro_note' => self::text($boundaries['intro_note'] ?? null),
        ];

        $safety = self::section($content, 'safety');
        $normalized['safety'] = [
            'tools' => self::vocabList($safety['tools'] ?? null, SafetyTool::values()),
            'custom_note' => self::text($safety['custom_note'] ?? null),
            'signal_how' => self::text($safety['signal_how'] ?? null),
            'revisit' => self::choice($safety['revisit'] ?? null, ['each_session', 'end_of_arc', 'on_request'], 'each_session'),
            'open_door' => self::boolean($safety['open_door'] ?? null, true),
        ];

        $policies = self::section($content, 'policies');
        $normalized['policies'] = [
            'pvp' => self::choice($policies['pvp'] ?? null, ['none', 'limited', 'open']),
            'character_death' => self::choice($policies['character_death'] ?? null, ['possible', 'gm_may_fudge', 'player_consent']),
            'advancement' => self::choice($policies['advancement'] ?? null, ['milestone', 'xp', 'narrative']),
            'loot_policy' => self::nullableText($policies['loot_policy'] ?? null),
            'homebrew' => self::choice($policies['homebrew'] ?? null, ['no', 'gm_approval', 'free']),
            'etiquette' => self::vocabList($policies['etiquette'] ?? null, self::ETIQUETTE),
            'etiquette_text' => self::text($policies['etiquette_text'] ?? null),
        ];

        $aiTools = self::section($content, 'ai_tools');
        $normalized['ai_tools'] = [
            'ai_prep' => self::choice($aiTools['ai_prep'] ?? null, ['welcome_disclosed', 'case_by_case', 'not_at_table']),
            'ai_transcription' => self::choice($aiTools['ai_transcription'] ?? null, ['not_at_table', 'gm_private', 'shared_table']),
            'dice' => self::choice($aiTools['dice'] ?? null, ['physical', 'apps_fine', 'whatever_fun']),
            'sheets' => self::choice($aiTools['sheets'] ?? null, ['paper_preferred', 'digital_fine', 'either']),
            'photography' => self::choice($aiTools['photography'] ?? null, ['ask_every_time', 'preapproved_table_only', 'no_photos']),
            'recording' => self::choice($aiTools['recording'] ?? null, ['none', 'gm_private', 'shared_table']),
            'custom_clause' => self::nullableText($aiTools['custom_clause'] ?? null),
        ];

        $logistics = self::section($content, 'logistics');
        $normalized['logistics'] = [
            'venue_mode' => self::choice($logistics['venue_mode'] ?? null, ['location', 'rotate'], 'location'),
            'location_id' => uuid_or_null($logistics['location_id'] ?? null),
            'venue_access_notes' => self::text($logistics['venue_access_notes'] ?? null),
            'food_drink' => self::choice($logistics['food_drink'] ?? null, ['venue_rules', 'snack_rotation', 'byo', 'group_orders']),
            'travel_notes' => self::text($logistics['travel_notes'] ?? null),
            'cadence' => self::choice($logistics['cadence'] ?? null, ['weekly', 'biweekly', 'monthly', 'irregular']),
            'session_length' => self::choice($logistics['session_length'] ?? null, ['2h', '3h', '4h', 'other']),
            'session_length_other' => self::text($logistics['session_length_other'] ?? null),
            'attendance_note' => self::text($logistics['attendance_note'] ?? null),
            'max_missed_sessions' => self::nullableInt($logistics['max_missed_sessions'] ?? null),
            'absence_notice' => self::choice($logistics['absence_notice'] ?? null, ['asap', '24h', '48h']),
            'missing_player' => self::choice($logistics['missing_player'] ?? null, ['skip_session', 'pc_as_npc', 'side_oneshot']),
            'comms' => self::vocabList($logistics['comms'] ?? null, self::COMMS),
            'comms_other' => self::text($logistics['comms_other'] ?? null),
        ];

        $character = self::section($content, 'character');
        $normalized['character'] = [
            'build_constraints' => self::text($character['build_constraints'] ?? null),
            'backstory_requested' => self::boolean($character['backstory_requested'] ?? null),
            'backstory_guidance' => self::text($character['backstory_guidance'] ?? null),
        ];

        return new self($normalized);
    }

    /**
     * @return array<string, mixed> normalized schema-v1 content
     */
    public function toArray(): array
    {
        return $this->content;
    }

    /**
     * Full publish-time validation rules, keyed by dotted paths so
     * Validator::make($content, CharterContent::rules()) validates the
     * complete nested content array.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'overview.pitch' => ['required', 'string', 'max:2000'],
            'overview.system_notes' => ['nullable', 'string', 'max:5000'],
            'overview.tone_serious_lighthearted' => ['required', 'integer', 'min:1', 'max:5'],
            'overview.tone_gritty_heroic' => ['required', 'integer', 'min:1', 'max:5'],
            'overview.tone_political_dungeon' => ['required', 'integer', 'min:1', 'max:5'],
            'overview.themes' => ['present', 'array'],
            'overview.themes.*' => ['distinct', 'in:'.implode(',', self::THEMES)],
            'overview.expected_shape' => ['required', 'in:short_arc,ongoing,open_ended'],
            'overview.table_size' => ['required', 'integer', 'min:2', 'max:12'],
            'overview.may_add_players' => ['boolean'],

            'boundaries.lines_categories' => ['present', 'array'],
            'boundaries.lines_categories.*' => ['distinct', 'in:'.implode(',', self::BOUNDARY_CATEGORIES)],
            'boundaries.veils_categories' => ['present', 'array'],
            'boundaries.veils_categories.*' => ['distinct', 'in:'.implode(',', self::BOUNDARY_CATEGORIES)],
            'boundaries.lines_text' => ['nullable', 'string', 'max:2000'],
            'boundaries.veils_text' => ['nullable', 'string', 'max:2000'],
            'boundaries.intro_note' => ['nullable', 'string', 'max:1000'],

            'safety.tools' => ['required', 'array', 'min:1'],
            'safety.tools.*' => ['distinct', 'in:'.implode(',', SafetyTool::values())],
            'safety.custom_note' => ['nullable', 'string', 'max:2000'],
            'safety.signal_how' => ['nullable', 'string', 'max:2000'],
            'safety.revisit' => ['required', 'in:each_session,end_of_arc,on_request'],
            'safety.open_door' => ['boolean'],

            'policies.pvp' => ['required', 'in:none,limited,open'],
            'policies.character_death' => ['required', 'in:possible,gm_may_fudge,player_consent'],
            'policies.advancement' => ['required', 'in:milestone,xp,narrative'],
            'policies.loot_policy' => ['nullable', 'string', 'max:2000'],
            'policies.homebrew' => ['required', 'in:no,gm_approval,free'],
            'policies.etiquette' => ['present', 'array'],
            'policies.etiquette.*' => ['distinct', 'in:'.implode(',', self::ETIQUETTE)],
            'policies.etiquette_text' => ['nullable', 'string', 'max:2000'],

            'ai_tools.ai_prep' => ['required', 'in:welcome_disclosed,case_by_case,not_at_table'],
            'ai_tools.ai_transcription' => ['required', 'in:not_at_table,gm_private,shared_table'],
            'ai_tools.dice' => ['required', 'in:physical,apps_fine,whatever_fun'],
            'ai_tools.sheets' => ['required', 'in:paper_preferred,digital_fine,either'],
            'ai_tools.photography' => ['required', 'in:ask_every_time,preapproved_table_only,no_photos'],
            'ai_tools.recording' => ['required', 'in:none,gm_private,shared_table'],
            'ai_tools.custom_clause' => ['nullable', 'string', 'max:2000'],

            'logistics.venue_mode' => ['required', 'in:location,rotate'],
            'logistics.location_id' => ['nullable', 'uuid', 'required_if:logistics.venue_mode,location'],
            'logistics.venue_access_notes' => ['nullable', 'string', 'max:1000'],
            'logistics.food_drink' => ['required', 'in:venue_rules,snack_rotation,byo,group_orders'],
            'logistics.travel_notes' => ['nullable', 'string', 'max:1000'],
            'logistics.cadence' => ['required', 'in:weekly,biweekly,monthly,irregular'],
            'logistics.session_length' => ['required', 'in:2h,3h,4h,other'],
            'logistics.session_length_other' => ['nullable', 'string', 'max:500', 'required_if:logistics.session_length,other'],
            'logistics.attendance_note' => ['nullable', 'string', 'max:1000'],
            'logistics.max_missed_sessions' => ['nullable', 'integer', 'min:1', 'max:52'],
            'logistics.absence_notice' => ['required', 'in:asap,24h,48h'],
            'logistics.missing_player' => ['required', 'in:skip_session,pc_as_npc,side_oneshot'],
            'logistics.comms' => ['required', 'array', 'min:1'],
            'logistics.comms.*' => ['distinct', 'in:'.implode(',', self::COMMS)],
            'logistics.comms_other' => ['nullable', 'string', 'max:500'],

            'character.build_constraints' => ['nullable', 'string', 'max:2000'],
            'character.backstory_requested' => ['boolean'],
            'character.backstory_guidance' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Material-change detection for amendments (M070/S03): true when any
     * material section (§2–§5) differs or any §1 tone axis / theme set
     * differs. Everything else — pitch, system notes, expected shape, table
     * size, §6 logistics, §7 character — is a minor amendment that carries
     * existing signatures. Title lives outside content and is always minor.
     *
     * Both sides are normalized first so raw row shapes and stored snapshots
     * compare on equal footing. List-valued fields compare as sorted sets:
     * an order-only shuffle is never material.
     *
     * @param  array<string, mixed>  $old  previous published content (raw or normalized)
     * @param  array<string, mixed>  $new  amended content (raw or normalized)
     */
    public static function isMaterialChange(array $old, array $new): bool
    {
        $old = self::fromArray($old)->toArray();
        $new = self::fromArray($new)->toArray();

        foreach (self::MATERIAL_SECTIONS as $section) {
            $oldSection = self::section($old, $section);
            $newSection = self::section($new, $section);
            foreach (array_keys($oldSection) as $field) {
                if (! self::valuesEqual($oldSection[$field], $newSection[$field])) {
                    return true;
                }
            }
        }

        $oldOverview = self::section($old, 'overview');
        $newOverview = self::section($new, 'overview');
        foreach (self::MATERIAL_OVERVIEW_FIELDS as $field) {
            if (! self::valuesEqual($oldOverview[$field], $newOverview[$field])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The choice vocabulary of one enumerated field, derived from its
     * rules() `in:` list — the single source the composer selects and
     * the validation share, so a new choice can never exist in the UI
     * and not the rules (silent publish-block) or vice versa (a
     * selection the normalizer quietly resets).
     *
     * @param  string  $field  Full dotted path, e.g. 'logistics.cadence'.
     * @return list<string>
     */
    public static function choicesFor(string $field): array
    {
        foreach (self::rules()[$field] ?? [] as $rule) {
            if (str_starts_with($rule, 'in:')) {
                return explode(',', substr($rule, strlen('in:')));
            }
        }

        throw new \InvalidArgumentException("Charter field [{$field}] has no enumerated choices.");
    }

    /**
     * Choice maps for a section's select fields, keyed by bare field
     * name — the shape the composer partials iterate.
     *
     * @param  list<string>  $fields
     * @return array<string, list<string>>
     */
    public static function choicesForSection(string $section, array $fields): array
    {
        $map = [];

        foreach ($fields as $field) {
            $map[$field] = self::choicesFor($section.'.'.$field);
        }

        return $map;
    }

    /**
     * Stepper-step validation subset: only the rules of one section.
     *
     * @return array<string, array<int, string>>
     */
    public static function rulesForSection(string $section): array
    {
        if (! in_array($section, self::SECTIONS, true)) {
            throw new \InvalidArgumentException("Unknown charter section [{$section}].");
        }

        return array_filter(
            self::rules(),
            static fn (string $key): bool => str_starts_with($key, $section.'.'),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Completion check behind the stepper dots. Operates on raw content
     * (normalized internally), so it tolerates partial drafts.
     *
     * @param  array<string, mixed>  $content
     */
    public static function sectionComplete(string $section, array $content): bool
    {
        if (! in_array($section, self::SECTIONS, true)) {
            throw new \InvalidArgumentException("Unknown charter section [{$section}].");
        }

        $c = self::fromArray($content)->toArray();
        $overview = self::section($c, 'overview');
        $boundaries = self::section($c, 'boundaries');
        $safety = self::section($c, 'safety');
        $policies = self::section($c, 'policies');
        $aiTools = self::section($c, 'ai_tools');
        $logistics = self::section($c, 'logistics');

        return match ($section) {
            'overview' => $overview['pitch'] !== ''
                && $overview['expected_shape'] !== ''
                && $overview['table_size'] >= 2
                && $overview['table_size'] <= 12,
            'boundaries' => $boundaries['lines_categories'] !== []
                || $boundaries['veils_categories'] !== []
                || $boundaries['lines_text'] !== ''
                || $boundaries['veils_text'] !== '',
            'safety' => $safety['tools'] !== [],
            'policies' => $policies['pvp'] !== ''
                && $policies['character_death'] !== ''
                && $policies['advancement'] !== ''
                && $policies['homebrew'] !== '',
            'ai_tools' => $aiTools['ai_prep'] !== ''
                && $aiTools['ai_transcription'] !== ''
                && $aiTools['dice'] !== ''
                && $aiTools['sheets'] !== ''
                && $aiTools['photography'] !== ''
                && $aiTools['recording'] !== '',
            // Mirrors rules() exactly: every required logistics field
            // (incl. the required_if location_id and the three required
            // selects) — a green dot must mean publish() will accept the
            // section, or the stepper lies and publish() bounces the GM
            // back to step 6.
            'logistics' => $logistics['venue_mode'] !== ''
                && ($logistics['venue_mode'] !== 'location' || $logistics['location_id'] !== null)
                && $logistics['food_drink'] !== ''
                && $logistics['cadence'] !== ''
                && $logistics['session_length'] !== ''
                && ($logistics['session_length'] !== 'other' || $logistics['session_length_other'] !== '')
                && $logistics['absence_notice'] !== ''
                && $logistics['missing_player'] !== ''
                && $logistics['comms'] !== [],
            'character' => true, // informational — always completable
        };
    }

    /**
     * Schema-v1 blank content. Single source for blank()/fromArray defaults.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function defaults(): array
    {
        return [
            'overview' => [
                'pitch' => '',
                'system_notes' => '',
                'tone_serious_lighthearted' => 3,
                'tone_gritty_heroic' => 3,
                'tone_political_dungeon' => 3,
                'themes' => [],
                'expected_shape' => '',
                'table_size' => 4,
                'may_add_players' => false,
            ],
            'boundaries' => [
                'lines_categories' => [],
                'veils_categories' => [],
                'lines_text' => '',
                'veils_text' => '',
                'intro_note' => '',
            ],
            'safety' => [
                'tools' => [],
                'custom_note' => '',
                'signal_how' => '',
                'revisit' => 'each_session',
                'open_door' => true,
            ],
            'policies' => [
                'pvp' => '',
                'character_death' => '',
                'advancement' => '',
                'loot_policy' => null,
                'homebrew' => '',
                'etiquette' => [],
                'etiquette_text' => '',
            ],
            'ai_tools' => [
                'ai_prep' => '',
                'ai_transcription' => '',
                'dice' => '',
                'sheets' => '',
                'photography' => '',
                'recording' => '',
                'custom_clause' => null,
            ],
            'logistics' => [
                'venue_mode' => 'location',
                'location_id' => null,
                'venue_access_notes' => '',
                'food_drink' => '',
                'travel_notes' => '',
                'cadence' => '',
                'session_length' => '',
                'session_length_other' => '',
                'attendance_note' => '',
                'max_missed_sessions' => null,
                'absence_notice' => '',
                'missing_player' => '',
                'comms' => [],
                'comms_other' => '',
            ],
            'character' => [
                'build_constraints' => '',
                'backstory_requested' => false,
                'backstory_guidance' => '',
            ],
        ];
    }

    // ── Normalization helpers (tolerant coercion) ──────────

    /**
     * Materiality equality: string lists compare as sorted sets (order-only
     * shuffles are never material); everything else compares strictly.
     */
    private static function valuesEqual(mixed $old, mixed $new): bool
    {
        if (is_array($old) && is_array($new)) {
            $oldSet = array_values($old);
            $newSet = array_values($new);
            sort($oldSet);
            sort($newSet);

            return $oldSet === $newSet;
        }

        return $old === $new;
    }

    /**
     * Fetch a raw section array from mixed content, defaulting to [].
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private static function section(array $content, string $key): array
    {
        $value = $content[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /** Trimmed string; null/non-scalar becomes ''. */
    private static function text(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    /** Trimmed non-empty string or null (optional free-text fields). */
    private static function nullableText(mixed $value): ?string
    {
        $text = self::text($value);

        return $text === '' ? null : $text;
    }

    /** Tone axis: numeric coerced to int and clamped to 1–5; anything else → 3. */
    private static function tone(mixed $value): int
    {
        if (! is_numeric($value)) {
            return 3;
        }

        return max(1, min(5, (int) $value));
    }

    /** Table size: numeric coerced to int and clamped to 2–12; anything else → 4. */
    private static function tableSize(mixed $value): int
    {
        if (! is_numeric($value)) {
            return 4;
        }

        return max(2, min(12, (int) $value));
    }

    /** Table size from the campaign (nullable column), clamped to 2–12. */
    private static function clampTableSize(mixed $maxPlayers): int
    {
        if (! is_numeric($maxPlayers)) {
            return 4;
        }

        return max(2, min(12, (int) $maxPlayers));
    }

    /** Boolean coercion: true/1/'1'/'true' → true; false/0/'0'/'false' → false; else $default. */
    private static function boolean(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                'true', '1', 'yes', 'on' => true,
                'false', '0', 'no', 'off', '' => false,
                default => $default,
            };
        }

        return $default;
    }

    /** Single-choice field: keep the value when valid, else $default (default '').
     *
     * @param  array<int, string>  $allowed
     */
    private static function choice(mixed $value, array $allowed, string $default = ''): string
    {
        return is_scalar($value) && in_array((string) $value, $allowed, true) ? (string) $value : $default;
    }

    /**
     * Vocabulary list: keep only valid members, drop duplicates, reindex.
     *
     * @param  array<int, string>  $allowed
     * @return array<int, string>
     */
    private static function vocabList(mixed $value, array $allowed): array
    {
        if (! is_array($value)) {
            return [];
        }

        $kept = [];
        foreach ($value as $item) {
            $string = is_scalar($item) ? (string) $item : null;
            if ($string !== null && in_array($string, $allowed, true) && ! in_array($string, $kept, true)) {
                $kept[] = $string;
            }
        }

        return $kept;
    }

    /** Nullable positive integer (seat-rule threshold); junk becomes null. */
    private static function nullableInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    // ── Quick-start mapping helpers ────────────────────────

    /** Map campaigns.recurrence onto the charter cadence vocabulary. */
    private static function cadenceFromRecurrence(?string $recurrence): string
    {
        return match ($recurrence) {
            'weekly' => 'weekly',
            'bi-weekly' => 'biweekly',
            'monthly' => 'monthly',
            default => 'irregular',
        };
    }

    /** Map a campaign's recurrence onto the expected-shape vocabulary. */
    private static function shapeFromRecurrence(?string $recurrence): string
    {
        return in_array($recurrence, ['weekly', 'bi-weekly', 'monthly'], true) ? 'ongoing' : 'open_ended';
    }

    /** Map campaigns.session_duration (float hours) onto the session-length vocabulary. */
    private static function lengthFromDuration(mixed $duration): string
    {
        if (! is_numeric($duration)) {
            return 'other';
        }

        return match ((float) $duration) {
            2.0 => '2h',
            3.0 => '3h',
            4.0 => '4h',
            default => 'other',
        };
    }

    /** Multibyte-safe clip to a character limit. */
    private static function clip(string $value, int $limit): string
    {
        return Str::limit($value, $limit, '');
    }
}

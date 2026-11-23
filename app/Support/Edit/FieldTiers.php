<?php

namespace App\Support\Edit;

use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\Game;
use InvalidArgumentException;

/**
 * The single source of truth for consent tiers on game and campaign edit
 * fields (M084, decision D313).
 *
 * A field's tier answers one question: what did a participant agree to when
 * they confirmed?
 *
 *  - Tier 1 (TIER_LOCKED) — locked at first confirmation. Not owner-editable
 *    once the agreement exists, i.e. once the entity has ever had a confirmed
 *    non-owner participant (HasAgreementLock::isAgreementLocked()).
 *  - Tier 2 (TIER_NOTIFIED) — editable; participants are notified prominently.
 *  - Tier 3 (TIER_FREE) — freely editable.
 *
 * Every surface that edits a game or a campaign — AgreementEditService, the
 * detail-page edit mode, the Filament admin — reads tier membership from this
 * class and nowhere else: nothing else may hard-code it. This class only
 * CLASSIFIES. It does not know whether an entity is locked, and it does not
 * decide what a surface does with a tier.
 *
 * ## What the tier maps cover
 *
 * GAME_TIERS and CAMPAIGN_TIERS key every field the create forms collect
 * (App\Livewire\Games\CreateGame, App\Livewire\Campaigns\CreateCampaign) by
 * machine name, verified against their validation rule keys and the schema.
 * Three of those names are not a literal column on the entity:
 *
 *  - `game_system_id` and `game_systems` are the single- and the multi-system
 *    form key. Both resolve to the `gameSystems()` pivot
 *    (`game_game_system` / `campaign_game_system`). Tier 1 either way: the
 *    game system is the thing itself (D313).
 *  - `cover_image` is the uploaded cover, stored through the media library.
 *
 * `location` (games only) IS a column, but the game form writes it without a
 * rule key of its own.
 *
 * `vibe_flags` is a column and a create-form field of BOTH forms (the
 * vibe-preference picker; a list<string>, an empty list stored as null). It
 * is Tier 3, beside complexity and experience_level: a descriptor, not part
 * of the agreement.
 *
 * `comfort_notes` is NOT a column. Board games store it inside the
 * `safety_rules` JSON (CreateGame::save), so it is Tier 2 through
 * `safety_rules` and is deliberately not a top-level key here.
 *
 * Together with the two direction-dependent fields (DIRECTION_DEPENDENT), the
 * tier maps are the complete set of fields an edit surface may write. Every
 * other column on `games` / `campaigns` — status, owner_id, the event and
 * campaign links, share tokens, reminder and attendance bookkeeping, SEO and
 * recruitment-summary fields, timestamps — is system-managed, is not listed,
 * and makes tierOf() throw.
 *
 * Campaigns price per session (`price_per_session`, not `price`) and store
 * their length as `session_duration` (not `expected_duration`); they have no
 * JSON `location`, no `signup_cutoff_at` and no `min_reliability_preference`.
 * Asking for any of those on a campaign throws like every other unclassified
 * field.
 *
 * The class is pure PHP: no Eloquent, no database, no facades, no container.
 * The two model classes are referenced as `::class` name constants only and
 * are never loaded.
 */
final class FieldTiers
{
    /** Locked at first confirmation. */
    public const int TIER_LOCKED = 1;

    /** Editable; participants are notified. */
    public const int TIER_NOTIFIED = 2;

    /** Freely editable. */
    public const int TIER_FREE = 3;

    /**
     * Game fields → tier, ordered Tier 1 → 3.
     *
     * @var array<string, int>
     */
    public const array GAME_TIERS = [
        // Tier 1 — locked at first confirmation.
        'game_systems' => self::TIER_LOCKED,
        'game_system_id' => self::TIER_LOCKED,
        'game_type' => self::TIER_LOCKED,
        'date_time' => self::TIER_LOCKED,
        'expected_duration' => self::TIER_LOCKED,
        'price' => self::TIER_LOCKED,
        'min_players' => self::TIER_LOCKED,
        'bench_mode' => self::TIER_LOCKED,
        'language' => self::TIER_LOCKED,

        // Tier 2 — editable, participants notified.
        'location_id' => self::TIER_NOTIFIED,
        'location' => self::TIER_NOTIFIED,
        'location_instructions' => self::TIER_NOTIFIED,
        'safety_rules' => self::TIER_NOTIFIED,

        // Tier 3 — freely editable.
        'name' => self::TIER_FREE,
        'description' => self::TIER_FREE,
        'cover_image' => self::TIER_FREE,
        'experience_level' => self::TIER_FREE,
        'complexity' => self::TIER_FREE,
        'minimum_requirements' => self::TIER_FREE,
        'min_reliability_preference' => self::TIER_FREE,
        'signup_cutoff_at' => self::TIER_FREE,
        'host_note' => self::TIER_FREE,
        'vibe_flags' => self::TIER_FREE,
    ];

    /**
     * Campaign fields → tier, ordered Tier 1 → 3.
     *
     * @var array<string, int>
     */
    public const array CAMPAIGN_TIERS = [
        // Tier 1 — locked at first confirmation.
        'game_systems' => self::TIER_LOCKED,
        'game_system_id' => self::TIER_LOCKED,
        'game_type' => self::TIER_LOCKED,
        'recurrence' => self::TIER_LOCKED,
        'time_of_day' => self::TIER_LOCKED,
        'session_duration' => self::TIER_LOCKED,
        'price_per_session' => self::TIER_LOCKED,
        'min_players' => self::TIER_LOCKED,
        'bench_mode' => self::TIER_LOCKED,
        'language' => self::TIER_LOCKED,

        // Tier 2 — editable, participants notified.
        'location_id' => self::TIER_NOTIFIED,
        'location_instructions' => self::TIER_NOTIFIED,
        'safety_rules' => self::TIER_NOTIFIED,

        // Tier 3 — freely editable.
        'name' => self::TIER_FREE,
        'description' => self::TIER_FREE,
        'cover_image' => self::TIER_FREE,
        'experience_level' => self::TIER_FREE,
        'complexity' => self::TIER_FREE,
        'minimum_requirements' => self::TIER_FREE,
        'host_note' => self::TIER_FREE,
        'vibe_flags' => self::TIER_FREE,
    ];

    /**
     * Direction-dependent fields, deliberately absent from both tier maps.
     * Their tier is a function of the current AND the new value, so it can
     * only be resolved through the method named here; tierOf() throws for
     * them. Same set for both models.
     *
     * @var array<string, string> field → name of the method that resolves its tier
     */
    public const array DIRECTION_DEPENDENT = [
        'max_players' => 'maxPlayersTier',
        'visibility' => 'visibilityTier',
    ];

    /**
     * Tier of one top-level field of a game or a campaign.
     *
     * The fail-closed lookup every edit surface uses for a fixed-tier field:
     * a field nobody classified — a column added to a create form after this
     * list was written — throws instead of falling through as "free".
     *
     * @param  class-string  $modelClass  Game::class or Campaign::class
     * @return int One of TIER_LOCKED, TIER_NOTIFIED, TIER_FREE
     *
     * @throws InvalidArgumentException When $modelClass is neither Game nor Campaign; when the field is direction-dependent (use maxPlayersTier() or visibilityTier()); or when the field is not classified for that model.
     */
    public static function tierOf(string $modelClass, string $field): int
    {
        $tiers = match ($modelClass) {
            Game::class => self::GAME_TIERS,
            Campaign::class => self::CAMPAIGN_TIERS,
            default => throw new InvalidArgumentException(sprintf(
                'Unknown model class [%s]: %s classifies the fields of [%s] and [%s] only.',
                $modelClass,
                self::class,
                Game::class,
                Campaign::class,
            )),
        };

        if (array_key_exists($field, self::DIRECTION_DEPENDENT)) {
            $resolver = self::DIRECTION_DEPENDENT[$field];

            throw new InvalidArgumentException(sprintf(
                'Field [%s] is direction-dependent: its tier depends on the current and the new value. Use %s::%s() instead of tierOf().',
                $field,
                self::class,
                $resolver,
            ));
        }

        return $tiers[$field] ?? throw new InvalidArgumentException(sprintf(
            'Field [%s] is not classified for [%s]. Every field an edit surface may write MUST be assigned a tier in %s (decision D313).%s',
            $field,
            $modelClass,
            self::class,
            match ($field) {
                'comfort_notes' => ' It is not a column: board games store it inside the safety_rules JSON, so ask for safety_rules.',
                default => '',
            },
        ));
    }

    /**
     * Tier of a max_players change (decision D313). A decrease is Tier 1, an
     * increase is Tier 2, and an unchanged value is Tier 3 — a no-op.
     *
     * @return int One of TIER_LOCKED, TIER_NOTIFIED, TIER_FREE
     */
    public static function maxPlayersTier(int $current, int $new): int
    {
        return match (true) {
            $new < $current => self::TIER_LOCKED,
            $new > $current => self::TIER_NOTIFIED,
            default => self::TIER_FREE,
        };
    }

    /**
     * Tier of a visibility change (decision D313). Only public → private is
     * Tier 1. Every other transition — private → public, and anything
     * involving protected — is Tier 3, as is an unchanged value (a no-op).
     *
     * @param  string  $current  Visibility backing value
     * @param  string  $new  Visibility backing value
     * @return int One of TIER_LOCKED, TIER_NOTIFIED, TIER_FREE
     *
     * @throws InvalidArgumentException When either value is not a Visibility backing value — an unknown visibility is never classified as free.
     */
    public static function visibilityTier(string $current, string $new): int
    {
        self::assertVisibilityValue($current);
        self::assertVisibilityValue($new);

        if ($current === Visibility::Public->value && $new === Visibility::Private->value) {
            return self::TIER_LOCKED;
        }

        return self::TIER_FREE;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertVisibilityValue(string $value): void
    {
        if (! in_array($value, Visibility::values(), true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown visibility value [%s]: %s::visibilityTier() expects one of [%s].',
                $value,
                self::class,
                implode(', ', Visibility::values()),
            ));
        }
    }
}

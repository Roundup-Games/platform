<?php

namespace App\Enums;

/**
 * Rarity tier derived from the share of eligible users holding a badge
 * (spec §2.4, D195).
 *
 * The tier is a display grouping computed nightly by badges:rarity from
 * holders_count / eligible_count — it is never hand-assigned and never a
 * claim about difficulty beyond "how rare is this among the people who
 * could have it".
 *
 * ELIGIBLE_THRESHOLD is the boundary map's percentage-display gate (D195):
 * a badge may show "held by X% of eligible players" only while its
 * eligible population is at least 100 — no rare-% claims on a tiny
 * population. Below the gate the UI shows the raw holder count only.
 */
enum BadgeRarity: string
{
    case Common = 'common';
    case Uncommon = 'uncommon';
    case Rare = 'rare';
    case Elite = 'elite';
    case Legendary = 'legendary';

    /** @var int Minimum eligible population before a holder percentage may be shown publicly. */
    public const ELIGIBLE_THRESHOLD = 100;

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Common => __('badges.content_badge_rarity_common'),
            self::Uncommon => __('badges.content_badge_rarity_uncommon'),
            self::Rare => __('badges.content_badge_rarity_rare'),
            self::Elite => __('badges.content_badge_rarity_elite'),
            self::Legendary => __('badges.content_badge_rarity_legendary'),
        };
    }

    /**
     * Map a holder percentage onto a tier. Boundaries are inclusive on
     * the tier's lower edge: >=60 common, >=20 uncommon, >=5 rare, >=1
     * elite, below 1 (including an empty-platform 0) legendary.
     */
    public static function fromPercentage(float $percentage): self
    {
        return match (true) {
            $percentage >= 60 => self::Common,
            $percentage >= 20 => self::Uncommon,
            $percentage >= 5 => self::Rare,
            $percentage >= 1 => self::Elite,
            default => self::Legendary,
        };
    }
}

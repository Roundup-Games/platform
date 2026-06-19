<?php

namespace App\Enums;

/**
 * Address granularity rungs for graduated location disclosure (D079, D082).
 *
 * Ordered from most restrictive to most permissive: None < Area < City < Exact.
 *
 * - None:  no address shown at all (blocked viewer, unresolvable location).
 * - Area:  "In your area" — the geohash-tile-derived label (D060 semantics).
 * - City:  the locality name only (no street/postal).
 * - Exact: full street address.
 *
 * v1 collapses the originally-specified "neighborhood" rung (no column backs it;
 * see D082). A reverse-geocoded neighborhood column is an additive follow-up.
 */
enum DisclosureLevel: string
{
    case None = 'none';
    case Area = 'area';
    case City = 'city';
    case Exact = 'exact';

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
            self::None => __('people.label_disclosure_level_none'),
            self::Area => __('people.label_disclosure_level_area'),
            self::City => __('people.label_disclosure_level_city'),
            self::Exact => __('people.label_disclosure_level_exact'),
        };
    }

    /**
     * Numeric rank by permissiveness (None=0 ... Exact=3).
     *
     * Used to fail-closed: pick the most restrictive of two candidate levels.
     */
    public function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Area => 1,
            self::City => 2,
            self::Exact => 3,
        };
    }

    /**
     * True when this level is at least as permissive as the given level.
     */
    public function isAtLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    /**
     * Return whichever of the two levels is more restrictive (lower rank).
     *
     * Convenience for fail-closed composition: the result never over-discloses
     * relative to either input.
     */
    public static function mostRestrictive(self $a, self $b): self
    {
        return $a->rank() <= $b->rank() ? $a : $b;
    }
}

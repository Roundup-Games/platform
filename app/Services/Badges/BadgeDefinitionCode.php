<?php

namespace App\Services\Badges;

use App\Enums\BadgeCategory;
use App\Enums\BadgeEligibility;
use App\Enums\BadgeMode;

/**
 * Code-side badge definition (D186): the reviewed-code source of truth
 * that `badges:sync` mirrors into the badge_definitions runtime table.
 *
 * Named BadgeDefinitionCode (not BadgeDefinition) to avoid colliding with
 * the Eloquent model App\Models\BadgeDefinition — this class is the
 * declaration, the model is its runtime cache row.
 *
 * A badge enters the platform exactly one way: a PR adds an entry to
 * BadgeRegistry plus an evaluator plus lang copy, sync upserts the row,
 * done. Logic is never hand-authored in the admin UI; admins own only
 * is_active (retirement) and display-metadata overrides.
 *
 * Immutable by construction: readonly properties, no setters — a
 * definition that needs to change changes in a reviewed diff.
 */
final readonly class BadgeDefinitionCode
{
    /**
     * @param  string  $key  Registry key, e.g. 'debut' — the sync/join key into badge_definitions.
     * @param  BadgeCategory  $category  Display/rarity grouping (spec §3 categories).
     * @param  BadgeMode  $mode  one_time | milestone | recurring | requalifying (spec §2.1).
     * @param  array<string, int>  $tiers  tier-key => award threshold; empty = tierless badge.
     *                                     Heterogeneous by design (Mentor counts distinct
     *                                     reviewers, Regulars' Table counts months) — the
     *                                     evaluator that declares the badge defines what the
     *                                     threshold counts. Tier keys follow the bronze /
     *                                     silver / gold progression used by spec §3 tables.
     * @param  string  $nameKey  Full translation key, e.g. 'badges.content_badge_debut_name'.
     * @param  string  $descriptionKey  Full translation key for the tooltip/description copy.
     * @param  string|null  $icon  Material-symbols-outlined ligature name — every
     *                             definition carries one since the S04 design-system
     *                             glyph set landed; null only for un-glyphed factory rows.
     * @param  class-string<BadgeEvaluator>  $evaluator  Evaluates award eligibility for this badge.
     * @param  bool  $isDormant  Definition registered but its signal does not exist yet —
     *                           the engine holds the shape until the feature ships
     *                           (Chartered → M070, Campaign Compleat → M071).
     * @param  BadgeEligibility  $eligibility  Population the rarity denominator counts
     *                                         (D195): AllUsers for player badges,
     *                                         GameMasters for GM-craft badges whose
     *                                         signal only GMs can produce.
     * @param  int  $sortOrder  Canonical gallery/admin ordering.
     */
    public function __construct(
        public string $key,
        public BadgeCategory $category,
        public BadgeMode $mode,
        public array $tiers,
        public string $nameKey,
        public string $descriptionKey,
        public ?string $icon,
        public string $evaluator,
        public bool $isDormant = false,
        public BadgeEligibility $eligibility = BadgeEligibility::AllUsers,
        public int $sortOrder = 0,
    ) {}
}

<?php

namespace App\Services\Badges;

use App\Enums\BadgeCategory;
use App\Enums\BadgeEligibility;
use App\Enums\BadgeMode;

/**
 * The code-side badge registry (D186, spec §2.1) — the reviewed-code
 * source of truth for what badges exist and how they are structured.
 *
 * Registration is an explicit, hand-maintained array. No reflection, no
 * attribute discovery: the whole point of D186 is that a badge appearing
 * or changing is a reviewable diff — PR adds an entry here plus an
 * evaluator class plus lang copy, `badges:sync` mirrors it into
 * badge_definitions, done. (An enum-based registry was considered and
 * rejected: per-badge evaluator classes, tier thresholds and eligibility
 * scopes outgrow enum methods.)
 *
 * Initial five definitions (S01):
 *
 * - debut / first_seat / founding_table — tierless one-time badges,
 *   live since S05 (DebutEvaluator / FirstSeatEvaluator /
 *   FoundingTableEvaluator; the founding launch window lives in
 *   config/badges.php per D208).
 * - campaign_compleat — dormant (D190): shape, tiers and copy
 *   registered now, activation in M071/S05. (Its former dormant pair
 *   chartered activated in M070/S04 — CharteredEvaluator.)
 *
 * First real evaluator (S02): supporting_member — active paid
 * membership tiered by completed months of support history
 * (SupportingMemberEvaluator, event-driven via the BadgePipeline
 * listener plus a nightly sweep). Second (S02 T3): regulars_table —
 * the recurring attendance streak, statelessly recomputed from
 * resolved attendance by RegularsTableEvaluator in the nightly sweep.
 *
 * S05 T2 pair: still_rolling — hosting tenure in full years since the
 * first hosted game (StillRollingEvaluator, milestone 1/3/5 years,
 * never lapses) — and guild_linked — a Discord guild link aged thirty
 * days on a live guild (GuildLinkedEvaluator, one-time per D209).
 *
 * S05 T3: mentor — distinct first-session 5/5 Game reviewers (D190:
 * published 5/5 on a Game review whose session was the reviewer's
 * earliest attended one with this GM; tiers 5/15/40 distinct
 * reviewers, MentorEvaluator, milestone never-lapse, live with
 * backfill).
 *
 * S05 T4: trusted_organizer — the quarterly requalifying engine
 * (D206/D207): per CLOSED calendar quarter, >= 3 completed organized
 * events, avg >= 4.5 over published Game reviews on the quarter's
 * event-linked tables, and < 10% unannounced cancellations — tiers at
 * 1/3/6 consecutive qualifying quarters (TrustedOrganizerEvaluator,
 * quiet lapse + Requalified per Regulars' Table semantics).
 *
 * Tier thresholds mirror docs/spec/badges-achievement-concept.md §3
 * tables: Chartered 1/10/50 charters, Campaign Compleat 1/3/5
 * completions, Supporting Member 0/12/24/60 months, Regulars' Table
 * 3/6/12 consecutive months of verified attendance.
 */
class BadgeRegistry
{
    /** @var array<string, BadgeDefinitionCode>|null Lazily built key => definition map. */
    private static ?array $byKey = null;

    /**
     * All registered definitions, in canonical sort order.
     *
     * @return array<int, BadgeDefinitionCode>
     */
    public function all(): array
    {
        return array_values($this->definitions());
    }

    /**
     * Look up one definition by registry key, or null when unknown.
     */
    public function find(string $key): ?BadgeDefinitionCode
    {
        return $this->definitions()[$key] ?? null;
    }

    /**
     * @return array<string, BadgeDefinitionCode>
     */
    private function definitions(): array
    {
        return self::$byKey ??= [
            'debut' => new BadgeDefinitionCode(
                key: 'debut',
                category: BadgeCategory::Milestones,
                mode: BadgeMode::OneTime,
                tiers: [],
                nameKey: 'badges.content_badge_debut_name',
                descriptionKey: 'badges.content_badge_debut_description',
                icon: 'rocket_launch',
                evaluator: DebutEvaluator::class,
                isDormant: false,
                sortOrder: 10,
            ),
            'first_seat' => new BadgeDefinitionCode(
                key: 'first_seat',
                category: BadgeCategory::Milestones,
                mode: BadgeMode::OneTime,
                tiers: [],
                nameKey: 'badges.content_badge_first_seat_name',
                descriptionKey: 'badges.content_badge_first_seat_description',
                icon: 'event_seat',
                evaluator: FirstSeatEvaluator::class,
                isDormant: false,
                sortOrder: 20,
            ),
            'founding_table' => new BadgeDefinitionCode(
                key: 'founding_table',
                category: BadgeCategory::SupportTenure,
                mode: BadgeMode::OneTime,
                tiers: [],
                nameKey: 'badges.content_badge_founding_table_name',
                descriptionKey: 'badges.content_badge_founding_table_description',
                icon: 'foundation',
                evaluator: FoundingTableEvaluator::class,
                isDormant: false,
                sortOrder: 30,
            ),
            'chartered' => new BadgeDefinitionCode(
                key: 'chartered',
                category: BadgeCategory::GmCraft,
                mode: BadgeMode::Milestone,
                tiers: ['bronze' => 1, 'silver' => 10, 'gold' => 50],
                nameKey: 'badges.content_badge_chartered_name',
                descriptionKey: 'badges.content_badge_chartered_description',
                icon: 'workspace_premium',
                evaluator: CharteredEvaluator::class,
                isDormant: false,
                eligibility: BadgeEligibility::GameMasters,
                sortOrder: 40,
            ),
            'campaign_compleat' => new BadgeDefinitionCode(
                key: 'campaign_compleat',
                category: BadgeCategory::GmCraft,
                mode: BadgeMode::Milestone,
                tiers: ['bronze' => 1, 'silver' => 3, 'gold' => 5],
                nameKey: 'badges.content_badge_campaign_compleat_name',
                descriptionKey: 'badges.content_badge_campaign_compleat_description',
                icon: 'auto_stories',
                evaluator: NoOpEvaluator::class,
                isDormant: true,
                eligibility: BadgeEligibility::GameMasters,
                sortOrder: 50,
            ),
            'supporting_member' => new BadgeDefinitionCode(
                key: 'supporting_member',
                category: BadgeCategory::SupportTenure,
                mode: BadgeMode::Recurring,
                tiers: ['year_1' => 0, 'year_2' => 12, 'year_3' => 24, 'year_5' => 60],
                nameKey: 'badges.content_badge_supporting_member_name',
                descriptionKey: 'badges.content_badge_supporting_member_description',
                icon: 'volunteer_activism',
                evaluator: SupportingMemberEvaluator::class,
                isDormant: false,
                sortOrder: 60,
            ),
            'regulars_table' => new BadgeDefinitionCode(
                key: 'regulars_table',
                category: BadgeCategory::Participation,
                mode: BadgeMode::Recurring,
                tiers: ['bronze' => 3, 'silver' => 6, 'gold' => 12],
                nameKey: 'badges.content_badge_regulars_table_name',
                descriptionKey: 'badges.content_badge_regulars_table_description',
                icon: 'calendar_month',
                evaluator: RegularsTableEvaluator::class,
                isDormant: false,
                sortOrder: 70,
            ),
            'still_rolling' => new BadgeDefinitionCode(
                key: 'still_rolling',
                category: BadgeCategory::SupportTenure,
                mode: BadgeMode::Milestone,
                tiers: ['year_1' => 1, 'year_3' => 3, 'year_5' => 5],
                nameKey: 'badges.content_badge_still_rolling_name',
                descriptionKey: 'badges.content_badge_still_rolling_description',
                icon: 'timelapse',
                evaluator: StillRollingEvaluator::class,
                isDormant: false,
                eligibility: BadgeEligibility::GameMasters,
                sortOrder: 80,
            ),
            'guild_linked' => new BadgeDefinitionCode(
                key: 'guild_linked',
                category: BadgeCategory::CommunityInfrastructure,
                mode: BadgeMode::OneTime,
                tiers: [],
                nameKey: 'badges.content_badge_guild_linked_name',
                descriptionKey: 'badges.content_badge_guild_linked_description',
                icon: 'link',
                evaluator: GuildLinkedEvaluator::class,
                isDormant: false,
                eligibility: BadgeEligibility::GameMasters,
                sortOrder: 90,
            ),
            'trusted_organizer' => new BadgeDefinitionCode(
                key: 'trusted_organizer',
                category: BadgeCategory::EventsOrganizing,
                mode: BadgeMode::Requalifying,
                tiers: ['bronze' => 1, 'silver' => 3, 'gold' => 6],
                nameKey: 'badges.content_badge_trusted_organizer_name',
                descriptionKey: 'badges.content_badge_trusted_organizer_description',
                icon: 'verified',
                evaluator: TrustedOrganizerEvaluator::class,
                isDormant: false,
                eligibility: BadgeEligibility::GameMasters,
                sortOrder: 100,
            ),
            'mentor' => new BadgeDefinitionCode(
                key: 'mentor',
                category: BadgeCategory::GmCraft,
                mode: BadgeMode::Milestone,
                tiers: ['bronze' => 5, 'silver' => 15, 'gold' => 40],
                nameKey: 'badges.content_badge_mentor_name',
                descriptionKey: 'badges.content_badge_mentor_description',
                icon: 'school',
                evaluator: MentorEvaluator::class,
                isDormant: false,
                eligibility: BadgeEligibility::GameMasters,
                sortOrder: 110,
            ),
        ];
    }
}

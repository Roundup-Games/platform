<?php

namespace App\Services\Badges;

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\JournalEntryKind;
use App\Models\BadgeUser;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Campaign Compleat (spec §3 GM Craft row, D222; live since M071/S05):
 * the completed-campaign milestone — a campaign counts when it is
 * completed, held at least six completed sessions, and at least 80% of
 * those sessions carry a recap in the campaign journal. Tiers at
 * 1/3/5 qualifying completions across campaigns the user owns;
 * eligibility GameMasters.
 *
 * Qualification semantics (D222, authoritative):
 *
 * - A session is COMPLETED when its Game (campaign_id-linked) has
 *   status completed. Session zero counts: a completed Game linked via
 *   campaign_id is a real table sitting, and the "six completed
 *   sessions" floor carries no session-zero exclusion — a recap on it
 *   counts toward coverage like any other session's.
 * - A session is COVERED when at least one kind=recap journal entry
 *   links to it via game_id AND its Game is completed. Interlude
 *   recaps (game_id null) never count; in_character/prep_note entries
 *   never count. The campaign_journal_entries_one_recap_per_session
 *   partial unique index makes the entry count distinct-safe per
 *   session. Migrated legacy recaps count (they are journal rows).
 * - The 80% floor is integer-safe: covered * 5 >= completed * 4 (six
 *   sessions need five covered; ten need exactly eight).
 * - The games.recap projection column is NEVER read (D220) — the
 *   journal is the source of truth, and coverage derives from
 *   game_id-linked entries alone.
 * - NO game_type filter anywhere (D218): every campaign type can
 *   qualify through the same predicate.
 *
 * Count = qualifying completed campaigns owned by the user; the tier
 * is the highest threshold (bronze 1 / silver 3 / gold 5, from the
 * registry entry) the count reaches. Evidence is the latest
 * qualifying Campaign (updated_at desc, id desc; uuid-keyed via
 * HasPlatformUuid, so it satisfies badge_user.evidence_id — morph
 * alias 'campaign' per D170).
 *
 * Milestone semantics (spec §2.1): award and upgrade only, NEVER
 * lapse — sweep() carries no lapse() call at all. evaluate() is a
 * pure proposal (BadgeService owns every write); sweep() is the
 * stateless backfill: everyone who owns a completed campaign (plus
 * current holders) is re-proposed on the nightly sweep, and repeat
 * sweeps converge through BadgeService's AlreadyHeld/Upgraded
 * decisions. Owners of completed-but-below-threshold campaigns park
 * harmlessly (a null proposal writes nothing). A sweep failure never
 * crashes the run — the orchestrator isolates evaluators
 * (BadgesSweep).
 *
 * Demo-fixture note: the demo cohort's holds stay 0 by design —
 * DemoSeed creates no journal entries, and no demo fixture reaches
 * six completed sessions at 80% coverage (pinned by
 * DemoCohortProofTest).
 */
class CampaignCompleatEvaluator implements BadgeEvaluator
{
    private const BADGE_KEY = 'campaign_compleat';

    /** D222: the minimum completed-session floor a qualifying campaign must reach. */
    private const MIN_COMPLETED_SESSIONS = 6;

    public function evaluate(User $user): ?BadgeAward
    {
        $proposal = $this->proposalFor($user);

        if ($proposal === null) {
            return null;
        }

        [$tierKey, $evidence] = $proposal;

        return BadgeAward::for(self::BADGE_KEY, $tierKey, $evidence);
    }

    public function sweep(): void
    {
        $service = app(BadgeService::class);

        $this->cohort()->chunkById(200, function ($users) use ($service): void {
            /** @var iterable<User> $users */
            foreach ($users as $user) {
                $proposal = $this->proposalFor($user);

                if ($proposal === null) {
                    // Milestone badges never lapse (spec §2.1): an owner
                    // of completed-but-below-threshold campaigns parks
                    // nothing — the completions keep aging toward bronze.
                    continue;
                }

                [$tierKey, $evidence] = $proposal;

                $service->award($user, self::BADGE_KEY, $tierKey, $evidence);
            }
        });
    }

    // ── Internals ──────────────────────────────────────

    /**
     * The live computation both surfaces share: the tier the
     * qualifying-completion count has earned plus the latest
     * qualifying Campaign as evidence, or null below bronze (no
     * qualifying campaign).
     *
     * @return array{0: string, 1: Campaign}|null
     */
    private function proposalFor(User $user): ?array
    {
        $qualifying = $this->completedCampaignsWithCounts($user)
            ->filter(fn (Campaign $campaign): bool => $this->qualifies($campaign));

        $count = $qualifying->count();

        $tierKey = null;

        foreach ($this->tierThresholds() as $tier => $threshold) {
            if ($count >= $threshold) {
                // Thresholds ascend, so the last match is the highest earned.
                $tierKey = $tier;
            }
        }

        if ($tierKey === null) {
            return null;
        }

        $evidence = $qualifying->first();

        // Non-null by construction — a tier was reached, so at least one
        // qualifying row exists — but the guard keeps the tuple's type
        // honest under level-9 analysis.
        if ($evidence === null) {
            return null;
        }

        return [$tierKey, $evidence];
    }

    /**
     * The user's completed campaigns with the two D222 aggregates:
     * completed sessions, and how many of the campaign's sessions hold
     * a game_id-linked recap on a completed Game. Ordered newest-first
     * so the FIRST qualifying row doubles as the evidence pointer.
     *
     * @return Collection<int, Campaign>
     */
    private function completedCampaignsWithCounts(User $user): Collection
    {
        return Campaign::query()
            ->whereBelongsTo($user, 'owner')
            ->where('status', CampaignStatus::Completed->value)
            ->withCount([
                'sessions as completed_sessions_count' => fn (Builder $session): Builder => $session
                    ->where('games.status', GameStatus::Completed->value),
                'journalEntries as covered_sessions_count' => fn (Builder $entry): Builder => $entry
                    ->where('campaign_journal_entries.kind', JournalEntryKind::Recap->value)
                    ->whereNotNull('campaign_journal_entries.game_id')
                    ->whereHas('game', fn (Builder $game): Builder => $game
                        ->where('games.status', GameStatus::Completed->value)),
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * D222 qualification for one completed campaign: at least six
     * completed sessions (session zero included — see the class
     * docblock) and integer-safe >=80% coverage
     * (covered * 5 >= completed * 4).
     */
    private function qualifies(Campaign $campaign): bool
    {
        $completed = (int) $campaign->completed_sessions_count;
        $covered = (int) $campaign->covered_sessions_count;

        return $completed >= self::MIN_COMPLETED_SESSIONS
            && $covered * 5 >= $completed * 4;
    }

    /**
     * Tier thresholds from the registry entry — the same array
     * badges:sync mirrors into badge_definitions, so the evaluator and
     * the write side can never disagree about what a tier means.
     *
     * @return array<string, int>
     */
    private function tierThresholds(): array
    {
        $definition = app(BadgeRegistry::class)->find(self::BADGE_KEY);

        if ($definition === null) {
            throw new LogicException(sprintf('%s is missing from the badge registry', self::BADGE_KEY));
        }

        $tiers = $definition->tiers;

        // Thresholds are declared ascending; sort defensively so the
        // "last match wins" tier pick above can never mis-order.
        asort($tiers);

        return $tiers;
    }

    /**
     * Users in this badge's orbit: anyone owning a completed campaign
     * (the coverage math crosses thresholds as sessions finish and
     * recaps land) plus current holders (so a revoked hold
     * requalifies through the audited pipeline).
     *
     * The whole predicate is ONE grouped where for the chunkById
     * reason (see SupportingMemberEvaluator): the cursor's id > last
     * is a top-level AND, and SQL AND binds tighter than OR — an
     * ungrouped cohort predicate would re-match users in every chunk
     * and loop forever.
     *
     * @return Builder<User>
     */
    private function cohort(): Builder
    {
        return User::query()
            ->where(function (Builder $query): void {
                $query->whereHas('ownedCampaigns', fn (Builder $campaign): Builder => $campaign
                    ->where('campaigns.status', CampaignStatus::Completed->value))
                    ->orWhereIn('id', BadgeUser::query()
                        ->whereHas('badgeDefinition', fn (Builder $definition) => $definition->where('key', self::BADGE_KEY))
                        ->select('user_id'));
            });
    }
}

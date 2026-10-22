<?php

namespace App\Services\Badges;

use App\Models\DiscordGuildOrganizer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Guild Linked (spec §3 Community Infrastructure row, M069 S05 T2 per
 * D209): the organizer's Discord guild link has aged thirty days and
 * is still alive — the guild row exists and the landlord has not
 * paused it.
 *
 * - The derivation is the D209 connectedness proxy, verified against
 *   the live schema: DiscordGuild.paused is the GuildSettings posting
 *   pause, no guild-deletion flow exists, and the organizers FK
 *   cascades on guild delete — so whereHas('guild') + paused=false is
 *   the full "still connected" condition.
 * - OneTime, tierless, no lapse (D209): the 30-day aging happens
 *   before any award, so once held the badge never parks — admin
 *   revocation is the removal path, and a revoked hold re-awards
 *   through the audited pipeline when a qualifying link exists.
 * - Evidence is the DiscordGuildOrganizer opt-in row itself (morph
 *   alias 'discord_guild_organizer', D170), ordered by opted_in_at so
 *   repeat sweeps always re-propose the same pointer.
 * - evaluate() runs from the MembershipActivated pipeline for every
 *   registered badge and must stay cheap and pure: one earliest-row
 *   lookup, no writes — BadgeService owns the audited write side.
 * - sweep() is the stateless backfill: everyone with a guild opt-in
 *   row (plus current holders) is re-proposed, so the badge appears
 *   on the nightly sweep that follows the 30th day.
 */
class GuildLinkedEvaluator extends CohortSweepEvaluator
{
    private const BADGE_KEY = 'guild_linked';

    protected function badgeKey(): string
    {
        return self::BADGE_KEY;
    }

    /** D209: the link must have aged thirty full days before it counts. */
    private const MINIMUM_LINK_AGE_DAYS = 30;

    // ── Internals ──────────────────────────────────────

    /**
     * The oldest qualifying guild link (evidence pointer, morph alias
     * 'discord_guild_organizer' per D170) — the earliest opt-in row
     * that has aged thirty days on a live (existing, unpaused) guild,
     * or null.
     *
     * @return array{0: string|null, 1: DiscordGuildOrganizer|null}|null
     */
    protected function proposalFor(User $user): ?array
    {
        $evidence = DiscordGuildOrganizer::query()
            ->whereBelongsTo($user)
            ->whereNotNull('opted_in_at')
            ->where('opted_in_at', '<=', now()->subDays(self::MINIMUM_LINK_AGE_DAYS))
            ->whereHas('guild', fn (Builder $guild): Builder => $guild->where('paused', false))
            ->orderBy('opted_in_at')
            ->first();

        return $evidence === null ? null : [null, $evidence];
    }

    /**
     * Anyone with a guild-organizer link (the 30-day clock keeps
     * aging); holders join through the skeleton's holders half. The
     * grouped-where invariant lives in CohortSweepEvaluator::cohort().
     *
     * @param  Builder<User>  $grouped
     */
    protected function qualifyingCohort(Builder $grouped): Builder
    {
        return $grouped->whereHas('discordGuildOrganizers');
    }
}

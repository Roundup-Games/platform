<?php

namespace App\Services\Badges;

use App\Models\BadgeUser;
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
class GuildLinkedEvaluator implements BadgeEvaluator
{
    private const BADGE_KEY = 'guild_linked';

    /** D209: the link must have aged thirty full days before it counts. */
    private const MINIMUM_LINK_AGE_DAYS = 30;

    public function evaluate(User $user): ?BadgeAward
    {
        $evidence = $this->proposalFor($user);

        if ($evidence === null) {
            return null;
        }

        return BadgeAward::for(self::BADGE_KEY, null, $evidence);
    }

    public function sweep(): void
    {
        $service = app(BadgeService::class);

        $this->cohort()->chunkById(200, function ($users) use ($service): void {
            /** @var iterable<User> $users */
            foreach ($users as $user) {
                $evidence = $this->proposalFor($user);

                if ($evidence === null) {
                    // OneTime, no lapse (D209): a link still under
                    // thirty days, opted out, paused, or gone simply
                    // is not earned — nothing to park, nothing to strip.
                    continue;
                }

                $service->award($user, self::BADGE_KEY, null, $evidence);
            }
        });
    }

    // ── Internals ──────────────────────────────────────

    /**
     * The earliest qualifying opt-in row, or null when no guild link
     * has aged thirty days on a live (existing, unpaused) guild.
     */
    private function proposalFor(User $user): ?DiscordGuildOrganizer
    {
        return DiscordGuildOrganizer::query()
            ->whereBelongsTo($user)
            ->whereNotNull('opted_in_at')
            ->where('opted_in_at', '<=', now()->subDays(self::MINIMUM_LINK_AGE_DAYS))
            ->whereHas('guild', fn (Builder $guild): Builder => $guild->where('paused', false))
            ->orderBy('opted_in_at')
            ->first();
    }

    /**
     * Users in this badge's orbit: anyone with a guild opt-in row
     * (the 30th day completes at any time) plus current holders (so a
     * revoked hold requalifies through the audited pipeline once a
     * qualifying link exists again).
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
                $query->whereHas('discordGuildOrganizers')
                    ->orWhereIn('id', BadgeUser::query()
                        ->whereHas('badgeDefinition', fn (Builder $definition) => $definition->where('key', self::BADGE_KEY))
                        ->select('user_id'));
            });
    }
}

<?php

namespace App\Services\Badges;

use App\Models\BadgeUser;

/**
 * Read-side badge display lookups for card surfaces (M069 S04 T4).
 *
 * The GM Directory card grid re-renders on every filter/sort interaction,
 * so this service exists to keep the card chip at a constant query count
 * per page — the batched shape mirrors
 * ReviewAggregateService::topProficienciesForProfiles, never one query
 * per card.
 */
class BadgesDisplayService
{
    /**
     * Top badge hold per user for a page of cards.
     *
     * One query (whereIn user_id) over badge_user joined to
     * badge_definitions, ordered so the first row per user is their top
     * chip: featured holds first (badge_user.is_featured — the user's own
     * shelf selection), then lowest definition sort_order. Lapsed holds
     * are excluded on purpose — a lapsed streak badge is not someone's
     * top card chip; lapsed stays visible on the full profile shelf
     * instead (spec §4: lapse honestly, never vanish). Revoked holds are
     * admin actions and never display-worthy.
     *
     * @param  array<int, string>  $userIds
     * @return array<string, BadgeUser> user id => top hold with badgeDefinition eager-loaded; users without a display-worthy hold are absent
     */
    public function topBadgesForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $holds = BadgeUser::query()
            ->whereIn('badge_user.user_id', $userIds)
            ->whereNull('badge_user.revoked_at')
            ->whereNull('badge_user.lapsed_at')
            ->join('badge_definitions', 'badge_definitions.id', '=', 'badge_user.badge_definition_id')
            ->orderByDesc('badge_user.is_featured')
            ->orderBy('badge_definitions.sort_order')
            ->with('badgeDefinition')
            ->get(['badge_user.*']);

        // Ordered result, window-free reduction: the first row seen per
        // user is that user's top badge.
        $top = [];
        foreach ($holds as $hold) {
            $top[(string) $hold->user_id] ??= $hold;
        }

        return $top;
    }
}

<?php

namespace App\Services\Badges;

use App\Models\BadgeUser;
use App\Models\Game;

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
     * Badge keys eligible for the game-listing author chip (D237, spec
     * §2.4 "subscriber + Compleat-class only, avoid noise").
     *
     * Reading: the phrase names the two badge CLASSES that may appear on a
     * listing card — the subscriber badges (Supporting Member / Founding
     * Supporter, spec §5's "exactly two" membership-certifying badges) and
     * the Compleat-class badges (campaign_compleat, live since M071/D222).
     * Order is display precedence: earned GM-craft signal first, support
     * badges after (spec §1.5 "no purchasable prestige" — a money chip
     * never outranks an earned one); within the subscriber class the
     * one-time Founding Supporter out-ranks the recurring membership tier.
     * founding_supporter is forward-compat: no registry entry exists yet,
     * so the key simply matches nothing until wave 1 lands it.
     *
     * @var list<string>
     */
    public const AUTHOR_CHIP_KEYS = [
        'campaign_compleat',
        'founding_supporter',
        'supporting_member',
    ];

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
            $top[$hold->user_id] ??= $hold;
        }

        return $top;
    }

    /**
     * The game-listing author chip per user (D237, spec §2.4).
     *
     * One batched query over badge_user joined to badge_definitions,
     * restricted to {@see AUTHOR_CHIP_KEYS} — same lifecycle exclusions as
     * the directory chip: revoked holds are admin actions, and a lapsed
     * Supporting Member no longer certifies an active subscription, so
     * neither is listing-worthy. The first hold per user in AUTHOR_CHIP_KEYS
     * precedence order wins (see the constant's docblock).
     *
     * @param  array<int, string>  $userIds
     * @return array<string, BadgeUser> user id => chip hold with badgeDefinition eager-loaded; users without a listing-worthy hold are absent
     */
    public function authorChipsForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $holds = BadgeUser::query()
            ->whereIn('badge_user.user_id', $userIds)
            ->whereNull('badge_user.revoked_at')
            ->whereNull('badge_user.lapsed_at')
            ->join('badge_definitions', 'badge_definitions.id', '=', 'badge_user.badge_definition_id')
            ->whereIn('badge_definitions.key', self::AUTHOR_CHIP_KEYS)
            ->with('badgeDefinition')
            ->get(['badge_user.*']);

        $precedence = array_flip(self::AUTHOR_CHIP_KEYS);

        $chips = [];
        foreach ($holds as $hold) {
            $definition = $hold->badgeDefinition;
            if (! isset($precedence[$definition->key])) {
                continue;
            }

            $current = $chips[$hold->user_id] ?? null;
            if ($current === null
                || $precedence[$definition->key] < $precedence[$current->badgeDefinition->key]) {
                $chips[$hold->user_id] = $hold;
            }
        }

        return $chips;
    }

    /**
     * Attach the author chip to a page of listing items in one batch.
     *
     * Feeders call this right before returning (discovery results, the
     * recommendations rail, city-hub sessions, game-system upcoming tables,
     * the My Games board): every Game gets an author_chip attribute
     * (BadgeUser|null) resolved by ONE holds query per page — the
     * GmDirectory top_badge pattern, never per card. Non-Game items (mixed
     * listing collections carry campaigns/events too) pass through
     * untouched, and games whose owner never loaded still resolve via the
     * owner_id column.
     *
     * @param  iterable<array-key, mixed>  $items
     */
    public function attachAuthorChips(iterable $items): void
    {
        $games = [];
        foreach ($items as $item) {
            if ($item instanceof Game) {
                $games[] = $item;
            }
        }

        if ($games === []) {
            return;
        }

        /** @var list<string> $ownerIds */
        $ownerIds = array_values(array_unique(array_map(fn (Game $game): string => $game->owner_id, $games)));

        $chips = $this->authorChipsForUsers($ownerIds);

        foreach ($games as $game) {
            $game->author_chip = $chips[$game->owner_id] ?? null;
        }
    }
}

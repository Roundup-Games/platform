<?php

namespace App\Services;

use App\Models\User;

/**
 * Resolves which profile fields a viewer is allowed to see on a given profile owner.
 *
 * Fields: location, game_systems, vibes, campaigns, teams, friends_list.
 * Each field maps to a key in the owner's privacy_settings JSON with values:
 *   - "everyone"  → visible to all viewers (including guests)
 *   - "friends"   → visible to friends/teammates of the owner
 *   - "nobody"    → hidden from everyone (except self)
 *
 * Special cases:
 *   - Self (viewer === owner): sees everything, regardless of settings.
 *   - Blocked viewers: see only name + avatar (no profile fields).
 *   - Null viewer (guest): sees fields set to "everyone".
 */
class ProfileVisibilityResolver
{
    /**
     * All profile fields controlled by privacy settings.
     */
    public const FIELDS = [
        'location',
        'game_systems',
        'vibes',
        'campaigns',
        'teams',
        'friends_list',
        'stats',
    ];

    /**
     * Default visibility for fields not explicitly set in privacy_settings.
     */
    private const DEFAULT_VISIBILITY = 'everyone';

    /**
     * Get the list of profile field keys visible to the given viewer.
     *
     * @param  User|null  $viewer  The viewing user (null for guests).
     * @param  User  $owner  The profile owner.
     * @return string[] List of visible field keys.
     */
    public function profileFieldsVisible(?User $viewer, User $owner): array
    {
        // Self sees everything
        if ($viewer !== null && $viewer->is($owner)) {
            return self::FIELDS;
        }

        // Blocked viewers see no profile fields (only name + avatar)
        if ($viewer !== null && ($viewer->isBlockedBy($owner) || $viewer->hasBlocked($owner))) {
            return [];
        }

        // Determine the relationship level for access control
        $level = $viewer !== null ? $owner->getRelationshipLevel($viewer) : 'stranger';

        return $this->fieldsVisibleAtLevel($level, $owner);
    }

    /**
     * Resolve visible profile fields for MANY owners in one pass — the list
     * counterpart of {@see profileFieldsVisible()}, for surfaces that render
     * several profiles at once (nearby-people discovery).
     *
     * Identical rules, identical results: the per-owner method costs up to
     * nine queries (three relationship probes, the block pair twice), so
     * walking a candidate list with it is quadratic traffic. This resolves
     * the viewer's relationship to every owner through three set-based
     * lookups instead, then applies the same {@see isFieldVisible()} rule.
     *
     * @param  iterable<User>  $owners  Profile owners to resolve.
     * @return array<string, list<string>> Owner id → visible field keys, one entry per owner.
     */
    public function profileFieldsVisibleForMany(?User $viewer, iterable $owners): array
    {
        /** @var array<string, User> $byId */
        $byId = [];
        foreach ($owners as $owner) {
            $byId[$owner->id] = $owner;
        }

        if ($viewer === null) {
            // A guest is a stranger to everyone: only 'everyone' fields —
            // but still under EACH owner's own settings.
            $result = [];
            foreach ($byId as $id => $owner) {
                $result[$id] = $this->fieldsVisibleAtLevel('stranger', $owner);
            }

            return $result;
        }

        $graph = app(SocialGraphService::class);

        $blockedIds = array_fill_keys($graph->blockedUserIdsFor($viewer), true);

        $candidateIds = array_values(array_filter(
            array_keys($byId),
            fn (string $id): bool => $id !== $viewer->id && ! isset($blockedIds[$id]),
        ));

        $friendOrTeammateIds = array_fill_keys($graph->friendOrTeammateIdsAmong($viewer, $candidateIds), true);

        $result = [];
        foreach ($byId as $id => $owner) {
            if ($viewer->is($owner)) {
                $result[$id] = self::FIELDS;

                continue;
            }

            if (isset($blockedIds[$id])) {
                $result[$id] = [];

                continue;
            }

            $result[$id] = $this->fieldsVisibleAtLevel(
                isset($friendOrTeammateIds[$id]) ? 'friend_or_teammate' : 'stranger',
                $owner,
            );
        }

        return $result;
    }

    /**
     * Fields visible to a viewer at the given relationship level, under the
     * owner's privacy settings (defaults to visible when unset).
     *
     * @return list<string>
     */
    private function fieldsVisibleAtLevel(string $level, User $owner): array
    {
        /** @var array<string, string> $settings */
        $settings = $owner->privacy_settings ?? [];

        $visible = [];
        foreach (self::FIELDS as $field) {
            $setting = isset($settings[$field])
                ? $settings[$field]
                : self::DEFAULT_VISIBILITY;

            if ($this->isFieldVisible($setting, $level)) {
                $visible[] = $field;
            }
        }

        return $visible;
    }

    /**
     * Check whether a field with the given privacy setting is visible at the given relationship level.
     */
    private function isFieldVisible(string $setting, string $level): bool
    {
        return match ($setting) {
            'everyone' => true,
            'friends' => $level === 'friend_or_teammate',
            'nobody' => false,
            default => true, // Unknown settings default to visible
        };
    }
}

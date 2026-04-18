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
    ];

    /**
     * Default visibility for fields not explicitly set in privacy_settings.
     */
    private const DEFAULT_VISIBILITY = 'everyone';

    /**
     * Get the list of profile field keys visible to the given viewer.
     *
     * @param User|null $viewer  The viewing user (null for guests).
     * @param User      $owner   The profile owner.
     * @return string[] List of visible field keys.
     */
    public function profileFieldsVisible(?User $viewer, User $owner): array
    {
        // Self sees everything
        if ($viewer && $viewer->is($owner)) {
            return self::FIELDS;
        }

        // Blocked viewers see no profile fields (only name + avatar)
        if ($viewer && ($viewer->isBlockedBy($owner) || $viewer->hasBlocked($owner))) {
            return [];
        }

        // Determine the relationship level for access control
        $level = $viewer ? $owner->getRelationshipLevel($viewer) : 'stranger';

        $visible = [];
        $settings = $owner->privacy_settings ?? [];

        foreach (self::FIELDS as $field) {
            $setting = $settings[$field] ?? self::DEFAULT_VISIBILITY;

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
            'friends'  => $level === 'friend_or_teammate',
            'nobody'   => false,
            default    => true, // Unknown settings default to visible
        };
    }
}

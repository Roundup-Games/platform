<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stateless service for querying the social graph between users.
 *
 * Each method receives User parameters instead of relying on $this,
 * making the logic testable and reusable outside the User model.
 * The service is injectable via the container and logs relationship
 * queries for abuse detection.
 */
class SocialGraphService
{
    // ── Follow Queries ────────────────────────────────

    /**
     * Check if $viewer follows $target.
     */
    public function isFollowing(User $viewer, User $target): bool
    {
        return $viewer->followings()
            ->whereBelongsTo($target, 'related')
            ->exists();
    }

    /**
     * Check if $viewer is followed by $target.
     */
    public function isFollowedBy(User $viewer, User $target): bool
    {
        return $viewer->followers()
            ->whereBelongsTo($target)
            ->exists();
    }

    // ── Block Queries ─────────────────────────────────

    /**
     * Check if $viewer has been blocked by $target.
     */
    public function isBlockedBy(User $viewer, User $target): bool
    {
        return $viewer->blockedBy()
            ->whereBelongsTo($target)
            ->exists();
    }

    /**
     * Check if $viewer has blocked $target.
     */
    public function hasBlocked(User $viewer, User $target): bool
    {
        return $viewer->blocks()
            ->whereBelongsTo($target, 'related')
            ->exists();
    }

    // ── Composite Relationship Queries ────────────────

    /**
     * Check if two users are friends (mutual follow with no blocks either direction).
     */
    public function isFriend(User $a, User $b): bool
    {
        return $this->isFollowing($a, $b)
            && $this->isFollowedBy($a, $b)
            && ! $this->hasBlocked($a, $b)
            && ! $this->isBlockedBy($a, $b);
    }

    /**
     * Check if two users are friends or teammates on an active team.
     */
    public function isFriendOrTeammate(User $a, User $b): bool
    {
        if ($this->isFriend($a, $b)) {
            return true;
        }

        return $this->hasSharedActiveTeamWith($a, $b);
    }

    /**
     * Get the relationship level between $viewer and $target.
     *
     * Returns one of: 'self', 'friend_or_teammate', 'blocked', 'stranger'.
     */
    public function getRelationshipLevel(User $viewer, User $target): string
    {
        if ($viewer->is($target)) {
            return 'self';
        }

        if ($this->isBlockedBy($viewer, $target) || $this->hasBlocked($viewer, $target)) {
            return 'blocked';
        }

        if ($this->isFriendOrTeammate($viewer, $target)) {
            return 'friend_or_teammate';
        }

        return 'stranger';
    }

    // ── Team Queries ──────────────────────────────────

    /**
     * Check if two users share an active team membership.
     */
    public function hasSharedActiveTeamWith(User $a, User $b): bool
    {
        $aTeamIds = $a->teams()
            ->wherePivot('status', 'active')
            ->pluck('teams.id');

        if ($aTeamIds->isEmpty()) {
            return false;
        }

        return $b->teams()
            ->wherePivot('status', 'active')
            ->whereIn('teams.id', $aTeamIds)
            ->exists();
    }

    // ── Aggregate Queries ─────────────────────────────

    /**
     * Get the user IDs whose protected content $user can see: self + friends + teammates.
     *
     * Friends are mutual follows (I follow them AND they follow me, no blocks).
     * Teammates are users sharing an active team membership.
     *
     * @return list<mixed>
     */
    public function getAllowedOwnerIdsForProtectedContent(User $user): array
    {
        $ids = [(string) $user->id];

        $friendIds = to_string_id_array($this->getMutualFollows($user)->pluck('id'));
        /** @var array<int, string> $friendIds */
        $ids = array_merge($ids, $friendIds);

        // Teammate user IDs: users who share an active team membership
        $myActiveTeamIds = $user->teams()
            ->wherePivot('status', 'active')
            ->pluck('teams.id');

        if ($myActiveTeamIds->isNotEmpty()) {
            $teammateUserIds = DB::table('team_members')
                ->whereIn('team_id', $myActiveTeamIds)
                ->where('status', 'active')
                ->where('user_id', '!=', $user->id)
                ->distinct()
                ->pluck('user_id')
                ->map(fn (mixed $id): string => to_string_id($id))
                ->toArray();
            $ids = array_merge($ids, $teammateUserIds);
        }

        $ids = array_filter($ids, fn (mixed $v) => is_string($v));

        return array_values(array_unique($ids));
    }

    /**
     * Get the mutual follows for a user (intersection of followers and followings).
     *
     * Returns a collection of User models representing bidirectional follows.
     *
     * @return Collection<int, User>
     */
    public function getMutualFollows(User $user): Collection
    {
        $iFollow = $user->followings()->pluck('related_user_id');
        $followsMe = $user->followers()->pluck('user_id');
        $mutualIds = $iFollow->intersect($followsMe);

        return User::whereIn('id', $mutualIds)
            ->whereNull('anonymized_at')
            ->get();
    }
}

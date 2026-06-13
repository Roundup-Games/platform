<?php

namespace Tests\Traits;

use App\Enums\RelationshipType;
use App\Models\User;
use App\Models\UserRelationship;

/**
 * Shared helpers for creating relationship and social graph test state.
 *
 * Consolidates duplicated helpers from:
 * - ParticipantManagementTest::makeMutualFriends
 */
trait CreatesRelationships
{
    /**
     * Make two users mutual friends (both follow each other).
     */
    public function makeMutualFriends(User $a, User $b): void
    {
        UserRelationship::create([
            'user_id' => $a->id,
            'related_user_id' => $b->id,
            'type' => RelationshipType::Follow,
        ]);
        UserRelationship::create([
            'user_id' => $b->id,
            'related_user_id' => $a->id,
            'type' => RelationshipType::Follow,
        ]);
    }
}

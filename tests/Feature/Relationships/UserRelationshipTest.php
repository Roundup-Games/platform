<?php

use App\Enums\RelationshipType;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRelationship;
use App\Enums\ParticipantRole;

/*
|--------------------------------------------------------------------------
| Comprehensive Relationship Tests (Pest)
|--------------------------------------------------------------------------
|
| This test suite provides end-to-end coverage of the relationship system:
|   - UserRelationship model actions (follow, unfollow, block, unblock)
|   - User model resolution helpers (isFollowing, isFriend, etc.)
|   - Edge cases and cross-concern interactions
|
*/

// ── Follow Action ──────────────────────────────────────

describe('Follow Action', function () {
    it('creates a follow relationship', function () {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $rel = UserRelationship::follow($user, $target);

        expect($rel->type)->toBe(RelationshipType::Follow);
        $this->assertDatabaseHas('user_relationships', [
            'user_id' => $user->id,
            'related_user_id' => $target->id,
            'type' => 'follow',
        ]);
    });

});

// ── Unfollow Action ────────────────────────────────────

describe('Unfollow Action', function () {
    it('removes an existing follow', function () {
        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::follow($user, $target);
        $result = UserRelationship::unfollow($user, $target);

        expect($result)->toBeTrue();
        $this->assertDatabaseMissing('user_relationships', [
            'user_id' => $user->id,
            'related_user_id' => $target->id,
            'type' => 'follow',
        ]);
    })->group('smoke');

    it('does not remove blocks', function () {
        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::block($user, $target);
        UserRelationship::unfollow($user, $target);

        $this->assertDatabaseHas('user_relationships', [
            'user_id' => $user->id,
            'related_user_id' => $target->id,
            'type' => 'block',
        ]);
    });

    it('does not remove the reverse follow', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        UserRelationship::follow($alice, $bob);
        UserRelationship::follow($bob, $alice);

        UserRelationship::unfollow($alice, $bob);

        // Bob still follows Alice
        $this->assertDatabaseHas('user_relationships', [
            'user_id' => $bob->id,
            'related_user_id' => $alice->id,
            'type' => 'follow',
        ]);
        // Alice no longer follows Bob
        $this->assertDatabaseMissing('user_relationships', [
            'user_id' => $alice->id,
            'related_user_id' => $bob->id,
            'type' => 'follow',
        ]);
    });

});

// ── Block Action ───────────────────────────────────────

describe('Block Action', function () {
    it('creates a block relationship', function () {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $rel = UserRelationship::block($user, $target);

        expect($rel->type)->toBe(RelationshipType::Block);
        $this->assertDatabaseHas('user_relationships', [
            'user_id' => $user->id,
            'related_user_id' => $target->id,
            'type' => 'block',
        ]);
    });

    it('removes existing follow from initiator to target', function () {
        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::follow($user, $target);
        UserRelationship::block($user, $target);

        $this->assertDatabaseMissing('user_relationships', [
            'user_id' => $user->id,
            'related_user_id' => $target->id,
            'type' => 'follow',
        ]);
        $this->assertDatabaseHas('user_relationships', [
            'user_id' => $user->id,
            'related_user_id' => $target->id,
            'type' => 'block',
        ]);
    });

    it('removes existing follow from target to initiator', function () {
        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::follow($target, $user);
        UserRelationship::block($user, $target);

        $this->assertDatabaseMissing('user_relationships', [
            'user_id' => $target->id,
            'related_user_id' => $user->id,
            'type' => 'follow',
        ]);
    });

    it('removes mutual follows in both directions', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        UserRelationship::follow($alice, $bob);
        UserRelationship::follow($bob, $alice);

        UserRelationship::block($alice, $bob);

        expect(UserRelationship::where('type', 'follow')->count())->toBe(0);
        expect(UserRelationship::where('type', 'block')->count())->toBe(1);
    });

    it('is idempotent (does not create duplicate blocks)', function () {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $first = UserRelationship::block($user, $target);
        $second = UserRelationship::block($user, $target);

        expect(UserRelationship::where('type', 'block')->count())->toBe(1);
        expect($first->id)->toBe($second->id);
    });

    it('prevents new follow after block is in place (follow still created but block remains)', function () {
        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::block($user, $target);

        // Technically, follow() will create a follow record alongside the block.
        // The unique constraint is on (user_id, related_user_id, type),
        // so a follow can coexist with a block. The resolution logic
        // (isFriend, getRelationshipLevel) handles the precedence.
        UserRelationship::follow($user, $target);

        // Block still exists
        $this->assertDatabaseHas('user_relationships', [
            'user_id' => $user->id,
            'related_user_id' => $target->id,
            'type' => 'block',
        ]);
        // Follow was also created (different type, so unique constraint allows it)
        $this->assertDatabaseHas('user_relationships', [
            'user_id' => $user->id,
            'related_user_id' => $target->id,
            'type' => 'follow',
        ]);
    });
});

// ── Unblock Action ─────────────────────────────────────

describe('Unblock Action', function () {
    it('removes an existing block', function () {
        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::block($user, $target);
        $result = UserRelationship::unblock($user, $target);

        expect($result)->toBeTrue();
        $this->assertDatabaseMissing('user_relationships', [
            'user_id' => $user->id,
            'related_user_id' => $target->id,
            'type' => 'block',
        ]);
    })->group('smoke');

    it('returns false when not blocked', function () {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $result = UserRelationship::unblock($user, $target);

        expect($result)->toBeFalse();
    });

    it('does not remove follows', function () {
        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::follow($user, $target);
        UserRelationship::unblock($user, $target);

        $this->assertDatabaseHas('user_relationships', [
            'user_id' => $user->id,
            'related_user_id' => $target->id,
            'type' => 'follow',
        ]);
    });

    it('does not remove the reverse block', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        UserRelationship::block($alice, $bob);
        UserRelationship::block($bob, $alice);

        UserRelationship::unblock($alice, $bob);

        // Bob's block on Alice still exists
        $this->assertDatabaseHas('user_relationships', [
            'user_id' => $bob->id,
            'related_user_id' => $alice->id,
            'type' => 'block',
        ]);
    });
});

// ── isFollowing / isFollowedBy ─────────────────────────

describe('isFollowing / isFollowedBy', function () {
    it('detects following relationship', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        UserRelationship::follow($alice, $bob);

        expect($alice->isFollowing($bob))->toBeTrue();
        expect($bob->isFollowing($alice))->toBeFalse();
    });

    it('detects followed-by relationship', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        UserRelationship::follow($alice, $bob);

        expect($bob->isFollowedBy($alice))->toBeTrue();
        expect($alice->isFollowedBy($bob))->toBeFalse();
    });
});

// ── isFriend (mutual follow) ───────────────────────────

describe('isFriend', function () {
    it('returns true for mutual follow (friends)', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        UserRelationship::follow($alice, $bob);
        UserRelationship::follow($bob, $alice);

        expect($alice->isFriend($bob))->toBeTrue();
        expect($bob->isFriend($alice))->toBeTrue();
    })->group('smoke');

    it('returns false for one-way follow', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        UserRelationship::follow($alice, $bob);

        expect($alice->isFriend($bob))->toBeFalse();
    });

    it('returns false when user has blocked target', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        // Create mutual follows, then block (which removes follows)
        UserRelationship::follow($alice, $bob);
        UserRelationship::follow($bob, $alice);
        UserRelationship::block($alice, $bob);

        expect($alice->isFriend($bob))->toBeFalse();
    });

    it('returns false when target has blocked user', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        // Manually insert mutual follows + block without side effects
        UserRelationship::create([
            'user_id' => $alice->id,
            'related_user_id' => $bob->id,
            'type' => RelationshipType::Follow,
        ]);
        UserRelationship::create([
            'user_id' => $bob->id,
            'related_user_id' => $alice->id,
            'type' => RelationshipType::Follow,
        ]);
        UserRelationship::create([
            'user_id' => $bob->id,
            'related_user_id' => $alice->id,
            'type' => RelationshipType::Block,
        ]);

        expect($alice->isFriend($bob))->toBeFalse();
    });

    it('returns true after unblocking and re-following', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        // Become friends
        UserRelationship::follow($alice, $bob);
        UserRelationship::follow($bob, $alice);
        expect($alice->isFriend($bob))->toBeTrue();

        // Block removes follows in both directions
        UserRelationship::block($alice, $bob);
        expect($alice->isFriend($bob))->toBeFalse();

        // Unblock and re-follow both directions to restore friendship
        UserRelationship::unblock($alice, $bob);
        UserRelationship::follow($alice, $bob);
        UserRelationship::follow($bob, $alice);
        expect($alice->isFriend($bob))->toBeTrue();
    });
});

// ── isBlockedBy / hasBlocked ───────────────────────────

describe('isBlockedBy / hasBlocked', function () {
    it('detects blocking direction correctly', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        UserRelationship::block($alice, $bob);

        expect($alice->hasBlocked($bob))->toBeTrue();
        expect($alice->isBlockedBy($bob))->toBeFalse();
        expect($bob->hasBlocked($alice))->toBeFalse();
        expect($bob->isBlockedBy($alice))->toBeTrue();
    });

});

// ── isFriendOrTeammate ─────────────────────────────────

describe('isFriendOrTeammate', function () {
    it('returns true for friends without shared team', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        UserRelationship::follow($alice, $bob);
        UserRelationship::follow($bob, $alice);

        expect($alice->isFriendOrTeammate($bob))->toBeTrue();
    });

    it('returns true for teammates without friendship', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $team = Team::factory()->create();

        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $alice->id,
            'role' => ParticipantRole::Player->value,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $bob->id,
            'role' => ParticipantRole::Player->value,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        expect($alice->isFriendOrTeammate($bob))->toBeTrue();
    });

    it('returns true for friends who are also teammates', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $team = Team::factory()->create();

        UserRelationship::follow($alice, $bob);
        UserRelationship::follow($bob, $alice);

        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $alice->id,
            'role' => ParticipantRole::Player->value,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $bob->id,
            'role' => ParticipantRole::Player->value,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        expect($alice->isFriendOrTeammate($bob))->toBeTrue();
    });

    it('returns false for strangers with no shared team', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        expect($alice->isFriendOrTeammate($bob))->toBeFalse();
    });

    it('ignores inactive team memberships', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $team = Team::factory()->create();

        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $alice->id,
            'role' => ParticipantRole::Player->value,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $bob->id,
            'role' => ParticipantRole::Player->value,
            'status' => 'inactive',
            'joined_at' => now(),
        ]);

        expect($alice->isFriendOrTeammate($bob))->toBeFalse();
    });

    it('ignores memberships on different teams', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $team1 = Team::factory()->create();
        $team2 = Team::factory()->create();

        TeamMember::create([
            'team_id' => $team1->id,
            'user_id' => $alice->id,
            'role' => ParticipantRole::Player->value,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team2->id,
            'user_id' => $bob->id,
            'role' => ParticipantRole::Player->value,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        expect($alice->isFriendOrTeammate($bob))->toBeFalse();
    });

});

// ── getRelationshipLevel ───────────────────────────────

describe('getRelationshipLevel', function () {
    it('returns self for the same user', function () {
        $user = User::factory()->create();

        expect($user->getRelationshipLevel($user))->toBe('self');
    });

    it('returns stranger for users with no relationship', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        expect($alice->getRelationshipLevel($bob))->toBe('stranger');
    });

    it('returns stranger for one-way follow (not mutual)', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        UserRelationship::follow($alice, $bob);

        expect($alice->getRelationshipLevel($bob))->toBe('stranger');
    });

    it('returns friend_or_teammate for mutual follow', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        UserRelationship::follow($alice, $bob);
        UserRelationship::follow($bob, $alice);

        expect($alice->getRelationshipLevel($bob))->toBe('friend_or_teammate');
    });

    it('returns friend_or_teammate for teammates', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $team = Team::factory()->create();

        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $alice->id,
            'role' => ParticipantRole::Player->value,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $bob->id,
            'role' => ParticipantRole::Player->value,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        expect($alice->getRelationshipLevel($bob))->toBe('friend_or_teammate');
    });

    it('returns blocked when user has blocked target', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        UserRelationship::block($alice, $bob);

        expect($alice->getRelationshipLevel($bob))->toBe('blocked');
    });

    it('returns blocked when target has blocked user', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        UserRelationship::block($bob, $alice);

        expect($alice->getRelationshipLevel($bob))->toBe('blocked');
    });

    it('returns blocked overriding mutual friendship', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        // Mutual follow + a block (bypass side effects with direct insert)
        UserRelationship::create([
            'user_id' => $alice->id,
            'related_user_id' => $bob->id,
            'type' => RelationshipType::Follow,
        ]);
        UserRelationship::create([
            'user_id' => $bob->id,
            'related_user_id' => $alice->id,
            'type' => RelationshipType::Follow,
        ]);
        UserRelationship::create([
            'user_id' => $bob->id,
            'related_user_id' => $alice->id,
            'type' => RelationshipType::Block,
        ]);

        expect($alice->getRelationshipLevel($bob))->toBe('blocked');
    });

    it('returns blocked overriding shared team membership', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $team = Team::factory()->create();

        // Teammates
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $alice->id,
            'role' => ParticipantRole::Player->value,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $bob->id,
            'role' => ParticipantRole::Player->value,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        // Block overrides teammate
        UserRelationship::block($alice, $bob);

        expect($alice->getRelationshipLevel($bob))->toBe('blocked');
    });

});

// ── Eloquent Relationship Queries ──────────────────────

describe('Eloquent Relationships', function () {
    it('followers excludes blocks', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();

        UserRelationship::block($other, $user);

        expect($user->followers()->count())->toBe(0);
    });

    it('followings excludes blocks', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();

        UserRelationship::block($user, $other);

        expect($user->followings()->count())->toBe(0);
    });

});

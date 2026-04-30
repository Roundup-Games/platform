<?php

use App\Enums\RelationshipType;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Comprehensive Relationship Tests (Pest)
|--------------------------------------------------------------------------
|
| This test suite provides end-to-end coverage of the relationship system:
|   - UserRelationship model actions (follow, unfollow, block, unblock)
|   - User model resolution helpers (isFollowing, isFriend, etc.)
|   - Factory correctness
|   - Edge cases and cross-concern interactions
|
| Note: T02 and T03 already have PHPUnit-style test files
| (UserRelationshipModelTest, UserRelationshipTest). This file
| consolidates and extends coverage using Pest syntax.
|
*/

// ── Factory ────────────────────────────────────────────

describe('UserRelationshipFactory', function () {
    it('creates a follow relationship by default', function () {
        $rel = UserRelationship::factory()->create();

        expect($rel->type)->toBe(RelationshipType::Follow);
        expect($rel->user_id)->not->toBeNull();
        expect($rel->related_user_id)->not->toBeNull();
    })->group('smoke');

    it('creates a follow relationship with follow state', function () {
        $rel = UserRelationship::factory()->follow()->create();

        expect($rel->type)->toBe(RelationshipType::Follow);
    });

    it('creates a block relationship with block state', function () {
        $rel = UserRelationship::factory()->block()->create();

        expect($rel->type)->toBe(RelationshipType::Block);
    })->group('smoke');

    it('creates relationships between distinct users', function () {
        $rel = UserRelationship::factory()->create();

        expect($rel->user_id)->not->toBe($rel->related_user_id);
    });

    it('allows overriding user IDs', function () {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $rel = UserRelationship::factory()->create([
            'user_id' => $user->id,
            'related_user_id' => $target->id,
        ]);

        expect($rel->user_id)->toBe($user->id);
        expect($rel->related_user_id)->toBe($target->id);
    });
});

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

    it('prevents duplicate follows (idempotent)', function () {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $first = UserRelationship::follow($user, $target);
        $second = UserRelationship::follow($user, $target);

        expect(UserRelationship::count())->toBe(1);
        expect($first->id)->toBe($second->id);
    });

    it('logs the follow action with structured context', function () {
        $user = User::factory()->create();
        $target = User::factory()->create();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) use ($user, $target) {
                return $message === 'user.relationship.follow'
                    && $context['user_id'] === $user->id
                    && $context['target_id'] === $target->id
                    && $context['action'] === 'follow';
            });
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        UserRelationship::follow($user, $target);
    });

    it('allows mutual follows between two users', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        UserRelationship::follow($alice, $bob);
        UserRelationship::follow($bob, $alice);

        expect(UserRelationship::where('type', 'follow')->count())->toBe(2);
        expect($alice->isFollowing($bob))->toBeTrue();
        expect($bob->isFollowing($alice))->toBeTrue();
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

    it('returns false when not following', function () {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $result = UserRelationship::unfollow($user, $target);

        expect($result)->toBeFalse();
    });

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

    it('logs the unfollow action', function () {
        $user = User::factory()->create();
        $target = User::factory()->create();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) use ($user, $target) {
                return $message === 'user.relationship.unfollow'
                    && $context['user_id'] === $user->id
                    && $context['target_id'] === $target->id
                    && $context['action'] === 'unfollow';
            });
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        UserRelationship::unfollow($user, $target);
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

    it('logs the block action', function () {
        $user = User::factory()->create();
        $target = User::factory()->create();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) use ($user, $target) {
                return $message === 'user.relationship.block'
                    && $context['user_id'] === $user->id
                    && $context['target_id'] === $target->id
                    && $context['action'] === 'block';
            });
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        UserRelationship::block($user, $target);
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

    it('returns false when no relationship exists', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        expect($alice->isFollowing($bob))->toBeFalse();
        expect($alice->isFollowedBy($bob))->toBeFalse();
    });

    it('unfollow updates isFollowing to false', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        UserRelationship::follow($alice, $bob);
        expect($alice->isFollowing($bob))->toBeTrue();

        UserRelationship::unfollow($alice, $bob);
        expect($alice->isFollowing($bob))->toBeFalse();
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

    it('returns false when no relationship exists', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

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

    it('returns false when no block exists', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        expect($alice->hasBlocked($bob))->toBeFalse();
        expect($alice->isBlockedBy($bob))->toBeFalse();
    });

    it('both users can block each other simultaneously', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        UserRelationship::block($alice, $bob);
        UserRelationship::block($bob, $alice);

        expect($alice->hasBlocked($bob))->toBeTrue();
        expect($alice->isBlockedBy($bob))->toBeTrue();
        expect($bob->hasBlocked($alice))->toBeTrue();
        expect($bob->isBlockedBy($alice))->toBeTrue();
    });

    it('unblock clears the block direction', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        UserRelationship::block($alice, $bob);
        expect($alice->hasBlocked($bob))->toBeTrue();

        UserRelationship::unblock($alice, $bob);
        expect($alice->hasBlocked($bob))->toBeFalse();
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
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $bob->id,
            'role' => 'player',
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
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $bob->id,
            'role' => 'player',
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
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $bob->id,
            'role' => 'player',
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
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team2->id,
            'user_id' => $bob->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        expect($alice->isFriendOrTeammate($bob))->toBeFalse();
    });

    it('detects teammates across multiple shared teams', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $team1 = Team::factory()->create();
        $team2 = Team::factory()->create();

        TeamMember::create([
            'team_id' => $team1->id,
            'user_id' => $alice->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team2->id,
            'user_id' => $alice->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team1->id,
            'user_id' => $bob->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        expect($alice->isFriendOrTeammate($bob))->toBeTrue();
    });

    it('returns false when one user has no team memberships', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $team = Team::factory()->create();

        // Only Alice is on the team
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $alice->id,
            'role' => 'player',
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
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $bob->id,
            'role' => 'player',
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
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $bob->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        // Block overrides teammate
        UserRelationship::block($alice, $bob);

        expect($alice->getRelationshipLevel($bob))->toBe('blocked');
    });

    it('transitions through levels as relationships change', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        // Start as strangers
        expect($alice->getRelationshipLevel($bob))->toBe('stranger');

        // One-way follow — still stranger
        UserRelationship::follow($alice, $bob);
        expect($alice->getRelationshipLevel($bob))->toBe('stranger');

        // Mutual follow — friends
        UserRelationship::follow($bob, $alice);
        expect($alice->getRelationshipLevel($bob))->toBe('friend_or_teammate');

        // Block — blocked (removes follows)
        UserRelationship::block($alice, $bob);
        expect($alice->getRelationshipLevel($bob))->toBe('blocked');

        // Unblock — stranger again
        UserRelationship::unblock($alice, $bob);
        expect($alice->getRelationshipLevel($bob))->toBe('stranger');
    });
});

// ── Eloquent Relationship Queries ──────────────────────

describe('Eloquent Relationships', function () {
    it('followers returns only incoming follows', function () {
        $user = User::factory()->create();
        $follower1 = User::factory()->create();
        $follower2 = User::factory()->create();

        UserRelationship::follow($follower1, $user);
        UserRelationship::follow($follower2, $user);

        expect($user->followers()->count())->toBe(2);
    });

    it('followings returns only outgoing follows', function () {
        $user = User::factory()->create();
        $target1 = User::factory()->create();
        $target2 = User::factory()->create();

        UserRelationship::follow($user, $target1);
        UserRelationship::follow($user, $target2);

        expect($user->followings()->count())->toBe(2);
    });

    it('blocks returns only outgoing blocks', function () {
        $user = User::factory()->create();
        $target1 = User::factory()->create();
        $target2 = User::factory()->create();

        UserRelationship::block($user, $target1);
        UserRelationship::block($user, $target2);

        expect($user->blocks()->count())->toBe(2);
    });

    it('blockedBy returns only incoming blocks', function () {
        $user = User::factory()->create();
        $blocker1 = User::factory()->create();
        $blocker2 = User::factory()->create();

        UserRelationship::block($blocker1, $user);
        UserRelationship::block($blocker2, $user);

        expect($user->blockedBy()->count())->toBe(2);
    });

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

    it('mixed relationships are counted separately', function () {
        $user = User::factory()->create();
        $followTarget = User::factory()->create();
        $blockTarget = User::factory()->create();
        $follower = User::factory()->create();
        $blocker = User::factory()->create();

        UserRelationship::follow($user, $followTarget);
        UserRelationship::block($user, $blockTarget);
        UserRelationship::follow($follower, $user);
        UserRelationship::block($blocker, $user);

        expect($user->followings()->count())->toBe(1);
        expect($user->blocks()->count())->toBe(1);
        expect($user->followers()->count())->toBe(1);
        expect($user->blockedBy()->count())->toBe(1);
    });
});

// ── Edge Cases ─────────────────────────────────────────

describe('Edge Cases', function () {
    it('self-relationship: user can follow themselves at DB level but it is a valid record', function () {
        $user = User::factory()->create();

        // The unique constraint allows this — (user_id, related_user_id, type)
        // The model does not explicitly prevent self-relationships.
        $rel = UserRelationship::follow($user, $user);

        expect($rel)->not->toBeNull();
        expect($rel->user_id)->toBe($rel->related_user_id);
    });

    it('self-relationship: isFollowing self returns true after self-follow', function () {
        $user = User::factory()->create();

        UserRelationship::follow($user, $user);

        expect($user->isFollowing($user))->toBeTrue();
    });

    it('self-relationship: getRelationshipLevel returns self regardless of self-follow', function () {
        $user = User::factory()->create();

        UserRelationship::follow($user, $user);

        expect($user->getRelationshipLevel($user))->toBe('self');
    });

    it('cascade deletion: deleting user removes their relationships', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        UserRelationship::follow($alice, $bob);
        UserRelationship::follow($bob, $alice);
        UserRelationship::block($alice, $bob);

        $alice->delete();

        // All of alice's relationships should be gone
        $this->assertDatabaseMissing('user_relationships', [
            'user_id' => $alice->id,
        ]);
        $this->assertDatabaseMissing('user_relationships', [
            'related_user_id' => $alice->id,
        ]);
    });

    it('action methods work with freshly created users (no prior state)', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        // Immediately use all action methods
        UserRelationship::follow($alice, $bob);
        expect($alice->isFollowing($bob))->toBeTrue();

        UserRelationship::unfollow($alice, $bob);
        expect($alice->isFollowing($bob))->toBeFalse();

        UserRelationship::block($alice, $bob);
        expect($alice->hasBlocked($bob))->toBeTrue();

        UserRelationship::unblock($alice, $bob);
        expect($alice->hasBlocked($bob))->toBeFalse();
    });

    it('block then unblock then re-follow restores friendship', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        // Friends
        UserRelationship::follow($alice, $bob);
        UserRelationship::follow($bob, $alice);
        expect($alice->isFriend($bob))->toBeTrue();

        // Block removes follows
        UserRelationship::block($alice, $bob);
        expect($alice->isFriend($bob))->toBeFalse();

        // Unblock + re-follow both directions
        UserRelationship::unblock($alice, $bob);
        UserRelationship::follow($alice, $bob);
        UserRelationship::follow($bob, $alice);

        expect($alice->isFriend($bob))->toBeTrue();
        expect($alice->getRelationshipLevel($bob))->toBe('friend_or_teammate');
    });

    it('factory with existing users does not create extra users', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $userCountBefore = User::count();

        UserRelationship::factory()->create([
            'user_id' => $alice->id,
            'related_user_id' => $bob->id,
        ]);

        expect(User::count())->toBe($userCountBefore);
    });
});

<?php

namespace Tests\Feature;

use App\Enums\RelationshipType;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserRelationshipTest extends TestCase
{
    use RefreshDatabase;

    // ── Eloquent Relationships ─────────────────────────

    #[Test]
    public function followers_returns_incoming_follows(): void
    {
        $user = User::factory()->create();
        $follower = User::factory()->create();

        UserRelationship::follow($follower, $user);

        $this->assertEquals(1, $user->followers()->count());
        $this->assertEquals($follower->id, $user->followers()->first()->user_id);
    }

    #[Test]
    public function followings_returns_outgoing_follows(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::follow($user, $target);

        $this->assertEquals(1, $user->followings()->count());
        $this->assertEquals($target->id, $user->followings()->first()->related_user_id);
    }

    #[Test]
    public function blocks_returns_outgoing_blocks(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::block($user, $target);

        $this->assertEquals(1, $user->blocks()->count());
        $this->assertEquals($target->id, $user->blocks()->first()->related_user_id);
    }

    #[Test]
    public function blocked_by_returns_incoming_blocks(): void
    {
        $user = User::factory()->create();
        $blocker = User::factory()->create();

        UserRelationship::block($blocker, $user);

        $this->assertEquals(1, $user->blockedBy()->count());
        $this->assertEquals($blocker->id, $user->blockedBy()->first()->user_id);
    }

    #[Test]
    public function followers_excludes_blocks(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        UserRelationship::block($other, $user);

        $this->assertEquals(0, $user->followers()->count());
    }

    #[Test]
    public function followings_excludes_blocks(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        UserRelationship::block($user, $other);

        $this->assertEquals(0, $user->followings()->count());
    }

    // ── isFollowing ────────────────────────────────────

    #[Test]
    public function is_following_returns_true_when_following(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::follow($user, $target);

        $this->assertTrue($user->isFollowing($target));
    }

    #[Test]
    public function is_following_returns_false_when_not_following(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $this->assertFalse($user->isFollowing($target));
    }

    #[Test]
    public function is_following_returns_false_for_target_following_user(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::follow($target, $user);

        $this->assertFalse($user->isFollowing($target));
    }

    // ── isFollowedBy ───────────────────────────────────

    #[Test]
    public function is_followed_by_returns_true_when_followed(): void
    {
        $user = User::factory()->create();
        $follower = User::factory()->create();

        UserRelationship::follow($follower, $user);

        $this->assertTrue($user->isFollowedBy($follower));
    }

    #[Test]
    public function is_followed_by_returns_false_when_not_followed(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->assertFalse($user->isFollowedBy($other));
    }

    // ── isFriend ───────────────────────────────────────

    #[Test]
    public function is_friend_returns_true_for_mutual_follow(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::follow($user, $target);
        UserRelationship::follow($target, $user);

        $this->assertTrue($user->isFriend($target));
        $this->assertTrue($target->isFriend($user));
    }

    #[Test]
    public function is_friend_returns_false_for_one_way_follow(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::follow($user, $target);

        $this->assertFalse($user->isFriend($target));
    }

    #[Test]
    public function is_friend_returns_false_when_no_relationship(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $this->assertFalse($user->isFriend($target));
    }

    #[Test]
    public function is_friend_returns_false_if_user_has_blocked_target(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        // First create mutual follow (friends), then block (which removes follows)
        UserRelationship::follow($user, $target);
        UserRelationship::follow($target, $user);
        UserRelationship::block($user, $target);

        $this->assertFalse($user->isFriend($target));
    }

    #[Test]
    public function is_friend_returns_false_if_target_has_blocked_user(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        // Create mutual follow first via direct insert since block removes follows
        UserRelationship::create([
            'user_id' => $user->id,
            'related_user_id' => $target->id,
            'type' => RelationshipType::Follow,
        ]);
        UserRelationship::create([
            'user_id' => $target->id,
            'related_user_id' => $user->id,
            'type' => RelationshipType::Follow,
        ]);
        // Block without side effects to test the isFriend guard
        UserRelationship::create([
            'user_id' => $target->id,
            'related_user_id' => $user->id,
            'type' => RelationshipType::Block,
        ]);

        $this->assertFalse($user->isFriend($target));
    }

    // ── isBlockedBy / hasBlocked ────────────────────────

    #[Test]
    public function is_blocked_by_returns_true_when_blocked(): void
    {
        $user = User::factory()->create();
        $blocker = User::factory()->create();

        UserRelationship::block($blocker, $user);

        $this->assertTrue($user->isBlockedBy($blocker));
    }

    #[Test]
    public function is_blocked_by_returns_false_when_not_blocked(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->assertFalse($user->isBlockedBy($other));
    }

    #[Test]
    public function has_blocked_returns_true_when_user_blocked_target(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::block($user, $target);

        $this->assertTrue($user->hasBlocked($target));
    }

    #[Test]
    public function has_blocked_returns_false_when_user_did_not_block(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $this->assertFalse($user->hasBlocked($target));
    }

    #[Test]
    public function blocking_is_directional(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::block($user, $target);

        $this->assertTrue($user->hasBlocked($target));
        $this->assertFalse($user->isBlockedBy($target));
        $this->assertTrue($target->isBlockedBy($user));
        $this->assertFalse($target->hasBlocked($user));
    }

    // ── isFriendOrTeammate ──────────────────────────────

    #[Test]
    public function is_friend_or_teammate_returns_true_for_friends(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::follow($user, $target);
        UserRelationship::follow($target, $user);

        $this->assertTrue($user->isFriendOrTeammate($target));
    }

    #[Test]
    public function is_friend_or_teammate_returns_true_for_teammates(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        $team = Team::factory()->create();

        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $target->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->assertTrue($user->isFriendOrTeammate($target));
    }

    #[Test]
    public function is_friend_or_teammate_returns_false_for_strangers(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $this->assertFalse($user->isFriendOrTeammate($target));
    }

    #[Test]
    public function is_friend_or_teammate_ignores_inactive_team_memberships(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        $team = Team::factory()->create();

        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $target->id,
            'role' => 'player',
            'status' => 'inactive',
            'joined_at' => now(),
        ]);

        $this->assertFalse($user->isFriendOrTeammate($target));
    }

    #[Test]
    public function is_friend_or_teammate_ignores_different_teams(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        $team1 = Team::factory()->create();
        $team2 = Team::factory()->create();

        TeamMember::create([
            'team_id' => $team1->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team2->id,
            'user_id' => $target->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->assertFalse($user->isFriendOrTeammate($target));
    }

    // ── getRelationshipLevel ────────────────────────────

    #[Test]
    public function get_relationship_level_returns_self(): void
    {
        $user = User::factory()->create();

        $this->assertEquals('self', $user->getRelationshipLevel($user));
    }

    #[Test]
    public function get_relationship_level_returns_friend_or_teammate_for_friends(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::follow($user, $target);
        UserRelationship::follow($target, $user);

        $this->assertEquals('friend_or_teammate', $user->getRelationshipLevel($target));
    }

    #[Test]
    public function get_relationship_level_returns_friend_or_teammate_for_teammates(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        $team = Team::factory()->create();

        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $target->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->assertEquals('friend_or_teammate', $user->getRelationshipLevel($target));
    }

    #[Test]
    public function get_relationship_level_returns_stranger(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $this->assertEquals('stranger', $user->getRelationshipLevel($target));
    }

    #[Test]
    public function get_relationship_level_returns_stranger_for_one_way_follow(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::follow($user, $target);

        $this->assertEquals('stranger', $user->getRelationshipLevel($target));
    }

    #[Test]
    public function get_relationship_level_returns_blocked_when_user_blocked_target(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::block($user, $target);

        $this->assertEquals('blocked', $user->getRelationshipLevel($target));
    }

    #[Test]
    public function get_relationship_level_returns_blocked_when_target_blocked_user(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::block($target, $user);

        $this->assertEquals('blocked', $user->getRelationshipLevel($target));
    }

    #[Test]
    public function get_relationship_level_returns_blocked_overriding_friend(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        // Create mutual follows
        UserRelationship::create([
            'user_id' => $user->id,
            'related_user_id' => $target->id,
            'type' => RelationshipType::Follow,
        ]);
        UserRelationship::create([
            'user_id' => $target->id,
            'related_user_id' => $user->id,
            'type' => RelationshipType::Follow,
        ]);
        // Add block in one direction (without side effects)
        UserRelationship::create([
            'user_id' => $target->id,
            'related_user_id' => $user->id,
            'type' => RelationshipType::Block,
        ]);

        $this->assertEquals('blocked', $user->getRelationshipLevel($target));
    }
}

<?php

namespace Tests\Feature;

use App\Enums\RelationshipType;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserRelationshipModelTest extends TestCase
{
    use DatabaseTransactions;

    // ── Model Basics ───────────────────────────────────

    #[Test]
    public function model_has_correct_fillable(): void
    {
        $model = new UserRelationship;

        $this->assertEquals(
            ['id', 'user_id', 'related_user_id', 'type'],
            $model->getFillable(),
        );
    }

    #[Test]
    public function model_casts_type_to_relationship_type_enum(): void
    {
        $user = User::factory()->create();
        $related = User::factory()->create();

        $rel = UserRelationship::create([
            'user_id' => $user->id,
            'related_user_id' => $related->id,
            'type' => RelationshipType::Follow,
        ]);

        $this->assertInstanceOf(RelationshipType::class, $rel->type);
        $this->assertEquals(RelationshipType::Follow, $rel->type);
    }

    #[Test]
    public function model_uses_timestamps(): void
    {
        $user = User::factory()->create();
        $related = User::factory()->create();

        $rel = UserRelationship::create([
            'user_id' => $user->id,
            'related_user_id' => $related->id,
            'type' => RelationshipType::Follow,
        ]);

        $this->assertNotNull($rel->created_at);
        $this->assertNotNull($rel->updated_at);
    }

    // ── Relationships ──────────────────────────────────

    #[Test]
    public function user_relationship_returns_initiator(): void
    {
        $user = User::factory()->create();
        $related = User::factory()->create();

        $rel = UserRelationship::create([
            'user_id' => $user->id,
            'related_user_id' => $related->id,
            'type' => RelationshipType::Follow,
        ]);

        $this->assertTrue($rel->user->is($user));
    }

    #[Test]
    public function related_relationship_returns_target(): void
    {
        $user = User::factory()->create();
        $related = User::factory()->create();

        $rel = UserRelationship::create([
            'user_id' => $user->id,
            'related_user_id' => $related->id,
            'type' => RelationshipType::Follow,
        ]);

        $this->assertTrue($rel->related->is($related));
    }

    // ── Follow Action ──────────────────────────────────

    #[Test]
    public function follow_creates_relationship(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $rel = UserRelationship::follow($user, $target);

        $this->assertDatabaseHas('user_relationships', [
            'user_id' => $user->id,
            'related_user_id' => $target->id,
            'type' => 'follow',
        ]);
        $this->assertEquals(RelationshipType::Follow, $rel->type);
    }

    #[Test]
    public function follow_is_idempotent(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $first = UserRelationship::follow($user, $target);
        $second = UserRelationship::follow($user, $target);

        $this->assertEquals(1, UserRelationship::count());
        $this->assertEquals($first->id, $second->id);
    }

    #[Test]
    public function follow_logs_action(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'user.relationship.follow'
                    && $context['action'] === 'follow'
                    && isset($context['user_id'])
                    && isset($context['target_id']);
            });

        // Allow notification dispatch and cache invalidation logs
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::follow($user, $target);
    }

    // ── Unfollow Action ────────────────────────────────

    #[Test]
    public function unfollow_removes_follow_relationship(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::follow($user, $target);
        $result = UserRelationship::unfollow($user, $target);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('user_relationships', [
            'user_id' => $user->id,
            'related_user_id' => $target->id,
            'type' => 'follow',
        ]);
    }

    #[Test]
    public function unfollow_returns_false_when_not_following(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $result = UserRelationship::unfollow($user, $target);

        $this->assertFalse($result);
    }

    #[Test]
    public function unfollow_does_not_remove_block(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::block($user, $target);
        UserRelationship::unfollow($user, $target);

        $this->assertDatabaseHas('user_relationships', [
            'user_id' => $user->id,
            'related_user_id' => $target->id,
            'type' => 'block',
        ]);
    }

    // ── Block Action ───────────────────────────────────

    #[Test]
    public function block_creates_block_relationship(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $rel = UserRelationship::block($user, $target);

        $this->assertDatabaseHas('user_relationships', [
            'user_id' => $user->id,
            'related_user_id' => $target->id,
            'type' => 'block',
        ]);
        $this->assertEquals(RelationshipType::Block, $rel->type);
    }

    #[Test]
    public function block_removes_follow_from_initiator_to_target(): void
    {
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
    }

    #[Test]
    public function block_removes_follow_from_target_to_initiator(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        // Target follows user
        UserRelationship::follow($target, $user);

        // User blocks target — should remove target's follow
        UserRelationship::block($user, $target);

        $this->assertDatabaseMissing('user_relationships', [
            'user_id' => $target->id,
            'related_user_id' => $user->id,
            'type' => 'follow',
        ]);
    }

    #[Test]
    public function block_removes_follows_in_both_directions(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        // Mutual follows
        UserRelationship::follow($user, $target);
        UserRelationship::follow($target, $user);

        UserRelationship::block($user, $target);

        // Both follow directions should be removed
        $this->assertEquals(0, UserRelationship::where('type', 'follow')->count());
        // Block should exist
        $this->assertEquals(1, UserRelationship::where('type', 'block')->count());
    }

    #[Test]
    public function block_is_idempotent(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $first = UserRelationship::block($user, $target);
        $second = UserRelationship::block($user, $target);

        $this->assertEquals(1, UserRelationship::where('type', 'block')->count());
        $this->assertEquals($first->id, $second->id);
    }

    #[Test]
    public function block_logs_action(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'user.relationship.block'
                    && $context['action'] === 'block'
                    && isset($context['user_id'])
                    && isset($context['target_id']);
            });

        // Allow notification dispatch and cache invalidation logs
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::block($user, $target);
    }

    // ── Unblock Action ─────────────────────────────────

    #[Test]
    public function unblock_removes_block_relationship(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::block($user, $target);
        $result = UserRelationship::unblock($user, $target);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('user_relationships', [
            'user_id' => $user->id,
            'related_user_id' => $target->id,
            'type' => 'block',
        ]);
    }

    #[Test]
    public function unblock_returns_false_when_not_blocked(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $result = UserRelationship::unblock($user, $target);

        $this->assertFalse($result);
    }

    #[Test]
    public function unblock_does_not_remove_follow(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::follow($user, $target);
        UserRelationship::unblock($user, $target);

        $this->assertDatabaseHas('user_relationships', [
            'user_id' => $user->id,
            'related_user_id' => $target->id,
            'type' => 'follow',
        ]);
    }

    // ── Edge Cases ─────────────────────────────────────

    #[Test]
    public function user_can_follow_and_block_different_users(): void
    {
        $user = User::factory()->create();
        $target1 = User::factory()->create();
        $target2 = User::factory()->create();

        UserRelationship::follow($user, $target1);
        UserRelationship::block($user, $target2);

        $this->assertEquals(2, UserRelationship::count());
        $this->assertEquals(1, UserRelationship::where('type', 'follow')->count());
        $this->assertEquals(1, UserRelationship::where('type', 'block')->count());
    }

    #[Test]
    public function two_users_can_follow_each_other(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::follow($user, $target);
        UserRelationship::follow($target, $user);

        $this->assertEquals(2, UserRelationship::where('type', 'follow')->count());
    }
}

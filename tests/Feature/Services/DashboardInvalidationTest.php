<?php

namespace Tests\Feature\Services;

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Dto\ActionItem;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GMProfile;
use App\Models\Review;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\DashboardCacheService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests that action center cache is correctly invalidated when underlying
 * data changes: participant status, game events, reviews, follows, attendance.
 */
class DashboardInvalidationTest extends TestCase
{
    use DatabaseTransactions;

    private DashboardCacheService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DashboardCacheService::class);
        Cache::flush();
        Queue::fake();
        Log::spy();
    }

    // ── computeActionCenter delegates to ActionCenterService ────

    #[Test]
    public function compute_action_center_returns_serialized_action_items(): void
    {
        $user = User::factory()->create();

        // Create a game the user owns with a pending application
        $game = Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Scheduled,
        ]);
        $applicant = User::factory()->create();
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $applicant->id,
            'status' => ParticipantStatus::Pending,
        ]);

        $result = $this->service->getActionCenter($user);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);

        // Each item should be an array with ActionItem fields
        $item = $result[0];
        $this->assertArrayHasKey('type', $item);
        $this->assertArrayHasKey('priority', $item);
        $this->assertArrayHasKey('title', $item);
        $this->assertArrayHasKey('action_url', $item);
        $this->assertEquals('pending_applications', $item['type']);
    }

    #[Test]
    public function action_center_items_are_serialized_for_cache(): void
    {
        $user = User::factory()->create();

        $result = $this->service->getActionCenter($user);

        // Verify it was cached
        $cached = Cache::get("dashboard:action_center:{$user->id}");
        $this->assertNotNull($cached);
        $this->assertEquals($result, $cached);

        // Verify cached data can round-trip through ActionItem::fromArray
        foreach ($cached as $itemArray) {
            $restored = ActionItem::fromArray($itemArray);
            $this->assertInstanceOf(ActionItem::class, $restored);
            $this->assertEquals($itemArray['type'], $restored->type);
            $this->assertEquals($itemArray['priority'], $restored->priority);
        }
    }

    #[Test]
    public function action_center_returns_cached_data_on_second_call(): void
    {
        $user = User::factory()->create();

        $this->service->getActionCenter($user);

        // Overwrite cache with sentinel to verify second call reads from cache
        $sentinel = [['type' => '__sentinel__', 'priority' => 'test']];
        Cache::put("dashboard:action_center:{$user->id}", $sentinel, 3600);

        $second = $this->service->getActionCenter($user);

        $this->assertEquals($sentinel, $second);
    }

    // ── invalidateActionCenterForParticipantChange ──────────────

    #[Test]
    public function participant_change_invalidates_user_and_owner_action_center(): void
    {
        $owner = User::factory()->create();
        $player = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'status' => GameStatus::Scheduled,
        ]);

        // Populate both action center caches
        Cache::put("dashboard:action_center:{$player->id}", ['old_data'], 300);
        Cache::put("dashboard:action_center:{$owner->id}", ['old_data'], 300);

        $this->service->invalidateActionCenterForParticipantChange(
            (string) $player->id,
            (string) $game->id,
        );

        $this->assertNull(Cache::get("dashboard:action_center:{$player->id}"));
        $this->assertNull(Cache::get("dashboard:action_center:{$owner->id}"));
    }

    #[Test]
    public function participant_change_without_game_only_invalidates_user(): void
    {
        $user = User::factory()->create();
        Cache::put("dashboard:action_center:{$user->id}", ['old_data'], 300);

        $this->service->invalidateActionCenterForParticipantChange((string) $user->id);

        $this->assertNull(Cache::get("dashboard:action_center:{$user->id}"));
    }

    // ── invalidateActionCenterForGameEvent ──────────────────────

    #[Test]
    public function game_event_invalidates_owner_and_participants_action_center(): void
    {
        $owner = User::factory()->create();
        $player = User::factory()->create();
        $waitlisted = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'status' => GameStatus::Scheduled,
        ]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'status' => ParticipantStatus::Approved,
        ]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $waitlisted->id,
            'status' => ParticipantStatus::Waitlisted,
        ]);

        // Populate caches
        Cache::put("dashboard:action_center:{$owner->id}", ['old'], 300);
        Cache::put("dashboard:action_center:{$player->id}", ['old'], 300);
        Cache::put("dashboard:action_center:{$waitlisted->id}", ['old'], 300);

        $this->service->invalidateActionCenterForGameEvent((string) $game->id);

        $this->assertNull(Cache::get("dashboard:action_center:{$owner->id}"));
        $this->assertNull(Cache::get("dashboard:action_center:{$player->id}"));
        $this->assertNull(Cache::get("dashboard:action_center:{$waitlisted->id}"));
    }

    #[Test]
    public function game_event_skips_rejected_participants(): void
    {
        $owner = User::factory()->create();
        $rejected = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'status' => GameStatus::Scheduled,
        ]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $rejected->id,
            'status' => ParticipantStatus::Rejected,
        ]);

        Cache::put("dashboard:action_center:{$rejected->id}", ['should_stay'], 300);

        $this->service->invalidateActionCenterForGameEvent((string) $game->id);

        // Rejected participant's cache should NOT be cleared
        $this->assertNotNull(Cache::get("dashboard:action_center:{$rejected->id}"));
    }

    // ── invalidateActionCenterForReview ─────────────────────────

    #[Test]
    public function review_invalidation_clears_action_center_for_reviewed_user(): void
    {
        $user = User::factory()->create();
        Cache::put("dashboard:action_center:{$user->id}", ['old'], 300);

        $this->service->invalidateActionCenterForReview((string) $user->id);

        $this->assertNull(Cache::get("dashboard:action_center:{$user->id}"));
    }

    // ── invalidateActionCenterForFollow ─────────────────────────

    #[Test]
    public function follow_invalidation_clears_action_center_for_followed_user(): void
    {
        $user = User::factory()->create();
        Cache::put("dashboard:action_center:{$user->id}", ['old'], 300);

        $this->service->invalidateActionCenterForFollow((string) $user->id);

        $this->assertNull(Cache::get("dashboard:action_center:{$user->id}"));
    }

    // ── invalidateActionCenterForAttendance ─────────────────────

    #[Test]
    public function attendance_invalidation_clears_action_center_for_user(): void
    {
        $user = User::factory()->create();
        Cache::put("dashboard:action_center:{$user->id}", ['old'], 300);

        $this->service->invalidateActionCenterForAttendance((string) $user->id);

        $this->assertNull(Cache::get("dashboard:action_center:{$user->id}"));
    }

    // ── Observer wiring: GameParticipantObserver ────────────────

    #[Test]
    public function game_participant_created_invalidates_action_center(): void
    {
        $owner = User::factory()->create();
        $player = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'status' => GameStatus::Scheduled,
        ]);

        // Populate caches
        Cache::put("dashboard:action_center:{$player->id}", ['old'], 300);
        Cache::put("dashboard:action_center:{$owner->id}", ['old'], 300);

        // Creating a participant triggers the observer
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'status' => ParticipantStatus::Pending,
        ]);

        // Both player and owner action center should be invalidated
        $this->assertNull(Cache::get("dashboard:action_center:{$player->id}"));
        $this->assertNull(Cache::get("dashboard:action_center:{$owner->id}"));
    }

    #[Test]
    public function game_participant_status_change_invalidates_action_center(): void
    {
        $owner = User::factory()->create();
        $player = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'status' => GameStatus::Scheduled,
        ]);
        $participant = GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'status' => ParticipantStatus::Pending,
        ]);

        // Populate caches after creation
        Cache::put("dashboard:action_center:{$player->id}", ['old'], 300);
        Cache::put("dashboard:action_center:{$owner->id}", ['old'], 300);

        // Change status
        $participant->update(['status' => ParticipantStatus::Approved]);

        $this->assertNull(Cache::get("dashboard:action_center:{$player->id}"));
        $this->assertNull(Cache::get("dashboard:action_center:{$owner->id}"));
    }

    #[Test]
    public function game_participant_attendance_report_invalidates_action_center(): void
    {
        $player = User::factory()->create();
        $game = Game::factory()->create(['status' => GameStatus::Completed]);
        $participant = GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'status' => ParticipantStatus::Approved,
            'attendance_status' => null,
        ]);

        // Populate caches after the created observer has already fired
        Cache::flush();
        Cache::put("dashboard:action_center:{$player->id}", ['old'], 300);

        $participant->attendance_status = \App\Enums\AttendanceStatus::Attended;
        $participant->save();

        $this->assertNull(Cache::get("dashboard:action_center:{$player->id}"));
    }

    // ── Observer wiring: GameObserver ───────────────────────────

    #[Test]
    public function game_saved_invalidates_action_center(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'status' => GameStatus::Scheduled,
        ]);

        Cache::put("dashboard:action_center:{$owner->id}", ['old'], 300);

        $game->update(['name' => 'Updated Game Name']);

        $this->assertNull(Cache::get("dashboard:action_center:{$owner->id}"));
    }

    // ── Observer wiring: ReviewObserver ─────────────────────────

    #[Test]
    public function review_created_invalidates_action_center_for_gm(): void
    {
        $gm = User::factory()->create();
        $gmProfile = GMProfile::factory()->create(['user_id' => $gm->id]);
        $reviewer = User::factory()->create();

        Cache::put("dashboard:action_center:{$gm->id}", ['old'], 300);

        Review::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'reviewer_id' => $reviewer->id,
            'rating' => 5,
        ]);

        $this->assertNull(Cache::get("dashboard:action_center:{$gm->id}"));
    }

    // ── Observer wiring: UserRelationshipObserver ───────────────

    #[Test]
    public function follow_created_invalidates_action_center_for_followed_user(): void
    {
        $followed = User::factory()->create();
        $follower = User::factory()->create();

        Cache::put("dashboard:action_center:{$followed->id}", ['old'], 300);

        UserRelationship::follow($follower, $followed);

        $this->assertNull(Cache::get("dashboard:action_center:{$followed->id}"));
    }

    #[Test]
    public function non_follow_relationship_does_not_invalidate_action_center(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Cache::put("dashboard:action_center:{$user->id}", ['should_stay'], 300);

        // Create a block relationship — should NOT trigger action center invalidation
        UserRelationship::block($other, $user);

        $this->assertNotNull(Cache::get("dashboard:action_center:{$user->id}"));
    }
}

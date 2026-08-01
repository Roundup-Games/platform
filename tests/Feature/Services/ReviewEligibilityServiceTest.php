<?php

namespace Tests\Feature\Services;

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\VenueType;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Location;
use App\Models\Review;
use App\Models\User;
use App\Services\ReviewEligibilityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/*
 * Surviving slice of ReviewEligibilityServiceTest. The common eligibility
 * scenarios (approved/pending/rejected/non-participant/future/already-reviewed
 * for session + campaign) are duplicated by tests/Feature/Policies/ReviewPolicyTest.php,
 * which exercises the service directly inside its `describe('ReviewEligibilityService', ...)` block.
 *
 * What remains here are the venue-specific and owner-specific edge cases that
 * the policy file does NOT yet cover. They should be migrated into the policy
 * file's `describe('canReviewVenue', ...)` / `describe('canReviewSession')`
 * blocks by the Policies/ cluster agent.
 */
class ReviewEligibilityServiceTest extends TestCase
{
    use DatabaseTransactions;

    private ReviewEligibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReviewEligibilityService::class);
    }

    // ── canReviewVenue — edge cases not in ReviewPolicyTest ───────────

    /** Venue eligible: approved campaign participant at the venue WITH a past session → true */
    public function test_can_review_venue_approved_campaign_participant_with_past_session(): void
    {
        $gm = User::factory()->create();
        $user = User::factory()->create();
        $venue = $this->verifiedVenue();
        $campaign = Campaign::factory()->create([
            'owner_id' => $gm->id,
            'location_id' => $venue->id,
        ]);
        Game::factory()->create([
            'owner_id' => $gm->id,
            'campaign_id' => $campaign->id,
            'date_time' => now()->subDay(),
        ]);
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved,
        ]);

        $this->assertTrue($this->service->canReviewVenue($user, $venue));
    }

    /** Venue ineligible: approved participant of a game at a DIFFERENT location → false */
    public function test_can_review_venue_participant_at_different_location(): void
    {
        $gm = User::factory()->create();
        $user = User::factory()->create();
        $venue = $this->verifiedVenue();
        $otherVenue = $this->verifiedVenue();
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'location_id' => $otherVenue->id,
            'date_time' => now()->subDay(),
        ]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $this->assertFalse($this->service->canReviewVenue($user, $venue));
    }

    /** Venue ineligible: game at the venue but date_time is in the future → false */
    public function test_can_review_venue_future_game(): void
    {
        $gm = User::factory()->create();
        $user = User::factory()->create();
        $venue = $this->verifiedVenue();
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'location_id' => $venue->id,
            'date_time' => now()->addDay(),
        ]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $this->assertFalse($this->service->canReviewVenue($user, $venue));
    }

    /** Venue ineligible: user already reviewed this venue → false */
    public function test_can_review_venue_already_reviewed(): void
    {
        $gm = User::factory()->create();
        $user = User::factory()->create();
        $venue = $this->verifiedVenue();
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'location_id' => $venue->id,
            'date_time' => now()->subDay(),
        ]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Approved,
        ]);
        Review::factory()->venue()->create([
            'reviewable_id' => $venue->id,
            'reviewer_id' => $user->id,
        ]);

        $this->assertFalse($this->service->canReviewVenue($user, $venue));
    }

    /** Venue gate: UNVERIFIED commercial location → false (isPublicVenuePage authority / MEM717) */
    public function test_can_review_venue_unverified_location_rejected_by_gate(): void
    {
        $gm = User::factory()->create();
        $user = User::factory()->create();
        // Commercial type but unverified — fails the MEM717 gate.
        $location = Location::factory()->verifiedVenue()->create([
            'is_verified' => false,
            'venue_type' => VenueType::Cafe,
            'slug' => fake()->unique()->slug(),
        ]);
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'location_id' => $location->id,
            'date_time' => now()->subDay(),
        ]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $this->assertFalse($this->service->canReviewVenue($user, $location));
    }

    /** Venue gate: verified but 'other' (non-commercial / private) venue_type → false (MEM717) */
    public function test_can_review_venue_other_venue_type_rejected_by_gate(): void
    {
        $gm = User::factory()->create();
        $user = User::factory()->create();
        // Verified but 'other' — the doxxing-risk case MEM717 blocks.
        $location = Location::factory()->verifiedVenue()->create([
            'venue_type' => VenueType::Other,
        ]);
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'location_id' => $location->id,
            'date_time' => now()->subDay(),
        ]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $this->assertFalse($this->service->canReviewVenue($user, $location));
    }

    // ── Owner-edge cases not in ReviewPolicyTest ──────────────────────

    /** Scenario: game owner who is also an approved participant can review */
    public function test_can_review_session_owner_is_approved_participant(): void
    {
        $owner = User::factory()->create();
        $gm = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'date_time' => now()->subDay(),
        ]);
        // Owner plays in their own game as a participant
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $this->assertTrue($this->service->canReviewSession($owner, $game));
    }

    /** Scenario: game owner who is NOT a participant cannot review */
    public function test_can_review_session_owner_not_participant(): void
    {
        $owner = User::factory()->create();
        $gm = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'date_time' => now()->subDay(),
        ]);

        $this->assertFalse($this->service->canReviewSession($owner, $game));
    }

    /** Scenario: campaign owner should NOT be able to review own campaign */
    public function test_can_review_campaign_owner_cannot_review_own(): void
    {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'status' => 'active',
        ]);
        CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
            'user_id' => $owner->id,
            'status' => ParticipantStatus::Approved,
        ]);

        Game::factory()->create([
            'campaign_id' => $campaign->id,
            'owner_id' => $owner->id,
            'date_time' => now()->subDay(),
        ]);

        $this->assertFalse($this->service->canReviewCampaign($owner, $campaign));
    }

    /** Scenario: game host/owner should NOT be able to review own game session */
    public function test_can_review_session_host_cannot_review_own(): void
    {
        $host = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->subDay(),
        ]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $host->id,
            'status' => ParticipantStatus::Approved,
            'role' => ParticipantRole::Owner,
        ]);

        $this->assertFalse($this->service->canReviewSession($host, $game));
    }

    /**
     * Create a verified commercial venue — the only kind that is reviewable
     * (MEM717). verifiedVenue() randomises venue_type, so force a commercial
     * type (Cafe) and give it a unique slug so it mirrors a real venue page.
     */
    private function verifiedVenue(): Location
    {
        return Location::factory()->verifiedVenue()->create([
            'venue_type' => VenueType::Cafe,
            'slug' => fake()->unique()->slug(),
        ]);
    }
}

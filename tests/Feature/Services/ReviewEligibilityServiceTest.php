<?php

namespace Tests\Feature\Services;

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\VenueType;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GMProfile;
use App\Models\Location;
use App\Models\Review;
use App\Models\User;
use App\Services\ReviewEligibilityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReviewEligibilityServiceTest extends TestCase
{
    use DatabaseTransactions;

    private ReviewEligibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReviewEligibilityService::class);
    }

    // ── canReviewSession ───────────────────────────────

    /** Scenario 1: approved participant of past game, no existing review → true */
    public function test_can_review_session_eligible(): void
    {
        $gm = User::factory()->create();
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'date_time' => now()->subDay(),
        ]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $this->assertTrue($this->service->canReviewSession($user, $game));
    }

    /** Scenario 2: user is not a participant → false */
    public function test_can_review_session_not_participant(): void
    {
        $gm = User::factory()->create();
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'date_time' => now()->subDay(),
        ]);

        $this->assertFalse($this->service->canReviewSession($user, $game));
    }

    /** Scenario 3: game date_time is in the future → false */
    public function test_can_review_session_future_game(): void
    {
        $gm = User::factory()->create();
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'date_time' => now()->addDay(),
        ]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $this->assertFalse($this->service->canReviewSession($user, $game));
    }

    /** Scenario 4: user already has a review for this game → false */
    public function test_can_review_session_already_reviewed(): void
    {
        $gm = User::factory()->create();
        $gmProfile = GMProfile::factory()->create(['user_id' => $gm->id]);
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'date_time' => now()->subDay(),
        ]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Approved,
        ]);
        Review::factory()->create([
            'reviewable_type' => Game::class,
            'reviewable_id' => $game->id,
            'reviewer_id' => $user->id,
            'gm_profile_id' => $gmProfile->id,
        ]);

        $this->assertFalse($this->service->canReviewSession($user, $game));
    }

    /** Scenario 5: user has pending (not approved) status → false */
    public function test_can_review_session_pending_participant(): void
    {
        $gm = User::factory()->create();
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'date_time' => now()->subDay(),
        ]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Pending,
        ]);

        $this->assertFalse($this->service->canReviewSession($user, $game));
    }

    // ── canReviewCampaign ──────────────────────────────

    /** Scenario 6: approved participant, campaign has completed session, no review → true */
    public function test_can_review_campaign_eligible(): void
    {
        $gm = User::factory()->create();
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $gm->id]);
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

        $this->assertTrue($this->service->canReviewCampaign($user, $campaign));
    }

    /** Scenario 7: all campaign games are in the future → false */
    public function test_can_review_campaign_no_completed_session(): void
    {
        $gm = User::factory()->create();
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $gm->id]);
        Game::factory()->create([
            'owner_id' => $gm->id,
            'campaign_id' => $campaign->id,
            'date_time' => now()->addDay(),
        ]);
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved,
        ]);

        $this->assertFalse($this->service->canReviewCampaign($user, $campaign));
    }

    /** Scenario 8: user is not a campaign participant → false */
    public function test_can_review_campaign_not_participant(): void
    {
        $gm = User::factory()->create();
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $gm->id]);
        Game::factory()->create([
            'owner_id' => $gm->id,
            'campaign_id' => $campaign->id,
            'date_time' => now()->subDay(),
        ]);

        $this->assertFalse($this->service->canReviewCampaign($user, $campaign));
    }

    /** Scenario 9: user already reviewed this campaign → false */
    public function test_can_review_campaign_already_reviewed(): void
    {
        $gm = User::factory()->create();
        $gmProfile = GMProfile::factory()->create(['user_id' => $gm->id]);
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $gm->id]);
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
        Review::factory()->create([
            'reviewable_type' => Campaign::class,
            'reviewable_id' => $campaign->id,
            'reviewer_id' => $user->id,
            'gm_profile_id' => $gmProfile->id,
        ]);

        $this->assertFalse($this->service->canReviewCampaign($user, $campaign));
    }

    // ── canReviewVenue ─────────────────────────────────

    /** Venue eligible: approved participant of a COMPLETED game at the venue → true */
    public function test_can_review_venue_approved_completed_game_participant(): void
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

        $this->assertTrue($this->service->canReviewVenue($user, $venue));
    }

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

    /** Venue ineligible: non-participant → false */
    public function test_can_review_venue_non_participant(): void
    {
        $user = User::factory()->create();
        $venue = $this->verifiedVenue();

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

    /** Venue gate: UNVERIFIED commercial location → false (isPublicVenuePage authority) */
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

    // ── getEligibleReviews ─────────────────────────────

    /** Scenario 10: 3 completed games (reviewed 1) + 2 campaigns (reviewed 0) → 2 game + 2 campaign entries */
    public function test_get_eligible_reviews_mixed(): void
    {
        $gm = User::factory()->create();
        $gmProfile = GMProfile::factory()->create(['user_id' => $gm->id]);
        $user = User::factory()->create();

        // 3 completed games — user is approved participant in all
        $games = collect();
        for ($i = 0; $i < 3; $i++) {
            $games->push(Game::factory()->create([
                'owner_id' => $gm->id,
                'date_time' => now()->subDays($i + 1),
            ]));
        }
        foreach ($games as $game) {
            GameParticipant::factory()->create([
                'game_id' => $game->id,
                'user_id' => $user->id,
                'status' => ParticipantStatus::Approved,
            ]);
        }

        // Review one of the games
        Review::factory()->create([
            'reviewable_type' => Game::class,
            'reviewable_id' => $games[0]->id,
            'reviewer_id' => $user->id,
            'gm_profile_id' => $gmProfile->id,
        ]);

        // 2 campaigns with completed sessions — user is approved participant in both
        $campaigns = collect();
        for ($i = 0; $i < 2; $i++) {
            $campaigns->push(Campaign::factory()->create(['owner_id' => $gm->id]));
        }
        foreach ($campaigns as $campaign) {
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
        }

        $eligible = $this->service->getEligibleReviews($user);

        // 2 unreviewed games + 2 campaigns = 4 total
        $this->assertCount(4, $eligible);

        $gameEntries = $eligible->where('reviewable_type', Game::class);
        $campaignEntries = $eligible->where('reviewable_type', Campaign::class);
        $this->assertCount(2, $gameEntries);
        $this->assertCount(2, $campaignEntries);

        // Verify the reviewed game is excluded
        $reviewedGameIds = $gameEntries->pluck('reviewable_id');
        $this->assertNotContains($games[0]->id, $reviewedGameIds);
        $this->assertContains($games[1]->id, $reviewedGameIds);
        $this->assertContains($games[2]->id, $reviewedGameIds);

        // Each entry has the expected structure
        foreach ($eligible as $entry) {
            $this->assertArrayHasKey('reviewable_type', $entry);
            $this->assertArrayHasKey('reviewable_id', $entry);
            $this->assertArrayHasKey('reviewable', $entry);
        }
    }

    /** Scenario 11: user has no participations → empty collection */
    public function test_get_eligible_reviews_empty(): void
    {
        $user = User::factory()->create();

        $eligible = $this->service->getEligibleReviews($user);

        $this->assertCount(0, $eligible);
        $this->assertTrue($eligible->isEmpty());
    }

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
        // Owner has no participant record (not created via CreatesGameInstances)
        // Under explicit owner model, games should always have owner participant

        $this->assertFalse($this->service->canReviewSession($owner, $game));
    }

    /** Scenario: non-owner approved campaign participant can review */
    public function test_can_review_campaign_owner_is_approved_participant(): void
    {
        $campaignOwner = User::factory()->create();
        $participant = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $campaignOwner->id,
            'status' => 'active',
        ]);
        CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
            'user_id' => $participant->id,
            'status' => ParticipantStatus::Approved,
        ]);

        // Campaign must have at least one completed session
        Game::factory()->create([
            'campaign_id' => $campaign->id,
            'owner_id' => $campaignOwner->id,
            'date_time' => now()->subDay(),
        ]);

        $this->assertTrue($this->service->canReviewCampaign($participant, $campaign));
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

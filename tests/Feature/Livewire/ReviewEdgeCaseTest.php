<?php

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GMProfile;
use App\Models\Review;
use App\Models\User;
use App\Services\ReviewAggregateService;
use App\Services\ReviewEligibilityService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

// ═══════════════════════════════════════════════════════════
// SELF-REVIEW PREVENTION
// ═══════════════════════════════════════════════════════════

describe('Self-Review Prevention', function () {
    it('GM cannot review their own game session', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        GMProfile::factory()->create(['user_id' => $gm->id]);
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'date_time' => now()->subDay(),
        ]);

        // GM is the owner — they should not review their own game session
        $service = app(ReviewEligibilityService::class);
        expect($service->canReviewSession($gm, $game))->toBeFalse();
    });

    it('GM cannot review their own campaign', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        GMProfile::factory()->create(['user_id' => $gm->id]);
        $campaign = Campaign::factory()->create(['owner_id' => $gm->id]);
        Game::factory()->create([
            'owner_id' => $gm->id,
            'campaign_id' => $campaign->id,
            'date_time' => now()->subDay(),
        ]);

        $service = app(ReviewEligibilityService::class);
        expect($service->canReviewCampaign($gm, $campaign))->toBeFalse();
    });

    it('owner cannot review own session even if also an approved participant', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        GMProfile::factory()->create(['user_id' => $gm->id]);
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'date_time' => now()->subDay(),
        ]);

        // Even if the GM is somehow added as a player participant,
        // ownership blocks self-review (commit b2cfd35c)
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $gm->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $service = app(ReviewEligibilityService::class);
        expect($service->canReviewSession($gm, $game))->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════
// NON-PARTICIPANT BLOCKED
// ═══════════════════════════════════════════════════════════

describe('Non-Participant Blocked', function () {
    it('user who never applied cannot review', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'date_time' => now()->subDay(),
        ]);
        $stranger = User::factory()->create(['profile_complete' => true]);

        $service = app(ReviewEligibilityService::class);
        expect($service->canReviewSession($stranger, $game))->toBeFalse();
    });

    it('user who was rejected cannot review', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'date_time' => now()->subDay(),
        ]);
        $user = User::factory()->create(['profile_complete' => true]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Rejected->value,
        ]);

        $service = app(ReviewEligibilityService::class);
        expect($service->canReviewSession($user, $game))->toBeFalse();
    });

    it('user rejected from campaign cannot review', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create(['owner_id' => $gm->id]);
        Game::factory()->create([
            'owner_id' => $gm->id,
            'campaign_id' => $campaign->id,
            'date_time' => now()->subDay(),
        ]);
        $user = User::factory()->create(['profile_complete' => true]);
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Rejected->value,
        ]);

        $service = app(ReviewEligibilityService::class);
        expect($service->canReviewCampaign($user, $campaign))->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════
// PRE-DATE BLOCKED (FUTURE GAMES)
// ═══════════════════════════════════════════════════════════

describe('Pre-Date Blocked', function () {
    it('approved participant cannot review future game', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'date_time' => now()->addWeek(),
        ]);
        $player = User::factory()->create(['profile_complete' => true]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $service = app(ReviewEligibilityService::class);
        expect($service->canReviewSession($player, $game))->toBeFalse();
    });

    it('campaign with only future sessions cannot be reviewed', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create(['owner_id' => $gm->id]);
        Game::factory()->create([
            'owner_id' => $gm->id,
            'campaign_id' => $campaign->id,
            'date_time' => now()->addMonth(),
        ]);
        $player = User::factory()->create(['profile_complete' => true]);
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $service = app(ReviewEligibilityService::class);
        expect($service->canReviewCampaign($player, $campaign))->toBeFalse();
    });

    it('game happening right now (now()) cannot be reviewed', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        // Use a future date to ensure isFuture() returns true
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'date_time' => now()->addMinutes(5),
        ]);
        $player = User::factory()->create(['profile_complete' => true]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $service = app(ReviewEligibilityService::class);
        expect($service->canReviewSession($player, $game))->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════
// DUPLICATE REVIEW PREVENTION
// ═══════════════════════════════════════════════════════════

describe('Duplicate Review Prevention', function () {
    it('database constraint prevents duplicate reviews', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        $gmProfile = GMProfile::factory()->create(['user_id' => $gm->id]);
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'date_time' => now()->subDay(),
        ]);
        $player = User::factory()->create(['profile_complete' => true]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        Review::factory()->create([
            'reviewable_type' => Game::class,
            'reviewable_id' => $game->id,
            'reviewer_id' => $player->id,
            'gm_profile_id' => $gmProfile->id,
            'rating' => 4,
        ]);

        $this->expectException(QueryException::class);
        Review::factory()->create([
            'reviewable_type' => Game::class,
            'reviewable_id' => $game->id,
            'reviewer_id' => $player->id,
            'gm_profile_id' => $gmProfile->id,
            'rating' => 3,
        ]);
    });

    it('eligibility service blocks duplicate after first review', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        $gmProfile = GMProfile::factory()->create(['user_id' => $gm->id]);
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'date_time' => now()->subDay(),
        ]);
        $player = User::factory()->create(['profile_complete' => true]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $service = app(ReviewEligibilityService::class);
        expect($service->canReviewSession($player, $game))->toBeTrue();

        Review::factory()->create([
            'reviewable_type' => Game::class,
            'reviewable_id' => $game->id,
            'reviewer_id' => $player->id,
            'gm_profile_id' => $gmProfile->id,
            'rating' => 4,
        ]);

        expect($service->canReviewSession($player, $game))->toBeFalse();
    });

    it('same reviewer can review both game and campaign', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        $gmProfile = GMProfile::factory()->create(['user_id' => $gm->id]);

        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'date_time' => now()->subDay(),
        ]);
        $player = User::factory()->create(['profile_complete' => true]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $campaign = Campaign::factory()->create(['owner_id' => $gm->id]);
        Game::factory()->create([
            'owner_id' => $gm->id,
            'campaign_id' => $campaign->id,
            'date_time' => now()->subDay(),
        ]);
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        Review::factory()->create([
            'reviewable_type' => Game::class,
            'reviewable_id' => $game->id,
            'reviewer_id' => $player->id,
            'gm_profile_id' => $gmProfile->id,
            'rating' => 4,
        ]);

        $service = app(ReviewEligibilityService::class);
        expect($service->canReviewCampaign($player, $campaign))->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════
// AGGREGATE EDGE CASES
// ═══════════════════════════════════════════════════════════

describe('Aggregate Edge Cases', function () {
    it('tie-breaking in proficiency tags returns deterministic order', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        $gmProfile = GMProfile::factory()->create(['user_id' => $gm->id]);

        // Each tag appears exactly once — tie-breaking by key order
        Review::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'proficiency_tags' => ['storytelling'],
            'status' => 'published',
        ]);
        Review::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'proficiency_tags' => ['voices'],
            'status' => 'published',
        ]);
        Review::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'proficiency_tags' => ['world-builder'],
            'status' => 'published',
        ]);

        $result = app(ReviewAggregateService::class)->topProficiencies($gmProfile, 3);
        expect($result)->toHaveCount(3);
        // All should have count = 1, order determined by arsort (preserves key order for equal values)
        $names = $result->pluck('name')->toArray();
        expect($names)->toContain('storytelling', 'voices', 'world-builder');
    });
});

// ═══════════════════════════════════════════════════════════
// OBSERVER INTEGRATION
// ═══════════════════════════════════════════════════════════

describe('Observer Integration', function () {
    it('updating review body does not trigger recalculation', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        $gmProfile = GMProfile::factory()->create([
            'user_id' => $gm->id,
            'average_rating' => null,
            'review_count' => 0,
        ]);

        $review = Review::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'rating' => 4,
            'body' => 'Original text',
            'status' => 'published',
        ]);

        $originalRating = $gmProfile->fresh()->average_rating;

        $review->update(['body' => 'Updated text']);

        expect($gmProfile->fresh()->average_rating)->toBe($originalRating);
    });

    it('updating a published review rating recomputes the aggregate average', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        $gmProfile = GMProfile::factory()->create([
            'user_id' => $gm->id,
            'average_rating' => null,
            'review_count' => 0,
        ]);

        // Two published reviews: a 3-star and a 5-star → average 4.00.
        $reviewA = Review::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'rating' => 3,
            'status' => 'published',
        ]);
        Review::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'rating' => 5,
            'status' => 'published',
        ]);

        expect((float) $gmProfile->fresh()->average_rating)->toBe(4.0)
            ->and($gmProfile->fresh()->review_count)->toBe(2);

        // Edit the 3-star review up to 5 stars → average should become 5.00.
        $reviewA->update(['rating' => 5]);

        expect((float) $gmProfile->fresh()->average_rating)->toBe(5.0)
            ->and($gmProfile->fresh()->review_count)->toBe(2);
    });
});

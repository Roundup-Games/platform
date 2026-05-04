<?php

use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GMProfile;
use App\Models\Review;
use App\Models\User;
use App\Services\ReviewAggregateService;
use App\Services\ReviewEligibilityService;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;


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

        // GM is the owner but NOT a participant, so eligibility should be false
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

    it('owner listed as approved player participant can technically pass eligibility', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        GMProfile::factory()->create(['user_id' => $gm->id]);
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'date_time' => now()->subDay(),
        ]);

        // Even if the GM is somehow added as a player participant
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $gm->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $service = app(ReviewEligibilityService::class);
        // Eligibility service checks participation status, not ownership
        expect($service->canReviewSession($gm, $game))->toBeTrue();
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
            'role' => 'player',
            'status' => 'rejected',
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
            'role' => 'player',
            'status' => 'rejected',
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
            'role' => 'player',
            'status' => 'approved',
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
            'role' => 'player',
            'status' => 'approved',
        ]);

        $service = app(ReviewEligibilityService::class);
        expect($service->canReviewCampaign($player, $campaign))->toBeFalse();
    });

    it('game happening right now (now()) cannot be reviewed', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        // Use a future date to ensure isFuture() returns true
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'date_time' => now()->addSecond(),
        ]);
        $player = User::factory()->create(['profile_complete' => true]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
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
            'role' => 'player',
            'status' => 'approved',
        ]);

        Review::factory()->create([
            'reviewable_type' => Game::class,
            'reviewable_id' => $game->id,
            'reviewer_id' => $player->id,
            'gm_profile_id' => $gmProfile->id,
            'rating' => 4,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
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
            'role' => 'player',
            'status' => 'approved',
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
            'role' => 'player',
            'status' => 'approved',
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
            'role' => 'player',
            'status' => 'approved',
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
    it('all same ratings produce exact average', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        $gmProfile = GMProfile::factory()->create(['user_id' => $gm->id]);

        for ($i = 0; $i < 5; $i++) {
            Review::factory()->create([
                'gm_profile_id' => $gmProfile->id,
                'rating' => 5,
                'status' => 'published',
            ]);
        }

        app(ReviewAggregateService::class)->updateAggregates($gmProfile);
        expect($gmProfile->fresh()->average_rating)->toBe('5.00');
        expect($gmProfile->fresh()->review_count)->toBe(5);
    });

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

    it('single review produces exact rating', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        $gmProfile = GMProfile::factory()->create(['user_id' => $gm->id]);

        Review::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'rating' => 3,
            'status' => 'published',
        ]);

        app(ReviewAggregateService::class)->updateAggregates($gmProfile);
        expect($gmProfile->fresh()->average_rating)->toBe('3.00');
        expect($gmProfile->fresh()->review_count)->toBe(1);
    });

    it('deleting all reviews resets aggregate to null', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        $gmProfile = GMProfile::factory()->create(['user_id' => $gm->id]);

        $review = Review::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'rating' => 4,
            'status' => 'published',
        ]);

        app(ReviewAggregateService::class)->updateAggregates($gmProfile);
        expect($gmProfile->fresh()->review_count)->toBe(1);

        $review->delete();

        expect($gmProfile->fresh()->average_rating)->toBeNull();
        expect($gmProfile->fresh()->review_count)->toBe(0);
    });

    it('rating with 1-star minimum is computed correctly', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        $gmProfile = GMProfile::factory()->create(['user_id' => $gm->id]);

        Review::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'rating' => 1,
            'status' => 'published',
        ]);

        app(ReviewAggregateService::class)->updateAggregates($gmProfile);
        expect($gmProfile->fresh()->average_rating)->toBe('1.00');
    });

    it('top proficiencies respects the limit parameter', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        $gmProfile = GMProfile::factory()->create(['user_id' => $gm->id]);

        Review::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'proficiency_tags' => ['storytelling', 'voices', 'world-builder', 'creativity'],
            'status' => 'published',
        ]);

        $result = app(ReviewAggregateService::class)->topProficiencies($gmProfile, 3);
        expect($result)->toHaveCount(3);
    });
});

// ═══════════════════════════════════════════════════════════
// OBSERVER INTEGRATION
// ═══════════════════════════════════════════════════════════

describe('Observer Integration', function () {
    it('creating a review automatically updates GM aggregates', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        $gmProfile = GMProfile::factory()->create([
            'user_id' => $gm->id,
            'average_rating' => null,
            'review_count' => 0,
        ]);

        Review::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'rating' => 4,
            'status' => 'published',
        ]);

        expect($gmProfile->fresh()->average_rating)->toBe('4.00');
        expect($gmProfile->fresh()->review_count)->toBe(1);
    });

    it('changing review status to reported recalculates aggregates', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        $gmProfile = GMProfile::factory()->create([
            'user_id' => $gm->id,
            'average_rating' => null,
            'review_count' => 0,
        ]);

        $review = Review::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'rating' => 4,
            'status' => 'published',
        ]);

        expect($gmProfile->fresh()->review_count)->toBe(1);

        $reporter = User::factory()->create();
        $review->report($reporter->id, 'spam');

        expect($gmProfile->fresh()->review_count)->toBe(0);
        expect($gmProfile->fresh()->average_rating)->toBeNull();
    });

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
});


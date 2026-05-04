<?php

use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GMProfile;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\URL;


beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

function setupGameWithReview(): array
{
    $gm = User::factory()->create(['profile_complete' => true]);
    $gmProfile = GMProfile::factory()->create(['user_id' => $gm->id]);
    $game = Game::factory()->create([
        'owner_id' => $gm->id,
        'date_time' => now()->subDay(),
        'visibility' => 'public',
    ]);
    $reviewer = User::factory()->create(['profile_complete' => true]);
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $reviewer->id,
        'role' => 'player',
        'status' => 'approved',
    ]);
    $review = Review::factory()->create([
        'reviewable_type' => Game::class,
        'reviewable_id' => $game->id,
        'reviewer_id' => $reviewer->id,
        'gm_profile_id' => $gmProfile->id,
        'rating' => 4,
        'body' => 'Excellent storytelling session!',
        'proficiency_tags' => ['storytelling', 'voices'],
        'status' => 'published',
    ]);

    return compact('gm', 'gmProfile', 'game', 'reviewer', 'review');
}

function setupCampaignWithReview(): array
{
    $gm = User::factory()->create(['profile_complete' => true]);
    $gmProfile = GMProfile::factory()->create(['user_id' => $gm->id]);
    $campaign = Campaign::factory()->create([
        'owner_id' => $gm->id,
        'visibility' => 'public',
    ]);
    Game::factory()->create([
        'owner_id' => $gm->id,
        'campaign_id' => $campaign->id,
        'date_time' => now()->subDay(),
    ]);
    $reviewer = User::factory()->create(['profile_complete' => true]);
    CampaignParticipant::create([
        'campaign_id' => $campaign->id,
        'user_id' => $reviewer->id,
        'role' => 'player',
        'status' => 'approved',
    ]);
    $review = Review::factory()->create([
        'reviewable_type' => Campaign::class,
        'reviewable_id' => $campaign->id,
        'reviewer_id' => $reviewer->id,
        'gm_profile_id' => $gmProfile->id,
        'rating' => 5,
        'body' => 'Incredible multi-session campaign!',
        'proficiency_tags' => ['world-builder'],
        'status' => 'published',
    ]);

    return compact('gm', 'gmProfile', 'campaign', 'reviewer', 'review');
}

// ═══════════════════════════════════════════════════════════
// GAME DETAIL — REVIEW DISPLAY
// ═══════════════════════════════════════════════════════════

describe('Game Detail — Review Display', function () {
    it('displays published reviews on game detail page', function () {
        $data = setupGameWithReview();
        $viewer = User::factory()->create(['profile_complete' => true]);

        $response = $this->actingAs($viewer)
            ->get(route('games.detail', $data['game']->id));

        $response->assertOk()
            ->assertSee('Excellent storytelling session!')
            ->assertSee($data['reviewer']->name);
    })->group('smoke');

    it('shows write review link for eligible user', function () {
        $data = setupGameWithReview();
        // The reviewer already reviewed, so create a second eligible player
        $player2 = User::factory()->create(['profile_complete' => true]);
        GameParticipant::create([
            'game_id' => $data['game']->id,
            'user_id' => $player2->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $response = $this->actingAs($player2)
            ->get(route('games.detail', $data['game']->id));

        $response->assertOk()
            ->assertSee(__('reviews.action_write_review'));
    });

    it('hides write review link for non-eligible user', function () {
        $data = setupGameWithReview();
        $stranger = User::factory()->create(['profile_complete' => true]);

        $response = $this->actingAs($stranger)
            ->get(route('games.detail', $data['game']->id));

        $response->assertOk()
            ->assertDontSee(__('reviews.action_write_review'));
    });

    it('hides reported reviews from game detail', function () {
        $data = setupGameWithReview();
        $data['review']->update(['status' => 'reported']);

        $viewer = User::factory()->create(['profile_complete' => true]);

        $response = $this->actingAs($viewer)
            ->get(route('games.detail', $data['game']->id));

        $response->assertOk()
            ->assertDontSee('Excellent storytelling session!')
            ->assertSee(__('reviews.content_no_reviews_yet'));
    });

    it('displays rating on GM profile', function () {
        $data = setupGameWithReview();
        app(\App\Services\ReviewAggregateService::class)->updateAggregates($data['gmProfile']);

        $viewer = User::factory()->create(['profile_complete' => true]);

        $response = $this->actingAs($viewer)
            ->get(route('profile.public', $data['gm']));

        $response->assertOk()
            ->assertSee('4.0')
            ->assertSee('1 review');
    })->group('smoke');

    it('displays top proficiency badges on GM profile', function () {
        $data = setupGameWithReview();
        app(\App\Services\ReviewAggregateService::class)->updateAggregates($data['gmProfile']);

        $viewer = User::factory()->create(['profile_complete' => true]);

        $response = $this->actingAs($viewer)
            ->get(route('profile.public', $data['gm']));

        $response->assertOk();
        // Check that proficiency badges appear
        $topProfs = $data['gmProfile']->fresh()->topProficiencies();
        expect($topProfs)->toHaveCount(2);
    });

    it('displays individual reviews on GM profile', function () {
        $data = setupGameWithReview();
        app(\App\Services\ReviewAggregateService::class)->updateAggregates($data['gmProfile']);

        $viewer = User::factory()->create(['profile_complete' => true]);

        $response = $this->actingAs($viewer)
            ->get(route('profile.public', $data['gm']));

        $response->assertOk()
            ->assertSee('Excellent storytelling session!');
    });

    it('displays no reviews message when GM has no reviews', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        GMProfile::factory()->create([
            'user_id' => $gm->id,
            'review_count' => 0,
        ]);

        $viewer = User::factory()->create(['profile_complete' => true]);

        $response = $this->actingAs($viewer)
            ->get(route('profile.public', $gm));

        $response->assertOk()
            ->assertSee('No reviews yet');
    });

    it('aggregate reflects all published reviews across games and campaigns', function () {
        $gameData = setupGameWithReview();
        $campaignData = setupCampaignWithReview();
        // Use same GM for both
        $gmProfile = $gameData['gmProfile'];

        // Create campaign review pointing to same GM
        $campaign = Campaign::factory()->create([
            'owner_id' => $gameData['gm']->id,
            'visibility' => 'public',
        ]);
        Game::factory()->create([
            'owner_id' => $gameData['gm']->id,
            'campaign_id' => $campaign->id,
            'date_time' => now()->subDay(),
        ]);
        $player = User::factory()->create(['profile_complete' => true]);
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $player->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
        Review::factory()->create([
            'reviewable_type' => Campaign::class,
            'reviewable_id' => $campaign->id,
            'reviewer_id' => $player->id,
            'gm_profile_id' => $gmProfile->id,
            'rating' => 5,
            'status' => 'published',
        ]);

        app(\App\Services\ReviewAggregateService::class)->updateAggregates($gmProfile);

        $viewer = User::factory()->create(['profile_complete' => true]);
        $response = $this->actingAs($viewer)
            ->get(route('profile.public', $gameData['gm']));

        $response->assertOk()
            ->assertSee('4.5') // (4 + 5) / 2 = 4.5
            ->assertSee('2 reviews');
    });
});

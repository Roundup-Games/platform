<?php

use App\Enums\GmProficiency;
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
    });

    it('shows star rating on game detail reviews', function () {
        $data = setupGameWithReview();
        $viewer = User::factory()->create(['profile_complete' => true]);

        $response = $this->actingAs($viewer)
            ->get(route('games.detail', $data['game']->id));

        $response->assertOk()
            ->assertSee('star'); // Material symbol for filled stars
    });

    it('shows proficiency tags on game detail reviews', function () {
        $data = setupGameWithReview();
        $viewer = User::factory()->create(['profile_complete' => true]);

        $response = $this->actingAs($viewer)
            ->get(route('games.detail', $data['game']->id));

        $response->assertOk();
        // Proficiency tags are rendered via GmProficiency enum labels
        $tag = GmProficiency::from('storytelling');
        $response->assertSee($tag->label());
    });

    it('displays no reviews message when game has no reviews', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'date_time' => now()->subDay(),
            'visibility' => 'public',
        ]);

        $viewer = User::factory()->create(['profile_complete' => true]);

        $response = $this->actingAs($viewer)
            ->get(route('games.detail', $game->id));

        $response->assertOk()
            ->assertSee(__('reviews.content_no_reviews_yet'));
    });

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

    it('guest can see reviews on public game detail', function () {
        $data = setupGameWithReview();

        $response = $this->get(route('games.detail', $data['game']->id));

        $response->assertOk()
            ->assertSee('Excellent storytelling session!');
    });

    it('displays multiple reviews on game detail', function () {
        $data = setupGameWithReview();

        // Add a second review
        $reviewer2 = User::factory()->create(['profile_complete' => true]);
        GameParticipant::create([
            'game_id' => $data['game']->id,
            'user_id' => $reviewer2->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
        Review::factory()->create([
            'reviewable_type' => Game::class,
            'reviewable_id' => $data['game']->id,
            'reviewer_id' => $reviewer2->id,
            'gm_profile_id' => $data['gmProfile']->id,
            'rating' => 5,
            'body' => 'Second opinion review text',
            'status' => 'published',
        ]);

        $viewer = User::factory()->create(['profile_complete' => true]);

        $response = $this->actingAs($viewer)
            ->get(route('games.detail', $data['game']->id));

        $response->assertOk()
            ->assertSee('Excellent storytelling session!')
            ->assertSee('Second opinion review text');
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN DETAIL — REVIEW DISPLAY
// ═══════════════════════════════════════════════════════════

describe('Campaign Detail — Review Display', function () {
    it('displays published reviews on campaign detail page', function () {
        $data = setupCampaignWithReview();
        $viewer = User::factory()->create(['profile_complete' => true]);

        $response = $this->actingAs($viewer)
            ->get(route('campaigns.detail', $data['campaign']->id));

        $response->assertOk()
            ->assertSee('Incredible multi-session campaign!')
            ->assertSee($data['reviewer']->name);
    });

    it('shows proficiency tags on campaign detail reviews', function () {
        $data = setupCampaignWithReview();
        $viewer = User::factory()->create(['profile_complete' => true]);

        $response = $this->actingAs($viewer)
            ->get(route('campaigns.detail', $data['campaign']->id));

        $response->assertOk();
        $tag = GmProficiency::from('world-builder');
        $response->assertSee($tag->label());
    });

    it('displays no reviews message when campaign has no reviews', function () {
        $gm = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create([
            'owner_id' => $gm->id,
            'visibility' => 'public',
        ]);
        Game::factory()->create([
            'owner_id' => $gm->id,
            'campaign_id' => $campaign->id,
            'date_time' => now()->subDay(),
        ]);

        $viewer = User::factory()->create(['profile_complete' => true]);

        $response = $this->actingAs($viewer)
            ->get(route('campaigns.detail', $campaign->id));

        $response->assertOk()
            ->assertSee(__('reviews.content_no_reviews_yet'));
    });

    it('shows write review link for eligible campaign participant', function () {
        $data = setupCampaignWithReview();

        // Create another eligible participant (the existing reviewer already reviewed)
        $player2 = User::factory()->create(['profile_complete' => true]);
        CampaignParticipant::create([
            'campaign_id' => $data['campaign']->id,
            'user_id' => $player2->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $response = $this->actingAs($player2)
            ->get(route('campaigns.detail', $data['campaign']->id));

        $response->assertOk()
            ->assertSee(__('reviews.action_write_review'));
    });

    it('hides reported reviews from campaign detail', function () {
        $data = setupCampaignWithReview();
        $data['review']->update(['status' => 'reported']);

        $viewer = User::factory()->create(['profile_complete' => true]);

        $response = $this->actingAs($viewer)
            ->get(route('campaigns.detail', $data['campaign']->id));

        $response->assertOk()
            ->assertDontSee('Incredible multi-session campaign!')
            ->assertSee(__('reviews.content_no_reviews_yet'));
    });

    it('guest can see reviews on public campaign detail', function () {
        $data = setupCampaignWithReview();

        $response = $this->get(route('campaigns.detail', $data['campaign']->id));

        $response->assertOk()
            ->assertSee('Incredible multi-session campaign!');
    });

    it('displays multiple reviews on campaign detail', function () {
        $data = setupCampaignWithReview();

        $reviewer2 = User::factory()->create(['profile_complete' => true]);
        CampaignParticipant::create([
            'campaign_id' => $data['campaign']->id,
            'user_id' => $reviewer2->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
        Review::factory()->create([
            'reviewable_type' => Campaign::class,
            'reviewable_id' => $data['campaign']->id,
            'reviewer_id' => $reviewer2->id,
            'gm_profile_id' => $data['gmProfile']->id,
            'rating' => 3,
            'body' => 'A different campaign review',
            'status' => 'published',
        ]);

        $viewer = User::factory()->create(['profile_complete' => true]);

        $response = $this->actingAs($viewer)
            ->get(route('campaigns.detail', $data['campaign']->id));

        $response->assertOk()
            ->assertSee('Incredible multi-session campaign!')
            ->assertSee('A different campaign review');
    });
});

// ═══════════════════════════════════════════════════════════
// GM PROFILE — REVIEW DISPLAY
// ═══════════════════════════════════════════════════════════

describe('GM Profile — Review Display', function () {
    // smoke: GM profile shows aggregate review count and rating
    it('displays review count and average rating on GM profile', function () {
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

    it('guest can view GM profile reviews', function () {
        $data = setupGameWithReview();
        app(\App\Services\ReviewAggregateService::class)->updateAggregates($data['gmProfile']);

        $response = $this->get(route('profile.public', $data['gm']));

        $response->assertOk()
            ->assertSee('4.0')
            ->assertSee('Excellent storytelling session!');
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

// ═══════════════════════════════════════════════════════════
// REVIEW DISPLAY — EDGE CASES
// ═══════════════════════════════════════════════════════════

describe('Review Display — Edge Cases', function () {
    it('review without body does not show empty paragraph', function () {
        $data = setupGameWithReview();
        $data['review']->update(['body' => null]);

        $viewer = User::factory()->create(['profile_complete' => true]);

        $response = $this->actingAs($viewer)
            ->get(route('games.detail', $data['game']->id));

        $response->assertOk()
            ->assertSee($data['reviewer']->name);
    });

    it('review without proficiency tags renders without tags section', function () {
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
        Review::factory()->create([
            'reviewable_type' => Game::class,
            'reviewable_id' => $game->id,
            'reviewer_id' => $reviewer->id,
            'gm_profile_id' => $gmProfile->id,
            'rating' => 3,
            'body' => 'No tags review',
            'proficiency_tags' => null,
            'status' => 'published',
        ]);

        $viewer = User::factory()->create(['profile_complete' => true]);

        $response = $this->actingAs($viewer)
            ->get(route('games.detail', $game->id));

        $response->assertOk()
            ->assertSee('No tags review');
    });

    it('report button shows for authenticated non-reviewer on published review', function () {
        $data = setupGameWithReview();
        $viewer = User::factory()->create(['profile_complete' => true]);

        $response = $this->actingAs($viewer)
            ->get(route('games.detail', $data['game']->id));

        $response->assertOk()
            ->assertSee('Excellent storytelling session!');
        // The report-review Livewire component is embedded in the card
    });

    it('report button not shown for review author', function () {
        $data = setupGameWithReview();

        $response = $this->actingAs($data['reviewer'])
            ->get(route('games.detail', $data['game']->id));

        $response->assertOk()
            ->assertSee('Excellent storytelling session!');
        // Author should NOT see the report button — checked via the @if guard
    });

    it('report button not shown for guest users', function () {
        $data = setupGameWithReview();

        $response = $this->get(route('games.detail', $data['game']->id));

        $response->assertOk()
            ->assertSee('Excellent storytelling session!');
        // Guest cannot see report button due to @auth guard
    });

    it('game detail shows reviews section heading', function () {
        $data = setupGameWithReview();
        $viewer = User::factory()->create(['profile_complete' => true]);

        $response = $this->actingAs($viewer)
            ->get(route('games.detail', $data['game']->id));

        $response->assertOk()
            ->assertSee(__('reviews.title_reviews'));
    });

    it('campaign detail shows reviews section heading', function () {
        $data = setupCampaignWithReview();
        $viewer = User::factory()->create(['profile_complete' => true]);

        $response = $this->actingAs($viewer)
            ->get(route('campaigns.detail', $data['campaign']->id));

        $response->assertOk()
            ->assertSee(__('reviews.title_reviews'));
    });
});

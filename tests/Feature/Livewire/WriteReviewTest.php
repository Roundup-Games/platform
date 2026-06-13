<?php

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Livewire\Reviews\WriteReview;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GMProfile;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

function createReviewUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'profile_complete' => true,
    ], $overrides));
}

function createEligibleGameSession(): array
{
    $gm = createReviewUser();
    $gmProfile = GMProfile::factory()->create(['user_id' => $gm->id]);

    $game = Game::factory()->create([
        'owner_id' => $gm->id,
        'date_time' => now()->subDay(),
    ]);

    $player = createReviewUser();
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $player->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    return compact('gm', 'gmProfile', 'game', 'player');
}

function createEligibleCampaign(): array
{
    $gm = createReviewUser();
    $gmProfile = GMProfile::factory()->create(['user_id' => $gm->id]);

    $campaign = Campaign::factory()->create([
        'owner_id' => $gm->id,
    ]);

    // At least one past session
    Game::factory()->create([
        'owner_id' => $gm->id,
        'campaign_id' => $campaign->id,
        'date_time' => now()->subDay(),
    ]);

    $player = createReviewUser();
    CampaignParticipant::create([
        'campaign_id' => $campaign->id,
        'user_id' => $player->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    return compact('gm', 'gmProfile', 'campaign', 'player');
}

// ═══════════════════════════════════════════════════════════
// WRITE REVIEW COMPONENT — GAME SESSION
// ═══════════════════════════════════════════════════════════

describe('WriteReview — Game Session', function () {
    // smoke: write-review form renders for eligible game participant
    it('renders the form for an eligible game session', function () {
        $data = createEligibleGameSession();

        $this->actingAs($data['player']);

        Livewire::test(WriteReview::class, [
            'reviewable_type' => 'game',
            'reviewable_id' => $data['game']->id,
        ])
            ->assertOk()
            ->assertSee('Write a Review')
            ->assertSet('reviewableName', $data['game']->name);
    })->group('smoke');

    it('shows error for non-participant user', function () {
        $data = createEligibleGameSession();
        $stranger = createReviewUser();

        $this->actingAs($stranger);

        Livewire::test(WriteReview::class, [
            'reviewable_type' => 'game',
            'reviewable_id' => $data['game']->id,
        ])
            ->assertSet('errorMessage', 'You are not eligible to review this item.');
    });

    it('shows error for future game session', function () {
        $gm = createReviewUser();
        GMProfile::factory()->create(['user_id' => $gm->id]);
        $game = Game::factory()->create([
            'owner_id' => $gm->id,
            'date_time' => now()->addDay(),
        ]);

        $player = createReviewUser();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $this->actingAs($player);

        Livewire::test(WriteReview::class, [
            'reviewable_type' => 'game',
            'reviewable_id' => $game->id,
        ])
            ->assertSet('errorMessage', 'You are not eligible to review this item.');
    });

    it('submits a review with valid data', function () {
        $data = createEligibleGameSession();
        $this->actingAs($data['player']);

        Livewire::test(WriteReview::class, [
            'reviewable_type' => 'game',
            'reviewable_id' => $data['game']->id,
        ])
            ->set('rating', 4)
            ->set('body', 'Great session! Had a wonderful time.')
            ->set('proficiency_tags', ['storytelling', 'voices'])
            ->call('submit')
            ->assertRedirect();

        $this->assertDatabaseHas('reviews', [
            'reviewable_type' => Game::class,
            'reviewable_id' => $data['game']->id,
            'reviewer_id' => $data['player']->id,
            'gm_profile_id' => $data['gmProfile']->id,
            'rating' => 4,
            'status' => 'published',
        ]);

        $review = Review::first();
        $this->assertEquals(['storytelling', 'voices'], $review->proficiency_tags);
    });

    it('prevents duplicate review', function () {
        $data = createEligibleGameSession();
        $this->actingAs($data['player']);

        // Submit first review
        Review::factory()->create([
            'reviewable_type' => Game::class,
            'reviewable_id' => $data['game']->id,
            'reviewer_id' => $data['player']->id,
            'gm_profile_id' => $data['gmProfile']->id,
            'rating' => 4,
        ]);

        // Try to open write review form
        Livewire::test(WriteReview::class, [
            'reviewable_type' => 'game',
            'reviewable_id' => $data['game']->id,
        ])
            ->assertSet('errorMessage', 'You are not eligible to review this item.');
    });

    it('blocks adding more than 3 tags via toggle', function () {
        $data = createEligibleGameSession();
        $this->actingAs($data['player']);

        Livewire::test(WriteReview::class, [
            'reviewable_type' => 'game',
            'reviewable_id' => $data['game']->id,
        ])
            ->call('toggleTag', 'storytelling')
            ->call('toggleTag', 'voices')
            ->call('toggleTag', 'world-builder')
            ->call('toggleTag', 'creativity')
            ->assertHasErrors(['proficiency_tags']);
    });
});

// ═══════════════════════════════════════════════════════════
// WRITE REVIEW COMPONENT — CAMPAIGN
// ═══════════════════════════════════════════════════════════
// WRITE REVIEW COMPONENT — EDGE CASES
// ═══════════════════════════════════════════════════════════

describe('WriteReview — Edge Cases', function () {
    it('shows not found error for invalid reviewable_id', function () {
        $user = createReviewUser();
        $this->actingAs($user);

        Livewire::test(WriteReview::class, [
            'reviewable_type' => 'game',
            'reviewable_id' => '00000000-0000-0000-0000-000000000000',
        ])
            ->assertSet('errorMessage', 'The item you are trying to review could not be found.');
    });

    it('shows not found error for invalid reviewable_type', function () {
        $user = createReviewUser();
        $this->actingAs($user);

        Livewire::test(WriteReview::class, [
            'reviewable_type' => 'invalid',
            'reviewable_id' => '00000000-0000-0000-0000-000000000000',
        ])
            ->assertSet('errorMessage', 'The item you are trying to review could not be found.');
    });

});

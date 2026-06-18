<?php

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
use App\Policies\ReviewPolicy;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    seedPermissions();
    seedRoles();
    setPermissionsTeamId(1);

    $this->gmUser = User::factory()->create();
    $this->gmProfile = GMProfile::factory()->create(['user_id' => $this->gmUser->id]);
    $this->reviewer = User::factory()->create();
    $this->otherUser = User::factory()->create();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Platform Admin');
    $this->admin->unsetRelations();

    $this->policy = app(ReviewPolicy::class);

    setPermissionsTeamId(1);
});

describe('ReviewPolicy — eligibility methods', function () {
    describe('canReviewSession', function () {
        test('eligible approved participant can review session', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->gmUser->id,
                'date_time' => now()->subDay(),
            ]);

            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->reviewer->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
            ]);

            expect($this->policy->canReviewSession($this->reviewer, $game))->toBeTrue();
        });

        test('ineligible user cannot review session', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->gmUser->id,
                'date_time' => now()->subDay(),
            ]);

            expect($this->policy->canReviewSession($this->otherUser, $game))->toBeFalse();
        });

        test('cannot review future session', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->gmUser->id,
                'date_time' => now()->addDay(),
            ]);

            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->reviewer->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
            ]);

            expect($this->policy->canReviewSession($this->reviewer, $game))->toBeFalse();
        });

        test('cannot review session already reviewed', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->gmUser->id,
                'date_time' => now()->subDay(),
            ]);

            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->reviewer->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
            ]);

            Review::factory()->create([
                'reviewable_type' => Game::class,
                'reviewable_id' => $game->id,
                'reviewer_id' => $this->reviewer->id,
                'gm_profile_id' => $this->gmProfile->id,
            ]);

            expect($this->policy->canReviewSession($this->reviewer, $game))->toBeFalse();
        });
    });

    describe('canReviewCampaign', function () {
        test('eligible participant can review campaign', function () {
            $campaign = Campaign::factory()->create(['owner_id' => $this->gmUser->id]);

            Game::factory()->create([
                'owner_id' => $this->gmUser->id,
                'campaign_id' => $campaign->id,
                'date_time' => now()->subDay(),
            ]);

            CampaignParticipant::create([
                'campaign_id' => $campaign->id,
                'user_id' => $this->reviewer->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
            ]);

            expect($this->policy->canReviewCampaign($this->reviewer, $campaign))->toBeTrue();
        });

        test('ineligible user cannot review campaign', function () {
            $campaign = Campaign::factory()->create(['owner_id' => $this->gmUser->id]);

            expect($this->policy->canReviewCampaign($this->otherUser, $campaign))->toBeFalse();
        });

        test('cannot review campaign with no past sessions', function () {
            $campaign = Campaign::factory()->create(['owner_id' => $this->gmUser->id]);

            Game::factory()->create([
                'owner_id' => $this->gmUser->id,
                'campaign_id' => $campaign->id,
                'date_time' => now()->addDay(),
            ]);

            CampaignParticipant::create([
                'campaign_id' => $campaign->id,
                'user_id' => $this->reviewer->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
            ]);

            expect($this->policy->canReviewCampaign($this->reviewer, $campaign))->toBeFalse();
        });
    });

    describe('canReviewVenue', function () {
        test('eligible approved participant of completed game at venue can review', function () {
            $venue = Location::factory()->verifiedVenue()->create([
                'venue_type' => VenueType::Cafe,
                'slug' => fake()->unique()->slug(),
            ]);

            $game = Game::factory()->create([
                'owner_id' => $this->gmUser->id,
                'location_id' => $venue->id,
                'date_time' => now()->subDay(),
            ]);

            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->reviewer->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
            ]);

            expect($this->policy->canReviewVenue($this->reviewer, $venue))->toBeTrue();
        });

        test('ineligible user cannot review venue', function () {
            $venue = Location::factory()->verifiedVenue()->create([
                'venue_type' => VenueType::Cafe,
                'slug' => fake()->unique()->slug(),
            ]);

            expect($this->policy->canReviewVenue($this->otherUser, $venue))->toBeFalse();
        });

        test('admin bypass applies to canReviewVenue', function () {
            $venue = Location::factory()->verifiedVenue()->create([
                'venue_type' => VenueType::Cafe,
                'slug' => fake()->unique()->slug(),
            ]);

            $this->actingAs($this->admin);
            // Routed through the Gate so the policy before() global-admin bypass fires.
            // ReviewPolicy is resolved by passing [Review::class, $venue] — the production
            // invocation pattern from WriteReview — even though the reviewable is a Location.
            expect(Gate::allows('canReviewVenue', [Review::class, $venue]))->toBeTrue();
        });
    });

    describe('viewEligibility', function () {
        test('authenticated user can view their own eligibility', function () {
            $this->actingAs($this->reviewer);
            expect(Gate::allows('viewEligibility', Review::class))->toBeTrue();
        });

        test('admin bypass applies to viewEligibility', function () {
            $this->actingAs($this->admin);
            expect(Gate::allows('viewEligibility', Review::class))->toBeTrue();
        });

        test('guest cannot view eligibility', function () {
            expect(Gate::allows('viewEligibility', Review::class))->toBeFalse();
        });
    });
});

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
use App\Services\ReviewEligibilityService;
use Illuminate\Support\Facades\Gate;

/*
 * M077/S01 consolidation: 25 tests → 7 flow tests (one per describe block).
 * Mapping: update 3→1, delete 3→1, report 2→1, canReviewVenue 3→1,
 * viewEligibility 3→1, canReviewSession 6→1, canReviewCampaign 5→1.
 * The shared beforeEach world is unchanged; every original assertion is
 * preserved verbatim inside its describe's flow, with the original test name
 * carried as a comment per check. Shared-shape fixtures were merged where the
 * checks are user-scoped (one past game/campaign carrying the different-status
 * participants, each viewer asserted against it; already-reviewed asserted
 * after the approved TRUE). Distinct shapes (future game, bare campaign) keep
 * per-case fixtures inside the flow. actingAs() persists within a test, so
 * guest checks run before any actingAs() and viewer switches are ordered.
 */

beforeEach(function () {
    seedPermissions();
    seedRoles();
    setPermissionsTeamId(1);

    $this->service = app(ReviewEligibilityService::class);
    $this->policy = app(ReviewPolicy::class);
    $this->gmUser = User::factory()->create();
    $this->gmProfile = GMProfile::factory()->create(['user_id' => $this->gmUser->id]);
    $this->reviewer = User::factory()->create();
    $this->admin = User::factory()->create();

    // Assign Platform Admin
    $this->admin->assignRole('Platform Admin');
    $this->admin->unsetRelations();
    setPermissionsTeamId(1);
});

describe('ReviewPolicy', function () {
    describe('update', function () {
        test('update policy matrix (flow)', function () {
            $review = Review::factory()->create(['reviewer_id' => $this->reviewer->id]);

            // reviewer can update their own review
            $this->actingAs($this->reviewer);
            expect(Gate::allows('update', $review))->toBeTrue();

            // other user cannot update someone else review
            $other = User::factory()->create();
            $this->actingAs($other);
            expect(Gate::allows('update', $review))->toBeFalse();

            // admin can update any review
            $this->actingAs($this->admin);
            expect(Gate::allows('update', $review))->toBeTrue();
        });
    });

    describe('delete', function () {
        test('delete policy matrix (flow)', function () {
            $review = Review::factory()->create(['reviewer_id' => $this->reviewer->id]);

            // reviewer cannot delete their own review
            $this->actingAs($this->reviewer);
            expect(Gate::allows('delete', $review))->toBeFalse();

            // other user cannot delete review
            $other = User::factory()->create();
            $this->actingAs($other);
            expect(Gate::allows('delete', $review))->toBeFalse();

            // admin can delete any review
            $this->actingAs($this->admin);
            expect(Gate::allows('delete', $review))->toBeTrue();
        });
    });

    describe('report', function () {
        test('report policy matrix (flow)', function () {
            $review = Review::factory()->create(['reviewer_id' => $this->reviewer->id]);

            // any authenticated user can report a review they did not write
            $reporter = User::factory()->create();
            $this->actingAs($reporter);
            expect(Gate::allows('report', $review))->toBeTrue();

            // reviewer cannot report their own review
            $this->actingAs($this->reviewer);
            expect(Gate::allows('report', $review))->toBeFalse();
        });
    });

    // Migrated from ReviewPolicyEligibilityTest — venue + viewEligibility are the
    // only ReviewPolicy methods not covered by the ReviewEligibilityService block below.
    describe('canReviewVenue', function () {
        test('canReviewVenue eligibility matrix (flow)', function () {
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

            // eligible approved participant of completed game at venue can review
            expect($this->policy->canReviewVenue($this->reviewer, $venue))->toBeTrue();

            // ineligible user cannot review venue
            $other = User::factory()->create();
            expect($this->policy->canReviewVenue($other, $venue))->toBeFalse();

            // admin bypass applies to canReviewVenue
            $this->actingAs($this->admin);
            // Routed through the Gate so the policy before() global-admin bypass fires.
            // ReviewPolicy is resolved by passing [Review::class, $venue] — the production
            // invocation pattern from WriteReview — even though the reviewable is a Location.
            expect(Gate::allows('canReviewVenue', [Review::class, $venue]))->toBeTrue();
        });
    });

    describe('viewEligibility', function () {
        test('viewEligibility eligibility matrix (flow)', function () {
            // guest cannot view eligibility (asserted before any actingAs —
            // actingAs() persists within a test)
            expect(Gate::allows('viewEligibility', Review::class))->toBeFalse();

            // authenticated user can view their own eligibility
            $this->actingAs($this->reviewer);
            expect(Gate::allows('viewEligibility', Review::class))->toBeTrue();

            // admin bypass applies to viewEligibility
            $this->actingAs($this->admin);
            expect(Gate::allows('viewEligibility', Review::class))->toBeTrue();
        });
    });
});

describe('ReviewEligibilityService', function () {
    describe('canReviewSession', function () {
        test('canReviewSession eligibility matrix (flow)', function () {
            // Shared past game: the approved/pending/rejected cases shared the
            // same game shape, so one game carries the different-status
            // participants and each viewer is asserted against it (the service
            // checks are user-scoped, so results are unchanged).
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

            $pendingUser = User::factory()->create();
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $pendingUser->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Pending->value,
            ]);

            $rejectedUser = User::factory()->create();
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $rejectedUser->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Rejected->value,
            ]);

            // approved participant of past game can review
            expect($this->service->canReviewSession($this->reviewer, $game))->toBeTrue();

            // pending participant cannot review
            expect($this->service->canReviewSession($pendingUser, $game))->toBeFalse();

            // rejected participant cannot review
            expect($this->service->canReviewSession($rejectedUser, $game))->toBeFalse();

            // non-participant cannot review
            $stranger = User::factory()->create();
            expect($this->service->canReviewSession($stranger, $game))->toBeFalse();

            // cannot review future game
            $futureGame = Game::factory()->create([
                'owner_id' => $this->gmUser->id,
                'date_time' => now()->addDay(),
            ]);

            GameParticipant::create([
                'game_id' => $futureGame->id,
                'user_id' => $this->reviewer->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
            ]);

            expect($this->service->canReviewSession($this->reviewer, $futureGame))->toBeFalse();

            // cannot review same game twice
            // Already reviewed
            Review::factory()->create([
                'reviewable_type' => (new Game)->getMorphClass(),
                'reviewable_id' => $game->id,
                'reviewer_id' => $this->reviewer->id,
                'gm_profile_id' => $this->gmProfile->id,
            ]);

            expect($this->service->canReviewSession($this->reviewer, $game))->toBeFalse();
        });
    });

    describe('canReviewCampaign', function () {
        test('canReviewCampaign eligibility matrix (flow)', function () {
            // Shared campaign: approved/pending/already-reviewed shared the
            // same completed-session campaign shape, so one campaign carries
            // the different-status participants and each viewer is asserted
            // against it (the service checks are user-scoped).
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

            $pendingUser = User::factory()->create();
            CampaignParticipant::create([
                'campaign_id' => $campaign->id,
                'user_id' => $pendingUser->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Pending->value,
            ]);

            // approved participant of campaign with completed session can review
            expect($this->service->canReviewCampaign($this->reviewer, $campaign))->toBeTrue();

            // cannot review campaign with no completed sessions
            $futureCampaign = Campaign::factory()->create(['owner_id' => $this->gmUser->id]);

            Game::factory()->create([
                'owner_id' => $this->gmUser->id,
                'campaign_id' => $futureCampaign->id,
                'date_time' => now()->addDay(),
            ]);

            CampaignParticipant::create([
                'campaign_id' => $futureCampaign->id,
                'user_id' => $this->reviewer->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
            ]);

            expect($this->service->canReviewCampaign($this->reviewer, $futureCampaign))->toBeFalse();

            // pending campaign participant cannot review
            expect($this->service->canReviewCampaign($pendingUser, $campaign))->toBeFalse();

            // non-participant cannot review campaign
            $bareCampaign = Campaign::factory()->create(['owner_id' => $this->gmUser->id]);

            expect($this->service->canReviewCampaign($this->reviewer, $bareCampaign))->toBeFalse();

            // cannot review same campaign twice
            // Already reviewed
            Review::factory()->create([
                'reviewable_type' => (new Campaign)->getMorphClass(),
                'reviewable_id' => $campaign->id,
                'reviewer_id' => $this->reviewer->id,
                'gm_profile_id' => $this->gmProfile->id,
            ]);

            expect($this->service->canReviewCampaign($this->reviewer, $campaign))->toBeFalse();
        });
    });

});

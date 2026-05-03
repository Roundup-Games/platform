<?php

use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GMProfile;
use App\Models\GameParticipant;
use App\Models\Review;
use App\Models\User;
use App\Services\ReviewEligibilityService;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    seedPermissions();
    seedRoles();
    setPermissionsTeamId(1);

    $this->service = app(ReviewEligibilityService::class);
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
        test('reviewer can update their own review', function () {
            $review = Review::factory()->create(['reviewer_id' => $this->reviewer->id]);
            $this->actingAs($this->reviewer);

            expect(Gate::allows('update', $review))->toBeTrue();
        });

        test('other user cannot update someone else review', function () {
            $review = Review::factory()->create(['reviewer_id' => $this->reviewer->id]);
            $other = User::factory()->create();
            $this->actingAs($other);

            expect(Gate::allows('update', $review))->toBeFalse();
        });

        test('admin can update any review', function () {
            $review = Review::factory()->create(['reviewer_id' => $this->reviewer->id]);
            $this->actingAs($this->admin);

            expect(Gate::allows('update', $review))->toBeTrue();
        });
    });

    describe('delete', function () {
        test('reviewer cannot delete their own review', function () {
            $review = Review::factory()->create(['reviewer_id' => $this->reviewer->id]);
            $this->actingAs($this->reviewer);

            expect(Gate::allows('delete', $review))->toBeFalse();
        });

        test('other user cannot delete review', function () {
            $review = Review::factory()->create(['reviewer_id' => $this->reviewer->id]);
            $other = User::factory()->create();
            $this->actingAs($other);

            expect(Gate::allows('delete', $review))->toBeFalse();
        });

        test('admin can delete any review', function () {
            $review = Review::factory()->create(['reviewer_id' => $this->reviewer->id]);
            $this->actingAs($this->admin);

            expect(Gate::allows('delete', $review))->toBeTrue();
        });
    });

    describe('report', function () {
        test('any authenticated user can report a review they did not write', function () {
            $review = Review::factory()->create(['reviewer_id' => $this->reviewer->id]);
            $reporter = User::factory()->create();
            $this->actingAs($reporter);

            expect(Gate::allows('report', $review))->toBeTrue();
        })->group('smoke');

        test('reviewer cannot report their own review', function () {
            $review = Review::factory()->create(['reviewer_id' => $this->reviewer->id]);
            $this->actingAs($this->reviewer);

            expect(Gate::allows('report', $review))->toBeFalse();
        })->group('smoke');
    });
});

describe('ReviewEligibilityService', function () {
    describe('canReviewSession', function () {
        test('approved participant of past game can review', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->gmUser->id,
                'date_time' => now()->subDay(),
            ]);

            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->reviewer->id,
                'role' => 'player',
                'status' => 'approved',
            ]);

            expect($this->service->canReviewSession($this->reviewer, $game))->toBeTrue();
        })->group('smoke');

        test('pending participant cannot review', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->gmUser->id,
                'date_time' => now()->subDay(),
            ]);

            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->reviewer->id,
                'role' => 'player',
                'status' => 'pending',
            ]);

            expect($this->service->canReviewSession($this->reviewer, $game))->toBeFalse();
        })->group('smoke');

        test('rejected participant cannot review', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->gmUser->id,
                'date_time' => now()->subDay(),
            ]);

            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->reviewer->id,
                'role' => 'player',
                'status' => 'rejected',
            ]);

            expect($this->service->canReviewSession($this->reviewer, $game))->toBeFalse();
        })->group('smoke');

        test('non-participant cannot review', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->gmUser->id,
                'date_time' => now()->subDay(),
            ]);

            expect($this->service->canReviewSession($this->reviewer, $game))->toBeFalse();
        })->group('smoke');

        test('cannot review future game', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->gmUser->id,
                'date_time' => now()->addDay(),
            ]);

            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->reviewer->id,
                'role' => 'player',
                'status' => 'approved',
            ]);

            expect($this->service->canReviewSession($this->reviewer, $game))->toBeFalse();
        });

        test('cannot review same game twice', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->gmUser->id,
                'date_time' => now()->subDay(),
            ]);

            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->reviewer->id,
                'role' => 'player',
                'status' => 'approved',
            ]);

            // Already reviewed
            Review::factory()->create([
                'reviewable_type' => Game::class,
                'reviewable_id' => $game->id,
                'reviewer_id' => $this->reviewer->id,
                'gm_profile_id' => $this->gmProfile->id,
            ]);

            expect($this->service->canReviewSession($this->reviewer, $game))->toBeFalse();
        })->group('smoke');
    });

    describe('canReviewCampaign', function () {
        test('approved participant of campaign with completed session can review', function () {
            $campaign = Campaign::factory()->create(['owner_id' => $this->gmUser->id]);

            Game::factory()->create([
                'owner_id' => $this->gmUser->id,
                'campaign_id' => $campaign->id,
                'date_time' => now()->subDay(),
            ]);

            CampaignParticipant::create([
                'campaign_id' => $campaign->id,
                'user_id' => $this->reviewer->id,
                'role' => 'player',
                'status' => 'approved',
            ]);

            expect($this->service->canReviewCampaign($this->reviewer, $campaign))->toBeTrue();
        })->group('smoke');

        test('cannot review campaign with no completed sessions', function () {
            $campaign = Campaign::factory()->create(['owner_id' => $this->gmUser->id]);

            Game::factory()->create([
                'owner_id' => $this->gmUser->id,
                'campaign_id' => $campaign->id,
                'date_time' => now()->addDay(),
            ]);

            CampaignParticipant::create([
                'campaign_id' => $campaign->id,
                'user_id' => $this->reviewer->id,
                'role' => 'player',
                'status' => 'approved',
            ]);

            expect($this->service->canReviewCampaign($this->reviewer, $campaign))->toBeFalse();
        });

        test('pending campaign participant cannot review', function () {
            $campaign = Campaign::factory()->create(['owner_id' => $this->gmUser->id]);

            Game::factory()->create([
                'owner_id' => $this->gmUser->id,
                'campaign_id' => $campaign->id,
                'date_time' => now()->subDay(),
            ]);

            CampaignParticipant::create([
                'campaign_id' => $campaign->id,
                'user_id' => $this->reviewer->id,
                'role' => 'player',
                'status' => 'pending',
            ]);

            expect($this->service->canReviewCampaign($this->reviewer, $campaign))->toBeFalse();
        })->group('smoke');

        test('non-participant cannot review campaign', function () {
            $campaign = Campaign::factory()->create(['owner_id' => $this->gmUser->id]);

            expect($this->service->canReviewCampaign($this->reviewer, $campaign))->toBeFalse();
        })->group('smoke');

        test('cannot review same campaign twice', function () {
            $campaign = Campaign::factory()->create(['owner_id' => $this->gmUser->id]);

            Game::factory()->create([
                'owner_id' => $this->gmUser->id,
                'campaign_id' => $campaign->id,
                'date_time' => now()->subDay(),
            ]);

            CampaignParticipant::create([
                'campaign_id' => $campaign->id,
                'user_id' => $this->reviewer->id,
                'role' => 'player',
                'status' => 'approved',
            ]);

            Review::factory()->create([
                'reviewable_type' => Campaign::class,
                'reviewable_id' => $campaign->id,
                'reviewer_id' => $this->reviewer->id,
                'gm_profile_id' => $this->gmProfile->id,
            ]);

            expect($this->service->canReviewCampaign($this->reviewer, $campaign))->toBeFalse();
        })->group('smoke');
    });

    describe('getEligibleReviews', function () {
        test('returns eligible games and campaigns for user', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->gmUser->id,
                'date_time' => now()->subDay(),
            ]);

            $campaign = Campaign::factory()->create(['owner_id' => $this->gmUser->id]);

            Game::factory()->create([
                'owner_id' => $this->gmUser->id,
                'campaign_id' => $campaign->id,
                'date_time' => now()->subDay(),
            ]);

            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->reviewer->id,
                'role' => 'player',
                'status' => 'approved',
            ]);

            CampaignParticipant::create([
                'campaign_id' => $campaign->id,
                'user_id' => $this->reviewer->id,
                'role' => 'player',
                'status' => 'approved',
            ]);

            $eligible = $this->service->getEligibleReviews($this->reviewer);

            expect($eligible)->toHaveCount(2);

            $types = $eligible->pluck('reviewable_type')->toArray();
            expect($types)->toContain(Game::class);
            expect($types)->toContain(Campaign::class);
        });

        test('excludes already-reviewed entities', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->gmUser->id,
                'date_time' => now()->subDay(),
            ]);

            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->reviewer->id,
                'role' => 'player',
                'status' => 'approved',
            ]);

            Review::factory()->create([
                'reviewable_type' => Game::class,
                'reviewable_id' => $game->id,
                'reviewer_id' => $this->reviewer->id,
                'gm_profile_id' => $this->gmProfile->id,
            ]);

            $eligible = $this->service->getEligibleReviews($this->reviewer);

            expect($eligible)->toHaveCount(0);
        });

        test('excludes future games', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->gmUser->id,
                'date_time' => now()->addDay(),
            ]);

            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->reviewer->id,
                'role' => 'player',
                'status' => 'approved',
            ]);

            $eligible = $this->service->getEligibleReviews($this->reviewer);

            expect($eligible)->toHaveCount(0);
        });

        test('excludes non-approved participants', function () {
            $game = Game::factory()->create([
                'owner_id' => $this->gmUser->id,
                'date_time' => now()->subDay(),
            ]);

            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->reviewer->id,
                'role' => 'player',
                'status' => 'pending',
            ]);

            $eligible = $this->service->getEligibleReviews($this->reviewer);

            expect($eligible)->toHaveCount(0);
        });

        test('returns empty collection for user with no participations', function () {
            $eligible = $this->service->getEligibleReviews($this->reviewer);

            expect($eligible)->toHaveCount(0);
        });
    });
});

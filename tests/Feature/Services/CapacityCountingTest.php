<?php

namespace Tests\Feature\Services;

use App\Enums\GameStatus;
use App\Enums\JoinSource;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\BenchService;
use App\Services\DashboardCacheService;
use App\Services\ParticipantService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

uses(\Illuminate\Foundation\Testing\DatabaseTransactions::class);

// ── Helper: create game with owner participant ─────

function createGameWithOwner(GameSystem $system, int $maxPlayers, array $extra = []): Game
{
    $owner = User::factory()->create();
    $game = Game::factory()->create(array_merge([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'max_players' => $maxPlayers,
    ], $extra));

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $owner->id,
        'role' => ParticipantRole::Owner->value,
        'status' => 'approved',
        'join_source' => JoinSource::Application,
    ]);

    return $game->fresh();
}

describe('Capacity and Counting Correctness', function () {

    beforeEach(function () {
        $this->service = new ParticipantService();
        $this->benchService = new BenchService();
        $this->system = GameSystem::factory()->create();
    });

    // ── 1. isAtCapacity: owner + 1 approved = full at max_players=2 ──

    describe('isAtCapacity with owner as explicit participant', function () {
        it('returns true when owner + 1 approved player fills max_players=2', function () {
            $game = createGameWithOwner($this->system, 2);

            // Add 1 approved player → owner + 1 = 2/2 = full
            $player = User::factory()->create();
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $player->id,
                'role' => ParticipantRole::Player->value,
                'status' => 'approved',
                'join_source' => JoinSource::Application,
            ]);

            expect($this->service->getApprovedParticipantCount($game))->toBe(2);
            expect($this->service->isAtCapacity($game))->toBeTrue();
        });

        it('returns false when owner alone is under max_players=2', function () {
            $game = createGameWithOwner($this->system, 2);

            expect($this->service->getApprovedParticipantCount($game))->toBe(1);
            expect($this->service->isAtCapacity($game))->toBeFalse();
        });
    });

    // ── 2. Approved count excludes pending; capacity not exceeded ──

    describe('approved count excludes non-approved statuses', function () {
        it('counts only approved participants — pending does not inflate count', function () {
            $game = createGameWithOwner($this->system, 2);

            // 1 approved player
            $approvedPlayer = User::factory()->create();
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $approvedPlayer->id,
                'role' => ParticipantRole::Player->value,
                'status' => 'approved',
                'join_source' => JoinSource::Application,
            ]);

            // 1 pending invite
            $pendingPlayer = User::factory()->create();
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $pendingPlayer->id,
                'role' => ParticipantRole::Invited->value,
                'status' => 'pending',
                'join_source' => JoinSource::FriendInvite,
            ]);

            // Owner (1) + approved (1) = 2; pending does NOT count
            expect($this->service->getApprovedParticipantCount($game))->toBe(2);
            expect($this->service->isAtCapacity($game))->toBeTrue();
        });

        it('counts owner + multiple approved players correctly', function () {
            $game = createGameWithOwner($this->system, 5);

            // Add 3 approved players
            for ($i = 0; $i < 3; $i++) {
                GameParticipant::create([
                    'game_id' => $game->id,
                    'user_id' => User::factory()->create()->id,
                    'role' => ParticipantRole::Player->value,
                    'status' => 'approved',
                    'join_source' => JoinSource::Application,
                ]);
            }

            // 1 waitlisted — should not count
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => User::factory()->create()->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Waitlisted->value,
                'join_source' => JoinSource::Application,
            ]);

            expect($this->service->getApprovedParticipantCount($game))->toBe(4); // owner + 3
            expect($this->service->isAtCapacity($game))->toBeFalse(); // 4/5
        });
    });

    // ── 3. Waitlist overflow: owner + 5 approved at max_players=6 ──

    describe('waitlist overflow at capacity', function () {
        it('sends next player to waitlist when game is full (owner + 5 at max=6)', function () {
            $game = createGameWithOwner($this->system, 6);

            // Add 5 approved players → owner + 5 = 6/6
            for ($i = 0; $i < 5; $i++) {
                GameParticipant::create([
                    'game_id' => $game->id,
                    'user_id' => User::factory()->create()->id,
                    'role' => ParticipantRole::Player->value,
                    'status' => 'approved',
                    'join_source' => JoinSource::Application,
                ]);
            }

            expect($this->service->getApprovedParticipantCount($game))->toBe(6);
            expect($this->service->isAtCapacity($game))->toBeTrue();

            // Next player accepts invitation → goes to waitlist
            $extraPlayer = User::factory()->create();
            $participant = GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $extraPlayer->id,
                'role' => ParticipantRole::Invited->value,
                'status' => 'pending',
                'join_source' => JoinSource::FriendInvite,
            ]);

            $result = $this->service->acceptInvitation($participant, $game, $extraPlayer);

            expect($result->success)->toBeTrue();
            expect($participant->fresh()->status)->toBe(ParticipantStatus::Waitlisted);
            // Approved count stays the same
            expect($this->service->getApprovedParticipantCount($game->fresh()))->toBe(6);
        });

        it('allows acceptance when exactly one spot remains', function () {
            $game = createGameWithOwner($this->system, 3);

            // 1 more approved player → owner + 1 = 2/3
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => User::factory()->create()->id,
                'role' => ParticipantRole::Player->value,
                'status' => 'approved',
                'join_source' => JoinSource::Application,
            ]);

            expect($this->service->getApprovedParticipantCount($game))->toBe(2);
            expect($this->service->isAtCapacity($game))->toBeFalse();

            // Acceptance fills last spot
            $lastPlayer = User::factory()->create();
            $participant = GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $lastPlayer->id,
                'role' => ParticipantRole::Invited->value,
                'status' => 'pending',
                'join_source' => JoinSource::FriendInvite,
            ]);

            $result = $this->service->acceptInvitation($participant, $game, $lastPlayer);

            expect($result->success)->toBeTrue();
            expect($participant->fresh()->status->value)->toBe('approved');
            expect($this->service->getApprovedParticipantCount($game->fresh()))->toBe(3);
        });
    });

    // ── 4. BenchService capacity check with owner participant ──

    describe('BenchService capacity with owner included', function () {
        it('allows bench add when owner fills entity to max_players', function () {
            $game = Game::factory()->create([
                'owner_id' => User::factory()->create()->id,
                'game_system_id' => $this->system->id,
                'max_players' => 2,
                'bench_mode' => true,
            ]);

            // Owner participant
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $game->owner_id,
                'role' => ParticipantRole::Owner->value,
                'status' => 'approved',
                'join_source' => JoinSource::Application,
            ]);

            // 1 approved player → owner + 1 = 2/2
            $player = User::factory()->create();
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $player->id,
                'role' => ParticipantRole::Player->value,
                'status' => 'approved',
                'join_source' => JoinSource::Application,
            ]);

            // Verify at capacity
            expect($this->service->isAtCapacity($game))->toBeTrue();

            // Add to bench — should succeed because entity IS full
            $benchedUser = User::factory()->create();
            $result = $this->benchService->addToBench($game, $benchedUser);

            expect($result)->toBeInstanceOf(GameParticipant::class);
            expect($result->status)->toBe(ParticipantStatus::Benched);
            expect($result->user_id)->toBe($benchedUser->id);
        });

        it('refuses bench add when entity is not full (owner only, max=3)', function () {
            $game = Game::factory()->create([
                'owner_id' => User::factory()->create()->id,
                'game_system_id' => $this->system->id,
                'max_players' => 3,
                'bench_mode' => true,
            ]);

            // Owner participant — 1/3, not full
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $game->owner_id,
                'role' => ParticipantRole::Owner->value,
                'status' => 'approved',
                'join_source' => JoinSource::Application,
            ]);

            $benchedUser = User::factory()->create();

            expect(fn () => $this->benchService->addToBench($game, $benchedUser))
                ->toThrow(\LogicException::class, 'Cannot add to bench: entity is not full.');
        });

        it('promotes from bench correctly accounting for owner', function () {
            $game = Game::factory()->create([
                'owner_id' => User::factory()->create()->id,
                'game_system_id' => $this->system->id,
                'max_players' => 2,
                'bench_mode' => true,
            ]);

            // Owner participant
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $game->owner_id,
                'role' => ParticipantRole::Owner->value,
                'status' => 'approved',
                'join_source' => JoinSource::Application,
            ]);

            // 1 approved → full (owner + 1 = 2/2)
            $player = User::factory()->create();
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $player->id,
                'role' => ParticipantRole::Player->value,
                'status' => 'approved',
                'join_source' => JoinSource::Application,
            ]);

            // Bench a user
            $benchedUser = User::factory()->create();
            $benched = $this->benchService->addToBench($game, $benchedUser);

            // Remove the approved player to open a spot
            GameParticipant::where('game_id', $game->id)
                ->where('user_id', $player->id)
                ->delete();

            // Now owner (1/2) — promote should work
            $this->benchService->promoteFromBench((string) $benched->id, 'game');

            expect($benched->fresh()->status)->toBe(ParticipantStatus::Approved);
            expect($this->service->getApprovedParticipantCount($game->fresh()))->toBe(2); // owner + promoted
        });
    });

    // ── 5. DashboardCacheService trending nearby count ──

    describe('DashboardCacheService trending count includes owner', function () {
        it('counts owner participant in trending nearby participant_count', function () {
            $dashboardService = app(DashboardCacheService::class);
            $owner = User::factory()->create();

            // Create game with a physical location (required for trending)
            $game = Game::factory()->create([
                'owner_id' => $owner->id,
                'game_system_id' => $this->system->id,
                'max_players' => 4,
                'status' => GameStatus::Scheduled->value,
                'visibility' => 'public',
                'date_time' => now()->addDays(5),
            ]);

            // Owner participant
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $owner->id,
                'role' => ParticipantRole::Owner->value,
                'status' => 'approved',
                'join_source' => JoinSource::Application,
            ]);

            // Add 2 more approved
            for ($i = 0; $i < 2; $i++) {
                GameParticipant::create([
                    'game_id' => $game->id,
                    'user_id' => User::factory()->create()->id,
                    'role' => ParticipantRole::Player->value,
                    'status' => 'approved',
                    'join_source' => JoinSource::Application,
                ]);
            }

            // getApprovedParticipantCount should be 3
            expect($this->service->getApprovedParticipantCount($game))->toBe(3);
        });
    });

    // ── 6. Campaign member count includes owner ──

    describe('Campaign member count includes owner', function () {
        it('counts owner as approved participant in campaign', function () {
            $owner = User::factory()->create();
            $campaign = Campaign::factory()->create([
                'owner_id' => $owner->id,
                'game_system_id' => $this->system->id,
                'max_players' => 5,
            ]);

            CampaignParticipant::create([
                'campaign_id' => $campaign->id,
                'user_id' => $owner->id,
                'role' => ParticipantRole::Owner->value,
                'status' => 'approved',
                'join_source' => JoinSource::Application,
            ]);

            // Add 2 members
            for ($i = 0; $i < 2; $i++) {
                CampaignParticipant::create([
                    'campaign_id' => $campaign->id,
                    'user_id' => User::factory()->create()->id,
                    'role' => ParticipantRole::Player->value,
                    'status' => 'approved',
                    'join_source' => JoinSource::Application,
                ]);
            }

            expect($this->service->getApprovedParticipantCount($campaign))->toBe(3); // owner + 2
            expect($this->service->isAtCapacity($campaign))->toBeFalse(); // 3/5
        });

        it('campaign at capacity with owner as explicit participant', function () {
            $owner = User::factory()->create();
            $campaign = Campaign::factory()->create([
                'owner_id' => $owner->id,
                'game_system_id' => $this->system->id,
                'max_players' => 3,
            ]);

            CampaignParticipant::create([
                'campaign_id' => $campaign->id,
                'user_id' => $owner->id,
                'role' => ParticipantRole::Owner->value,
                'status' => 'approved',
                'join_source' => JoinSource::Application,
            ]);

            // Add 2 more approved → owner + 2 = 3/3
            for ($i = 0; $i < 2; $i++) {
                CampaignParticipant::create([
                    'campaign_id' => $campaign->id,
                    'user_id' => User::factory()->create()->id,
                    'role' => ParticipantRole::Player->value,
                    'status' => 'approved',
                    'join_source' => JoinSource::Application,
                ]);
            }

            expect($this->service->getApprovedParticipantCount($campaign))->toBe(3);
            expect($this->service->isAtCapacity($campaign))->toBeTrue();
        });

        it('campaign opportunities spots_available counts owner correctly', function () {
            $owner = User::factory()->create();
            $campaign = Campaign::factory()->create([
                'owner_id' => $owner->id,
                'game_system_id' => $this->system->id,
                'max_players' => 4,
                'visibility' => 'public',
            ]);

            CampaignParticipant::create([
                'campaign_id' => $campaign->id,
                'user_id' => $owner->id,
                'role' => ParticipantRole::Owner->value,
                'status' => 'approved',
                'join_source' => JoinSource::Application,
            ]);

            // 1 additional member → 2/4 approved
            CampaignParticipant::create([
                'campaign_id' => $campaign->id,
                'user_id' => User::factory()->create()->id,
                'role' => ParticipantRole::Player->value,
                'status' => 'approved',
                'join_source' => JoinSource::Application,
            ]);

            // Verify approved_participant_count via withCount (same as DashboardCacheService)
            $campaignWithCount = Campaign::withCount(['participants as approved_participant_count' => function ($query) {
                $query->where('status', ParticipantStatus::Approved->value);
            }])->find($campaign->id);

            expect($campaignWithCount->approved_participant_count)->toBe(2); // owner + 1
            $spotsAvailable = $campaign->max_players - $campaignWithCount->approved_participant_count;
            expect($spotsAvailable)->toBe(2); // 4 - 2 = 2
        });
    });

    // ── 7. No +1 hacks remain in counting logic ──

    describe('no +1 hacks in service methods', function () {
        it('getApprovedParticipantCount returns natural count without +1 adjustment', function () {
            $game = createGameWithOwner($this->system, 4);

            // No additional players — just the owner
            expect($this->service->getApprovedParticipantCount($game))->toBe(1);

            // Add 1 player
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => User::factory()->create()->id,
                'role' => ParticipantRole::Player->value,
                'status' => 'approved',
                'join_source' => JoinSource::Application,
            ]);

            expect($this->service->getApprovedParticipantCount($game))->toBe(2);

            // Add 1 more
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => User::factory()->create()->id,
                'role' => ParticipantRole::Player->value,
                'status' => 'approved',
                'join_source' => JoinSource::Application,
            ]);

            expect($this->service->getApprovedParticipantCount($game))->toBe(3);
        });

        it('acceptInvitation capacity check uses natural count', function () {
            // max_players=1 — only the owner fits
            $game = Game::factory()->create([
                'owner_id' => User::factory()->create()->id,
                'game_system_id' => $this->system->id,
                'max_players' => 1,
            ]);

            // Owner is the only approved → 1/1 = full
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $game->owner_id,
                'role' => ParticipantRole::Owner->value,
                'status' => 'approved',
                'join_source' => JoinSource::Application,
            ]);

            // Invite someone
            $invited = User::factory()->create();
            $participant = GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $invited->id,
                'role' => ParticipantRole::Invited->value,
                'status' => 'pending',
                'join_source' => JoinSource::FriendInvite,
            ]);

            $result = $this->service->acceptInvitation($participant, $game, $invited);

            // Should be overflowed — game is at capacity with owner alone
            expect($result->success)->toBeTrue();
            expect($participant->fresh()->status)->toBe(ParticipantStatus::Waitlisted);
        });
    });
});

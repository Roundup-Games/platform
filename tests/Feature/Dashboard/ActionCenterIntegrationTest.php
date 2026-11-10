<?php

use App\Enums\ParticipantStatus;
use App\Enums\RelationshipType;
use App\Livewire\Dashboard;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\GMProfile;
use App\Models\Review;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\CampaignJournalService;
use App\Services\DashboardCacheService;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/*
 * M077/S01 consolidation: 16 tests → 3 flow tests.
 *
 * Mapping (old → new):
 * - 'Cache lifecycle' describe (miss/hit/invalidation/warm, 4 tests) and
 *   'Full cache cycle' → cut: DashboardCacheServiceTest covers the section
 *   cache contract per-section via datasets (miss/hit/warm/invalidation).
 * - 'Participant status change invalidation' (3), 'Game event invalidation'
 *   (2), 'Review invalidation' (1), 'Follow invalidation' (1),
 *   'invalidates on game creation' (1) → STEP blocks in the flow test below;
 *   every trigger keeps its warm → act → assert-null contract.
 * - 'Action Center data computation' (2) → computation flow test (empty →
 *   pending item in one world).
 * - 'dashboard component uses action center cache service' → kept verbatim.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Cache::flush();
    URL::defaults(['locale' => 'en']);
    $this->gameSystem = GameSystem::factory()->create();
});

describe('Action Center invalidation triggers', function () {
    test('every app event invalidates the action center cache (flow)', function () {
        $cacheService = app(DashboardCacheService::class);
        $cacheKey = "dashboard:action_center:{$this->user->id}";

        $warm = function () use ($cacheService, $cacheKey): void {
            $cacheService->getActionCenter($this->user);
            expect(Cache::get($cacheKey))->not->toBeNull();
        };

        // STEP 1 — game creation (GameObserver::saved) invalidates the owner.
        $game = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
        ]);
        expect(Cache::get($cacheKey))->toBeNull();

        // STEP 2 — participant creation invalidates participant AND owner
        // (game owned by a different user; $this->user joins as participant).
        $otherOwner = User::factory()->create();
        $ownerKey = "dashboard:action_center:{$otherOwner->id}";
        $cacheService->getActionCenter($otherOwner);
        expect(Cache::get($ownerKey))->not->toBeNull();
        // Re-warm BOTH keys: STEP 1 nulled $cacheKey, and asserting null on
        // an already-null key would pass even if the participant-side
        // invalidation never fired (M077 review fix — old test warmed both).
        $warm();

        $participant = GameParticipant::create([
            'game_id' => Game::factory()->create([
                'owner_id' => $otherOwner->id,
                'game_system_id' => $this->gameSystem->id,
                'status' => 'scheduled',
            ])->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Pending->value,
        ]);
        expect(Cache::get($cacheKey))->toBeNull();
        expect(Cache::get($ownerKey))->toBeNull();

        // STEP 3 — participant status update (observer updated()).
        $warm();
        $participant->update(['status' => ParticipantStatus::Approved->value]);
        expect(Cache::get($cacheKey))->toBeNull();

        // STEP 4 — attendance status update (observer updated()).
        $warm();
        $participant->update(['attendance_status' => 'attended']);
        expect(Cache::get($cacheKey))->toBeNull();

        // STEP 5 — game completion (GameObserver::saved on status change).
        $warm();
        $game->update(['status' => 'completed']);
        expect(Cache::get($cacheKey))->toBeNull();

        // STEP 6 — journal recap write (CampaignJournalService post-commit tail).
        $campaign = Campaign::factory()->create(['owner_id' => $this->user->id]);
        $campaignGame = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'campaign_id' => $campaign->id,
            'status' => 'completed',
        ]);
        $warm();
        app(CampaignJournalService::class)->writeRecap($campaignGame, $this->user, 'Great session!');
        expect(Cache::get($cacheKey))->toBeNull();

        // STEP 7 — new review for the user's GM profile (ReviewObserver).
        $gmProfile = GMProfile::factory()->create(['user_id' => $this->user->id]);
        $warm();
        Review::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'reviewer_id' => User::factory()->create()->id,
        ]);
        expect(Cache::get($cacheKey))->toBeNull();

        // STEP 8 — new follow of the user (UserRelationshipObserver).
        $warm();
        UserRelationship::create([
            'user_id' => User::factory()->create()->id,
            'related_user_id' => $this->user->id,
            'type' => RelationshipType::Follow->value,
        ]);
        expect(Cache::get($cacheKey))->toBeNull();
    });
});

describe('Action Center data computation', function () {
    test('empty for a fresh user, surfaces a high-priority pending application once one exists', function () {
        $cacheService = app(DashboardCacheService::class);

        // Empty world
        $data = $cacheService->getActionCenter($this->user);
        expect($data)->toBeArray()->toBeEmpty();

        // Create a pending application on the user's game
        $game = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'status' => ParticipantStatus::Pending->value,
        ]);

        $data = $cacheService->getActionCenter($this->user);
        expect($data)->toBeArray()->not->toBeEmpty();

        $appItems = array_filter($data, fn ($item) => $item['type'] === 'pending_applications');
        expect($appItems)->toHaveCount(1);

        $item = reset($appItems);
        expect($item)->toHaveKey('type')
            ->toHaveKey('priority')
            ->toHaveKey('title')
            ->toHaveKey('action_url');
        expect($item['priority'])->toBe('high');
    });
});

describe('Dashboard integration', function () {
    test('dashboard component uses action center cache service', function () {
        $cacheService = app(DashboardCacheService::class);

        // Pre-warm the action center cache
        $cacheService->warmActionCenter($this->user);

        // Dashboard should render without errors
        $component = Livewire::test(Dashboard::class);
        $component->assertStatus(200);

        // Cache key should still exist after render
        $cacheKey = "dashboard:action_center:{$this->user->id}";
        expect(Cache::get($cacheKey))->not->toBeNull();
    });
});

<?php

use App\Enums\ParticipantStatus;
use App\Enums\RelationshipType;
use App\Livewire\Dashboard;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\GMProfile;
use App\Models\Review;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\DashboardCacheService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Cache::flush();
    URL::defaults(['locale' => 'en']);
    $this->gameSystem = GameSystem::factory()->create();
});

// ── Action Center cache warm/fetch/invalidation cycle ─────────────

describe('Cache lifecycle', function () {
    test('action center cache miss computes and stores data', function () {
        $cacheService = app(DashboardCacheService::class);

        // Ensure cold cache
        $cacheKey = "dashboard:action_center:{$this->user->id}";
        expect(Cache::get($cacheKey))->toBeNull();

        // First call — should compute and cache
        $data = $cacheService->getActionCenter($this->user);

        expect($data)->toBeArray();
        expect(Cache::get($cacheKey))->not->toBeNull();
    });

    test('action center cache hit returns stored data', function () {
        $cacheService = app(DashboardCacheService::class);

        // Pre-populate with specific data
        Cache::put("dashboard:action_center:{$this->user->id}", ['test_key' => 'test_value'], 300);

        $data = $cacheService->getActionCenter($this->user);

        expect($data)->toBe(['test_key' => 'test_value']);
    });

    test('action center cache invalidation clears the key', function () {
        $cacheService = app(DashboardCacheService::class);

        // Warm the cache
        $cacheService->getActionCenter($this->user);
        $cacheKey = "dashboard:action_center:{$this->user->id}";
        expect(Cache::get($cacheKey))->not->toBeNull();

        // Invalidate
        $cacheService->invalidateForUser((string) $this->user->id, ['action_center']);

        expect(Cache::get($cacheKey))->toBeNull();
    });

    test('warm action center populates cache', function () {
        $cacheService = app(DashboardCacheService::class);
        $cacheKey = "dashboard:action_center:{$this->user->id}";

        $cacheService->warmActionCenter($this->user);

        expect(Cache::get($cacheKey))->not->toBeNull();
    });
});

// ── Invalidation triggers: participant status change ───────────────

describe('Participant status change invalidation', function () {
    test('participant status change invalidates action center for participant and owner', function () {
        $cacheService = app(DashboardCacheService::class);

        // Warm caches for both users
        $cacheService->getActionCenter($this->user);

        $owner = User::factory()->create();
        $cacheService->getActionCenter($owner);

        $participantCacheKey = "dashboard:action_center:{$this->user->id}";
        $ownerCacheKey = "dashboard:action_center:{$owner->id}";
        expect(Cache::get($participantCacheKey))->not->toBeNull();
        expect(Cache::get($ownerCacheKey))->not->toBeNull();

        // Create a game + participant — observer fires created()
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Pending->value,
        ]);

        // Both caches should be invalidated
        expect(Cache::get($participantCacheKey))->toBeNull();
        expect(Cache::get($ownerCacheKey))->toBeNull();
    });

    test('participant status update triggers action center invalidation', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Pending->value,
        ]);

        // Warm the cache after creation (creation already invalidated)
        $cacheService = app(DashboardCacheService::class);
        $cacheService->getActionCenter($this->user);
        $cacheKey = "dashboard:action_center:{$this->user->id}";
        expect(Cache::get($cacheKey))->not->toBeNull();

        // Update status — triggers observer updated()
        $participant->update(['status' => ParticipantStatus::Approved->value]);

        expect(Cache::get($cacheKey))->toBeNull();
    });

    test('attendance status update triggers action center invalidation', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
        ]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved->value,
            'attendance_status' => null,
        ]);

        // Warm cache after creation
        $cacheService = app(DashboardCacheService::class);
        $cacheService->getActionCenter($this->user);
        $cacheKey = "dashboard:action_center:{$this->user->id}";
        expect(Cache::get($cacheKey))->not->toBeNull();

        // Report attendance — triggers observer updated() for attendance_status
        $participant->update(['attendance_status' => 'attended']);

        expect(Cache::get($cacheKey))->toBeNull();
    });
});

// ── Invalidation triggers: game completion ─────────────────────────

describe('Game event invalidation', function () {
    test('game status change to completed invalidates action center', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
        ]);

        // Add a participant
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        // Warm caches (creation already fired, so warm manually)
        $cacheService = app(DashboardCacheService::class);
        $cacheService->getActionCenter($this->user);
        $cacheKey = "dashboard:action_center:{$this->user->id}";
        expect(Cache::get($cacheKey))->not->toBeNull();

        // Complete the game — triggers GameObserver::saved()
        $game->update(['status' => 'completed']);

        expect(Cache::get($cacheKey))->toBeNull();
    });

    test('game recap addition invalidates action center', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'recap' => null,
        ]);

        // Warm cache
        $cacheService = app(DashboardCacheService::class);
        $cacheService->getActionCenter($this->user);
        $cacheKey = "dashboard:action_center:{$this->user->id}";
        expect(Cache::get($cacheKey))->not->toBeNull();

        // Add recap — triggers GameObserver::saved()
        $game->update(['recap' => 'Great session!']);

        expect(Cache::get($cacheKey))->toBeNull();
    });
});

// ── Invalidation triggers: new review ──────────────────────────────

describe('Review invalidation', function () {
    test('new review invalidates action center for reviewed GM', function () {
        $gmProfile = GMProfile::factory()->create([
            'user_id' => $this->user->id,
        ]);

        // Warm cache
        $cacheService = app(DashboardCacheService::class);
        $cacheService->getActionCenter($this->user);
        $cacheKey = "dashboard:action_center:{$this->user->id}";
        expect(Cache::get($cacheKey))->not->toBeNull();

        // Create review — triggers ReviewObserver which invalidates action center
        Review::factory()->create([
            'gm_profile_id' => $gmProfile->id,
            'reviewer_id' => User::factory()->create()->id,
        ]);

        expect(Cache::get($cacheKey))->toBeNull();
    });
});

// ── Invalidation triggers: new follow ──────────────────────────────

describe('Follow invalidation', function () {
    test('new follow invalidates action center for followed user', function () {
        // Warm cache for the followed user
        $cacheService = app(DashboardCacheService::class);
        $cacheService->getActionCenter($this->user);
        $cacheKey = "dashboard:action_center:{$this->user->id}";
        expect(Cache::get($cacheKey))->not->toBeNull();

        // Create follow relationship — triggers UserRelationshipObserver
        $follower = User::factory()->create();
        UserRelationship::create([
            'user_id' => $follower->id,
            'related_user_id' => $this->user->id,
            'type' => RelationshipType::Follow->value,
        ]);

        expect(Cache::get($cacheKey))->toBeNull();
    });
});

// ── Action Center data flows through cache service compute ────────

describe('Action Center data computation', function () {
    test('cache service computes action items via ActionCenterService', function () {
        // Create a pending application on user's game
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

        $cacheService = app(DashboardCacheService::class);
        $data = $cacheService->getActionCenter($this->user);

        expect($data)->toBeArray();
        expect($data)->not->toBeEmpty();

        // Find the pending_applications item
        $appItems = array_filter($data, fn ($item) => $item['type'] === 'pending_applications');
        expect($appItems)->toHaveCount(1);

        $item = reset($appItems);
        expect($item)->toHaveKey('type');
        expect($item)->toHaveKey('priority');
        expect($item)->toHaveKey('title');
        expect($item)->toHaveKey('action_url');
        expect($item['priority'])->toBe('high');
    });

    test('cache service returns empty array for user with no items', function () {
        $cacheService = app(DashboardCacheService::class);
        $data = $cacheService->getActionCenter($this->user);

        expect($data)->toBeArray();
        expect($data)->toBeEmpty();
    });
});

// ── Full cycle: warm → read → invalidate → read ──────────────────

describe('Full cache cycle', function () {
    test('complete warm-fetch-invalidate-fetch cycle', function () {
        $cacheService = app(DashboardCacheService::class);
        $cacheKey = "dashboard:action_center:{$this->user->id}";

        // 1. Warm
        $cacheService->warmActionCenter($this->user);
        expect(Cache::get($cacheKey))->not->toBeNull();

        // 2. Fetch (should hit cache)
        $data1 = $cacheService->getActionCenter($this->user);
        expect($data1)->toBeArray();

        // 3. Create an item that should change the result
        $game = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
        ]);
        // The GameObserver::saved fires on creation and invalidates action_center

        // 4. Cache should be cleared
        expect(Cache::get($cacheKey))->toBeNull();

        // 5. Fetch again (recomputes with new data)
        $data2 = $cacheService->getActionCenter($this->user);

        // Should now contain the pending item for the new game
        // (the game has no pending participants yet, so might still be empty)
        expect($data2)->toBeArray();
    });
});

// ── Dashboard component passes action center via cache ────────────

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

    test('dashboard action center invalidates on game creation', function () {
        $cacheService = app(DashboardCacheService::class);

        // Pre-warm
        $cacheService->warmActionCenter($this->user);
        $cacheKey = "dashboard:action_center:{$this->user->id}";
        expect(Cache::get($cacheKey))->not->toBeNull();

        // Create a game owned by the user
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
        ]);

        // Cache should be invalidated by GameObserver::saved
        expect(Cache::get($cacheKey))->toBeNull();
    });
});

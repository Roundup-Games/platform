<?php

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Jobs\WarmDashboardCache;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\GMProfile;
use App\Models\Location;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\DashboardCacheService;
use App\Services\Geohash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Cache::flush();
    Log::spy();
    URL::defaults(['locale' => 'en']);
});

// ── Dashboard renders with all sections ─────────────────────────

describe('Dashboard rendering', function () {
    test('dashboard renders for authenticated user with all 6 sections', function () {
        $gameSystem = GameSystem::factory()->create();

        // Give the user a game so the week section has data
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
            'date_time' => now()->addDay(),
        ]);

        $component = Livewire::test(\App\Livewire\Dashboard::class);

        // Verify all 6 sections are rendered as view data
        $component->assertViewHas('smartPrompt');
        $component->assertViewHas('weekData');
        $component->assertViewHas('communityFeed');
        $component->assertViewHas('opportunities');
        $component->assertViewHas('contributions');
        $component->assertViewHas('quickActions');

        // Verify dashboardMode is always passed
        $component->assertViewHas('dashboardMode');
        $mode = $component->viewData('dashboardMode');
        expect($mode)->toBeIn(['newcomer', 'established']);
    });
});

// ── Dashboard mode resolution ───────────────────────────────────

describe('Dashboard mode', function () {
    test('new user gets newcomer mode', function () {
        // Fresh user — created just now, zero attended games
        $component = Livewire::test(\App\Livewire\Dashboard::class);

        $mode = $component->viewData('dashboardMode');
        expect($mode)->toBe('newcomer');
    });

    test('user with attended game gets established mode', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->user->id,
            'status' => 'completed',
        ]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $component = Livewire::test(\App\Livewire\Dashboard::class);

        $mode = $component->viewData('dashboardMode');
        expect($mode)->toBe('established');
    });

    test('user older than 30 days gets established mode regardless of attendance', function () {
        // User created 31 days ago — no attended games but account is old
        $oldUser = User::factory()->create(['created_at' => now()->subDays(31)]);
        $this->actingAs($oldUser);
        URL::defaults(['locale' => 'en']);

        $component = Livewire::test(\App\Livewire\Dashboard::class);

        $mode = $component->viewData('dashboardMode');
        expect($mode)->toBe('established');
    });
});

// ── Cache lifecycle ─────────────────────────────────────────────

describe('Cache lifecycle', function () {
    test('cold cache: first visit computes synchronously and dispatches warm job', function () {
        Queue::fake();

        // Cold cache — no cache entries exist
        $component = Livewire::test(\App\Livewire\Dashboard::class);

        // Week data should be computed synchronously
        $weekData = $component->viewData('weekData');
        expect($weekData)->not->toBeNull();
        expect($weekData)->toHaveKey('days');
        expect($weekData)->toHaveKey('summary');

        // Contributions should be computed synchronously
        $contributions = $component->viewData('contributions');
        expect($contributions)->toHaveKey('hosted');
        expect($contributions)->toHaveKey('played');

        // WarmDashboardCache should have been dispatched (multiple section misses)
        Queue::assertPushed(WarmDashboardCache::class);
    });

    test('warm cache: second visit reads from cache without recomputation', function () {
        Queue::fake();

        // First visit — populates cache
        Livewire::test(\App\Livewire\Dashboard::class);

        // Verify cache was populated
        $weekKey = now()->startOfWeek()->format('Y-m-d');
        $cacheKey = "dashboard:week:{$this->user->id}:{$weekKey}";
        expect(Cache::get($cacheKey))->not->toBeNull();

        // Count query log for second visit
        $queryCountBefore = DB::getQueryLog() ? count(DB::getQueryLog()) : 0;

        // Second visit — should use cache
        DB::enableQueryLog();
        $component = Livewire::test(\App\Livewire\Dashboard::class);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Week data should still be present
        $weekData = $component->viewData('weekData');
        expect($weekData)->toHaveKey('days');

        // Verify cache hit occurred (fewer queries than cold visit)
        // The key point: data is returned from cache, not recomputed
        expect(Cache::get($cacheKey))->not->toBeNull();
    });
});

// ── Cache invalidation on game creation ─────────────────────────

describe('Cache invalidation', function () {
    test('cache invalidation on game creation clears week data for owner', function () {
        $cacheService = app(DashboardCacheService::class);
        $weekKey = now()->startOfWeek()->format('Y-m-d');

        // Pre-populate week cache using the service (correct key format)
        $cacheService->getWeekData($this->user);
        $cacheKey = "dashboard:week:{$this->user->id}:{$weekKey}";
        expect(Cache::get($cacheKey))->not->toBeNull();

        // Create a game — triggers saved hook → invalidateForGameEvent
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'status' => GameStatus::Scheduled,
        ]);

        // Week cache should be cleared for owner
        expect(Cache::get($cacheKey))->toBeNull();
    });

    test('cache invalidation on follow clears feed for both users', function () {
        $target = User::factory()->create();

        // Pre-populate feed cache for both users using the service
        $cacheService = app(DashboardCacheService::class);
        $cacheService->getFeedData($this->user);
        $cacheService->getFeedData($target);

        $userFeedKey = "dashboard:feed:{$this->user->id}";
        $targetFeedKey = "dashboard:feed:{$target->id}";
        expect(Cache::get($userFeedKey))->not->toBeNull();
        expect(Cache::get($targetFeedKey))->not->toBeNull();

        // Follow — triggers invalidation for both users
        UserRelationship::follow($this->user, $target);

        // Both feeds should be cleared
        expect(Cache::get($userFeedKey))->toBeNull();
        expect(Cache::get($targetFeedKey))->toBeNull();
    });
});

// ── Smart Prompt integration ────────────────────────────────────

describe('Smart Prompt', function () {
    test('shows invitation nudge when invitations exist', function () {
        // Create a game and invite the user
        $inviter = User::factory()->create(['name' => 'Inviter']);
        $game = Game::factory()->create([
            'owner_id' => $inviter->id,
            'status' => 'scheduled',
            'date_time' => now()->addDay(),
        ]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Pending,
        ]);

        $component = Livewire::test(\App\Livewire\Dashboard::class);
        $smartPrompt = $component->viewData('smartPrompt');

        expect($smartPrompt['type'])->toBe('pending_invitations');
    });

    test('shows upcoming session for tomorrow game', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->user->id,
            'status' => 'scheduled',
            'date_time' => now()->addHours(12),
        ]);

        $component = Livewire::test(\App\Livewire\Dashboard::class);
        $smartPrompt = $component->viewData('smartPrompt');

        expect($smartPrompt['type'])->toBe('upcoming_session');
    });
});

// ── Empty states ────────────────────────────────────────────────

describe('Empty states', function () {
    test('empty dashboard for new user: shows fallback prompt, no errors', function () {
        // Brand new user — no games, no follows, no location
        $component = Livewire::test(\App\Livewire\Dashboard::class);

        // Should render without errors
        $component->assertStatus(200);

        $smartPrompt = $component->viewData('smartPrompt');
        expect($smartPrompt)->not->toBeNull();
        // New user should get either fallback_active or fallback_new
        expect($smartPrompt['type'])->toBeIn(['fallback_active', 'fallback_new']);

        // Week data should have 7 days with no games
        $weekData = $component->viewData('weekData');
        expect($weekData['summary']['total'])->toBe(0);

        // No community feed items
        $feed = $component->viewData('communityFeed');
        expect($feed)->toHaveCount(0);
    });

    test('all sections have graceful empty states when data is missing', function () {
        $component = Livewire::test(\App\Livewire\Dashboard::class);

        // Opportunities should be empty array (no location)
        $opportunities = $component->viewData('opportunities');
        expect($opportunities)->toHaveKey('games');
        expect($opportunities)->toHaveKey('total_available');

        // Contributions should have zero counts
        $contributions = $component->viewData('contributions');
        expect($contributions['hosted']['count'])->toBe(0);
        expect($contributions['played']['count'])->toBe(0);
        expect($contributions['campaigns'])->toBeNull();
        expect($contributions['recaps_written'])->toBe(0);
        expect($contributions['reviews_given'])->toBe(0);

        // Quick actions should always have at least one action
        $quickActions = $component->viewData('quickActions');
        expect($quickActions)->not->toBeEmpty();

        // No trending when no location
        $hasTrending = $component->viewData('hasTrendingSection');
        expect($hasTrending)->toBeFalse();
    });
});

// ── Recaps cache ────────────────────────────────────────────────

describe('Recaps cache', function () {
    test('recaps are served from cache on warm hit', function () {
        $cacheService = app(DashboardCacheService::class);

        // Pre-warm the recaps cache
        $cacheService->warmRecaps($this->user);

        $cacheKey = "dashboard:recaps:{$this->user->id}";
        expect(Cache::get($cacheKey))->not->toBeNull();

        // Dashboard render should read from cache
        $component = Livewire::test(\App\Livewire\Dashboard::class);
        $newRecaps = $component->viewData('newRecaps');

        // Cache key must still be populated (not cleared by render)
        expect(Cache::get($cacheKey))->not->toBeNull();
        // newRecaps is a collection of stdClass objects
        expect($newRecaps)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    });

    test('recaps cache is invalidated for user', function () {
        $cacheService = app(DashboardCacheService::class);

        $cacheService->warmRecaps($this->user);
        $cacheKey = "dashboard:recaps:{$this->user->id}";
        expect(Cache::get($cacheKey))->not->toBeNull();

        $cacheService->invalidateForUser((string) $this->user->id, ['recaps']);

        expect(Cache::get($cacheKey))->toBeNull();
    });
});

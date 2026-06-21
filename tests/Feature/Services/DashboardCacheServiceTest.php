<?php

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Jobs\WarmDashboardCache;
use App\Jobs\WarmTrendingNearby;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Location;
use App\Models\User;
use App\Services\DashboardCacheService;
use App\Services\Geohash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
 * Tests the centralised cache layer for all dashboard sections.
 *
 * Each section follows the same three-tier pattern: cache hit → synchronous
 * fallback on miss → background warm. These tests collapse the 11 sections ×
 * 6 contracts matrix into parameterized Pest datasets, plus unique-behaviour
 * tests for invalidation wiring and cache-key tracking.
 *
 * Note: file is misfiled (Tests\Unit\Services namespace but lives in Feature/);
 * kept in Feature/ for transactional isolation, not moved per audit.
 */

beforeEach(function () {
    $this->service = app(DashboardCacheService::class);
    Cache::flush();
    Queue::fake();
    Log::spy();
});

/*
 * Single source of truth for per-section metadata. Each row drives every
 * parameterized test below. Nullable fields mark sections that don't fit
 * a given contract (e.g. week has no warmer; trending uses WarmTrendingNearby
 * and is invalidated via a separate method).
 */
$sections = function (): array {
    $weekKey = now()->startOfWeek()->format('Y-m-d');
    $geohash = 'u33d';

    return [
        'week' => [[
            'getter' => fn ($s, $u) => $s->getWeekData($u),
            'cacheKey' => fn ($u) => "dashboard:week:{$u->id}:{$weekKey}",
            'expectedKeys' => ['days', 'summary'],
            'sample' => ['days' => ['test'], 'summary' => ['total' => 1]],
            'triggerType' => 'cache_miss_week',
            'warmer' => null,
            'invalidateSection' => 'week',
            'populateForInvalidate' => function ($u) use ($weekKey) {
                Cache::put("dashboard:week:{$u->id}:{$weekKey}", ['data' => true], 300);
            },
        ]],
        'feed' => [[
            'getter' => fn ($s, $u) => $s->getFeedData($u),
            'cacheKey' => fn ($u) => "dashboard:feed:{$u->id}",
            'expectedKeys' => ['items', 'source'],
            'sample' => ['items' => ['test_item'], 'source' => 'friends', 'fetched_at' => now()->toISOString()],
            'triggerType' => 'cache_miss_feed',
            'warmer' => fn ($s, $u) => $s->warmFeed($u),
            'invalidateSection' => 'feed',
            'populateForInvalidate' => fn ($u) => Cache::put("dashboard:feed:{$u->id}", ['items' => []], 900),
        ]],
        'trending' => [[
            'getter' => fn ($s, $u) => $s->getTrendingNearby('u33d'),
            'cacheKey' => fn ($u) => "dashboard:trending:{$geohash}",
            'expectedKeys' => ['games'],
            'sample' => ['games' => [['id' => 'test']]],
            'triggerType' => null,
            'warmer' => null, // trending uses a separate warm contract (returns int)
            'invalidateSection' => null, // uses invalidateTrendingForGeohash
            'populateForInvalidate' => null,
        ]],
        'opportunities' => [[
            'getter' => fn ($s, $u) => $s->getOpportunities($u, $geohash),
            'cacheKey' => fn ($u) => "dashboard:opportunities:{$u->id}:{$geohash}",
            'expectedKeys' => ['games', 'total_available'],
            'sample' => ['games' => ['test'], 'total_available' => 7],
            'triggerType' => 'cache_miss_opportunities',
            'warmer' => fn ($s, $u) => $s->warmOpportunities($u, $geohash),
            'invalidateSection' => 'opportunities',
            'populateForInvalidate' => fn ($u) => app(DashboardCacheService::class)->warmOpportunities($u, 'u33d'),
        ]],
        'contributions' => [[
            'getter' => fn ($s, $u) => $s->getContributions($u),
            'cacheKey' => fn ($u) => "dashboard:contributions:{$u->id}",
            'expectedKeys' => ['hosted', 'played'],
            'sample' => ['hosted' => ['count' => 10, 'hours' => 0, 'unique_players' => 0], 'played' => ['count' => 20, 'system_count' => 0], 'campaigns' => null, 'recaps_written' => 0, 'reviews_given' => 0, 'followers' => 0],
            'triggerType' => 'cache_miss_contributions',
            'warmer' => fn ($s, $u) => $s->warmContributions($u),
            'invalidateSection' => 'contributions',
            'populateForInvalidate' => fn ($u) => Cache::put("dashboard:contributions:{$u->id}", ['data' => true], 3600),
        ]],
        'action_center' => [[
            'getter' => fn ($s, $u) => $s->getActionCenter($u),
            'cacheKey' => fn ($u) => "dashboard:action_center:{$u->id}",
            'expectedKeys' => [],
            'sample' => ['actions' => ['complete_profile', 'join_game']],
            'triggerType' => 'cache_miss_action_center',
            'warmer' => fn ($s, $u) => $s->warmActionCenter($u),
            'invalidateSection' => 'action_center',
            'populateForInvalidate' => fn ($u) => Cache::put("dashboard:action_center:{$u->id}", ['actions' => ['test']], 300),
        ]],
        'newcomer_welcome' => [[
            'getter' => fn ($s, $u) => $s->getNewcomerWelcome($u),
            'cacheKey' => fn ($u) => "dashboard:newcomer_welcome:{$u->id}",
            'expectedKeys' => ['first_name', 'welcome_message_key'],
            'sample' => ['message' => 'Welcome!'],
            'triggerType' => 'cache_miss_newcomer_welcome',
            'warmer' => fn ($s, $u) => $s->warmNewcomerWelcome($u),
            'invalidateSection' => 'newcomer_welcome',
            'populateForInvalidate' => fn ($u) => Cache::put("dashboard:newcomer_welcome:{$u->id}", ['msg' => 'hi'], 600),
        ]],
        'progress_tracker' => [[
            'getter' => fn ($s, $u) => $s->getProgressTracker($u),
            'cacheKey' => fn ($u) => "dashboard:progress_tracker:{$u->id}",
            'expectedKeys' => ['steps', 'current_step'],
            'sample' => ['steps' => [], 'current_step' => 0],
            'triggerType' => 'cache_miss_progress_tracker',
            'warmer' => fn ($s, $u) => $s->warmProgressTracker($u),
            'invalidateSection' => 'progress_tracker',
            'populateForInvalidate' => fn ($u) => Cache::put("dashboard:progress_tracker:{$u->id}", ['steps' => []], 300),
        ]],
        'nearby_people' => [[
            'getter' => fn ($s, $u) => $s->getNearbyPeople($u, $geohash),
            'cacheKey' => fn ($u) => "dashboard:nearby_people:{$u->id}:{$geohash}",
            'expectedKeys' => ['people', 'total_nearby'],
            'sample' => ['people' => [['name' => 'Alice']]],
            'triggerType' => 'cache_miss_nearby_people',
            'warmer' => fn ($s, $u) => $s->warmNearbyPeople($u, $geohash),
            'invalidateSection' => 'nearby_people',
            'populateForInvalidate' => fn ($u) => app(DashboardCacheService::class)->warmNearbyPeople($u, 'u33d'),
        ]],
        'host_again' => [[
            'getter' => fn ($s, $u) => $s->getHostAgain($u),
            'cacheKey' => fn ($u) => "dashboard:host_again:{$u->id}",
            'expectedKeys' => [],
            'sample' => ['recent_game_ids' => [1, 2]],
            'triggerType' => 'cache_miss_host_again',
            'warmer' => fn ($s, $u) => $s->warmHostAgain($u),
            'invalidateSection' => 'host_again',
            'populateForInvalidate' => fn ($u) => Cache::put("dashboard:host_again:{$u->id}", ['games' => [1]], 600),
        ]],
        'milestone_cards' => [[
            'getter' => fn ($s, $u) => $s->getMilestoneCards($u),
            'cacheKey' => fn ($u) => "dashboard:milestone_cards:{$u->id}",
            'expectedKeys' => [],
            'sample' => ['milestones' => ['hosted_10']],
            'triggerType' => 'cache_miss_milestone_cards',
            'warmer' => fn ($s, $u) => $s->warmMilestoneCards($u),
            'invalidateSection' => 'milestone_cards',
            'populateForInvalidate' => fn ($u) => Cache::put("dashboard:milestone_cards:{$u->id}", ['milestones' => []], 3600),
        ]],
    ];
};

it('returns real data on cache miss', function (array $section) {
    $user = User::factory()->create();

    $result = $section['getter']($this->service, $user);

    expect($result)->toBeArray();
    foreach ($section['expectedKeys'] as $key) {
        expect($result)->toHaveKey($key);
    }
})->with($sections);

it('stores result in cache on miss', function (array $section) {
    // Trending uses async warm (WarmTrendingNearby) and returns a default
    // rather than storing synchronously — covered by the dispatch test below.
    if ($section['triggerType'] === null) {
        $this->markTestSkipped('trending uses async warm instead of synchronous store');
    }

    $user = User::factory()->create();

    $section['getter']($this->service, $user);

    expect(Cache::get(($section['cacheKey'])($user)))->not->toBeNull();
})->with($sections);

it('returns cached data on hit and skips warm dispatch', function (array $section) {
    $user = User::factory()->create();
    Cache::put(($section['cacheKey'])($user), $section['sample'], 600);

    $result = $section['getter']($this->service, $user);

    expect($result)->toBe($section['sample']);
    if ($section['triggerType'] !== null) {
        // Sections that use WarmDashboardCache should not re-dispatch on hit.
        Queue::assertNotPushed(WarmDashboardCache::class);
    }
})->with($sections);

it('dispatches warm job on cache miss', function (array $section) {
    $user = User::factory()->create();

    $section['getter']($this->service, $user);

    if ($section['triggerType'] === null) {
        // Trending dispatches WarmTrendingNearby instead.
        Queue::assertPushed(WarmTrendingNearby::class);

        return;
    }

    Queue::assertPushed(WarmDashboardCache::class, function ($job) use ($user, $section) {
        return $job->userId === (string) $user->id
            && $job->triggerType === $section['triggerType'];
    });
})->with($sections);

it('invalidates the section cache via invalidateForUser', function (array $section) {
    if ($section['invalidateSection'] === null) {
        $this->markTestSkipped('trending is invalidated via invalidateTrendingForGeohash');
    }

    $user = User::factory()->create();
    ($section['populateForInvalidate'])($user);
    $key = ($section['cacheKey'])($user);
    expect(Cache::get($key))->not->toBeNull();

    $this->service->invalidateForUser((string) $user->id, [$section['invalidateSection']]);

    expect(Cache::get($key))->toBeNull();
})->with($sections);

it('warm method stores data in cache and returns what it stores', function (array $section) {
    if ($section['warmer'] === null) {
        $this->markTestSkipped('section has no warmer (week) or uses separate contract (trending)');
    }

    $user = User::factory()->create();

    $result = ($section['warmer'])($this->service, $user);

    expect($result)->toBeArray();
    $cached = Cache::get(($section['cacheKey'])($user));
    expect($cached)->not->toBeNull()->toBe($result);
})->with($sections);

// ── Trending has a distinct warm contract (returns game count) ───────

it('warm_trending_nearby returns game count and stores empty list for remote tile', function () {
    $emptyTile = 'kddd'; // Antarctica — no other test populates this

    $result = $this->service->warmTrendingNearby($emptyTile);

    expect($result)->toBe(0);
    $cached = Cache::get("dashboard:trending:{$emptyTile}");
    expect($cached)->not->toBeNull()
        ->and($cached['games'])->toHaveCount(0);
});

// ── Invalidation wiring unique to specific entry points ──────────────

it('invalidates every section by default when no section list is passed', function () {
    $user = User::factory()->create();
    $weekKey = now()->startOfWeek()->format('Y-m-d');

    Cache::put("dashboard:week:{$user->id}:{$weekKey}", ['data' => true], 300);
    Cache::put("dashboard:feed:{$user->id}", ['data' => true], 900);
    Cache::put("dashboard:contributions:{$user->id}", ['data' => true], 3600);

    $this->service->invalidateForUser((string) $user->id);

    expect(Cache::get("dashboard:week:{$user->id}:{$weekKey}"))->toBeNull()
        ->and(Cache::get("dashboard:feed:{$user->id}"))->toBeNull()
        ->and(Cache::get("dashboard:contributions:{$user->id}"))->toBeNull();
});

it('invalidate_trending_for_geohash clears the trending cache key', function () {
    Cache::put('dashboard:trending:u33d', ['games' => []], 600);

    $this->service->invalidateTrendingForGeohash('u33d');

    expect(Cache::get('dashboard:trending:u33d'))->toBeNull();
});

it('invalidate_for_game_event clears week cache for the owner', function () {
    $owner = User::factory()->create();
    $game = Game::factory()->create(['owner_id' => $owner->id, 'status' => GameStatus::Scheduled]);
    $weekKey = now()->startOfWeek()->format('Y-m-d');
    $ownerWeekKey = "dashboard:week:{$owner->id}:{$weekKey}";
    Cache::put($ownerWeekKey, ['data' => true], 300);

    $this->service->invalidateForGameEvent($game, 'updated');

    expect(Cache::get($ownerWeekKey))->toBeNull();
});

it('invalidate_for_game_event clears week cache for approved participants', function () {
    $owner = User::factory()->create();
    $player = User::factory()->create();
    $game = Game::factory()->create(['owner_id' => $owner->id, 'status' => GameStatus::Scheduled]);
    GameParticipant::factory()->create([
        'game_id' => $game->id,
        'user_id' => $player->id,
        'status' => ParticipantStatus::Approved,
    ]);
    $weekKey = now()->startOfWeek()->format('Y-m-d');
    $playerWeekKey = "dashboard:week:{$player->id}:{$weekKey}";
    Cache::put($playerWeekKey, ['data' => true], 300);

    $this->service->invalidateForGameEvent($game, 'updated');

    expect(Cache::get($playerWeekKey))->toBeNull();
});

it('invalidate_for_game_event clears trending for the game location geohash', function () {
    $location = Location::factory()->create(['latitude' => 52.5163, 'longitude' => 13.3777]);
    $geohash4 = Geohash::tilePrefix(52.5163, 13.3777, 4);
    $owner = User::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        'location_id' => $location->id,
        'status' => GameStatus::Scheduled,
    ]);
    Cache::put("dashboard:trending:{$geohash4}", ['games' => []], 600);

    $this->service->invalidateForGameEvent($game, 'updated');

    expect(Cache::get("dashboard:trending:{$geohash4}"))->toBeNull();
});

it('invalidate_for_game_event skips trending when the game has no location', function () {
    $owner = User::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        'status' => GameStatus::Scheduled,
        'location_id' => null,
    ]);
    Cache::put('dashboard:trending:u33d', ['games' => ['unrelated']], 600);

    $this->service->invalidateForGameEvent($game, 'updated');

    expect(Cache::get('dashboard:trending:u33d'))->not->toBeNull();
});

// ── Cache-key tracking unique to opportunities (geohash-keyed sections) ──

it('week cache key is namespaced by the start-of-week date', function () {
    $user = User::factory()->create();

    $this->service->getWeekData($user);

    $weekKey = now()->startOfWeek()->format('Y-m-d');
    expect(Cache::get("dashboard:week:{$user->id}:{$weekKey}"))->not->toBeNull()
        ->and(Cache::get("dashboard:week:{$user->id}:2099-12-31"))->toBeNull();
});

it('opportunities tracks multiple geohash keys for invalidation', function () {
    $user = User::factory()->create();

    $this->service->warmOpportunities($user, 'u33d');
    $this->service->warmOpportunities($user, 'u33e');

    $trackedKeys = Cache::get("dashboard:opportunities:keys:{$user->id}");
    expect($trackedKeys)->toHaveCount(2)
        ->and($trackedKeys)->toContain("dashboard:opportunities:{$user->id}:u33d")
        ->and($trackedKeys)->toContain("dashboard:opportunities:{$user->id}:u33e");
});

it('opportunities does not duplicate tracked keys on repeated warm', function () {
    $user = User::factory()->create();

    $this->service->warmOpportunities($user, 'u33d');
    $this->service->warmOpportunities($user, 'u33d');

    expect(Cache::get("dashboard:opportunities:keys:{$user->id}"))->toHaveCount(1);
});

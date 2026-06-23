<?php

namespace App\Services;

use App\Dto\ActionItem;
use App\Dto\ActivityFeedItem;
use App\Dto\FeedItem;
use App\Enums\CampaignStatus;
use App\Enums\DashboardSection;
use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Enums\RelationshipType;
use App\Jobs\WarmDashboardCache;
use App\Jobs\WarmTrendingNearby;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Review;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Centralised cache layer for all dashboard sections.
 *
 * Each section follows the same three-tier pattern:
 *   1. Cache::get() — fast path for cached data.
 *   2. Synchronous fallback — compute on cache miss so the user sees data immediately.
 *   3. Background warm — dispatch WarmDashboardCache so the next visit is faster.
 *
 * Cache keys are namespaced per section with per-user or per-geohash isolation.
 * TTLs are tuned per-section based on expected freshness requirements:
 *   - week:          5 min  (changes when games are created/cancelled)
 *   - feed:          15 min (changes when friends interact)
 *   - trending:      10 min (changes as games gain participants)
 *   - opportunities: 10 min (changes based on user's proximity + schedule)
 *   - contributions: 60 min (historical data, changes slowly)
 */
class DashboardCacheService
{
    /**
     * Retrieve an array from the cache, returning null if missing or not an array.
     *
     * @return array<string, mixed>|null
     */
    private function getArrayFromCache(string $key): ?array
    {
        $value = Cache::get($key);
        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Retrieve an array from the cache, returning the default if missing or not an array.
     *
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    private function getArrayFromCacheWithDefault(string $key, array $default = []): array
    {
        $value = Cache::get($key);

        return is_array($value) ? $value : $default;
    }

    /**
     * Retrieve a list of cache keys from a tracking set.
     *
     * @return string[]
     */
    private function getTrackedKeys(string $trackingKey): array
    {
        $keys = Cache::get($trackingKey, []);
        if (! is_array($keys)) {
            return [];
        }

        /** @var array<string> $keys */
        return $keys;
    }

    // ── Three-tier read primitive ──────────────────────

    /**
     * Three-tier read for a Dashboard section: cache read → on miss compute
     * (under stampede lock if the section declares it) → store → track key if
     * geohash-tracked → dispatch WarmDashboardCache (unless the section opts out).
     *
     * This primitive replaces the 12 near-identical get/warm/compute triplets.
     * All key construction, TTL, lock policy, and tracking behaviour are derived
     * from the {@see DashboardSection} enum — the single source of truth.
     *
     * @return array<string, mixed>
     */
    private function remember(DashboardSection $section, User $user, ?string $geohash4 = null): array
    {
        $userId = (string) $user->id;
        $cacheKey = $section->readKey($userId, $geohash4);

        $cached = $this->getArrayFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        if ($section->dispatchesWarm()) {
            Log::debug('dashboard.cache_miss', [
                'section' => $section->value,
                'user_id' => $user->id,
                ...($geohash4 !== null ? ['geohash_4' => $geohash4] : []),
                'cache_key' => $cacheKey,
            ]);
        }

        /** @var array<string, mixed> $data */
        $data = $section->usesLock()
            ? $this->computeWithLock(
                $section->lockKey($userId),
                $cacheKey,
                $section->ttl(),
                fn () => $this->computeSection($section, $user, $geohash4),
            )
            : tap($this->computeSection($section, $user, $geohash4), fn ($d) => Cache::put($cacheKey, $d, $section->ttl()));

        $this->trackSectionKey($section, $userId, $cacheKey);

        if ($section->dispatchesWarm()) {
            WarmDashboardCache::dispatch($userId, $section->warmTrigger());
        }

        return $data;
    }

    /**
     * Synchronous compute + store, skipping the cache-read short-circuit.
     *
     * Used by {@see WarmDashboardCache::handle()} to force-refresh sections.
     *
     * @return array<string, mixed>
     */
    private function warmSection(DashboardSection $section, User $user, ?string $geohash4 = null): array
    {
        $userId = (string) $user->id;
        $cacheKey = $section->readKey($userId, $geohash4);

        /** @var array<string, mixed> $data */
        $data = $this->computeSection($section, $user, $geohash4);
        Cache::put($cacheKey, $data, $section->ttl());

        $this->trackSectionKey($section, $userId, $cacheKey);

        Log::debug('dashboard.cache_warmed', [
            'section' => $section->value,
            'user_id' => $user->id,
            ...($geohash4 !== null ? ['geohash_4' => $geohash4] : []),
        ]);

        return $data;
    }

    /**
     * Resolve a section's computer. Sibling computers (NewcomerService,
     * DiscoveryService) are resolved lazily via app() — one-directional: the
     * cache module calls siblings for compute; siblings never call back.
     *
     * @return array<int|string, mixed>
     */
    private function computeSection(DashboardSection $section, User $user, ?string $geohash4): array
    {
        return match ($section) {
            DashboardSection::Week => $this->computeWeekData($user),
            DashboardSection::Feed => $this->computeFeedData($user),
            DashboardSection::Opportunities => $this->computeOpportunities($user, (string) $geohash4),
            DashboardSection::Contributions => $this->computeContributions($user),
            DashboardSection::Recaps => $this->computeRecaps($user),
            DashboardSection::ActionCenter => $this->computeActionCenter($user),
            DashboardSection::NewcomerWelcome => app(DashboardNewcomerService::class)->computeWelcomeData($user),
            DashboardSection::ProgressTracker => app(DashboardNewcomerService::class)->computeProgressTracker($user),
            DashboardSection::NearbyPeople => app(DashboardNewcomerService::class)->computeNearbyPeople($user, (string) $geohash4),
            DashboardSection::NewcomerMatches => app(DashboardNewcomerService::class)->computePreferenceWeightedMatches($user, (string) $geohash4),
            DashboardSection::HostAgain => $this->computeHostAgain($user),
            DashboardSection::MilestoneCards => app(DashboardDiscoveryService::class)->computeMilestoneCardsPublic($user),
        };
    }

    /**
     * Track a geohash-suffixed cache key in the section's per-user tracking set.
     * No-op for non-tracked sections.
     */
    private function trackSectionKey(DashboardSection $section, string $userId, string $cacheKey): void
    {
        $trackingKey = $section->trackingKey($userId);
        if ($trackingKey === null) {
            return;
        }

        $existingKeys = $this->getTrackedKeys($trackingKey);
        if (! in_array($cacheKey, $existingKeys, true)) {
            $existingKeys[] = $cacheKey;
            Cache::put($trackingKey, $existingKeys, $section->ttl());
        }
    }

    /**
     * Resolve every cache key to forget for a section + user, including
     * geohash-tracked fan-out. Replaces the duplicated 11-branch chains.
     *
     * @return string[]
     */
    private function sectionInvalidationKeys(DashboardSection $section, string $userId): array
    {
        $trackingKey = $section->trackingKey($userId);

        if ($trackingKey !== null) {
            $keys = $this->getTrackedKeys($trackingKey);
            $keys[] = $trackingKey;

            return $keys;
        }

        return [$section->readKey($userId)];
    }

    /**
     * Resolve all cache keys to forget for a set of sections + a user.
     *
     * @param  string[]  $sections
     * @return string[]
     */
    private function sectionKeysForUser(string $userId, array $sections): array
    {
        $keys = [];

        foreach ($sections as $sectionName) {
            $section = DashboardSection::tryFrom($sectionName);
            if ($section !== null) {
                $keys = array_merge($keys, $this->sectionInvalidationKeys($section, $userId));
            }
        }

        return $keys;
    }

    // ── TTL constants (seconds) ────────────────────────

    private const TTL_TRENDING = 600;    // 10 minutes

    // ── Read methods ───────────────────────────────────

    /**
     * Get the user's "this week" dashboard section.
     *
     * Cache key includes the start-of-week date so it naturally rotates weekly.
     *
     * @return array<string, mixed>
     */
    public function getWeekData(User $user): array
    {
        return $this->remember(DashboardSection::Week, $user);
    }

    /**
     * Get the user's activity feed section.
     *
     * Shows recent activity from followed users and nearby games.
     *
     * @return array<string, mixed>
     */
    public function getFeedData(User $user): array
    {
        return $this->remember(DashboardSection::Feed, $user);
    }

    /**
     * Get trending games for a geohash tile.
     *
     * Not user-specific — shared cache key for all users in the same tile.
     *
     * @return array<string, mixed>
     */
    public function getTrendingNearby(string $geohash4): array
    {
        $cacheKey = "dashboard:trending:{$geohash4}";

        $cached = $this->getArrayFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        Log::debug('dashboard.cache_miss', [
            'section' => 'trending',
            'geohash_4' => $geohash4,
            'cache_key' => $cacheKey,
        ]);

        WarmTrendingNearby::dispatch($geohash4, 'cache_miss');

        return $this->getArrayFromCacheWithDefault($cacheKey, ['games' => []]);
    }

    /**
     * Get open-game opportunities for a user within a geohash tile.
     *
     * @return array<string, mixed>
     */
    public function getOpportunities(User $user, string $geohash4): array
    {
        return $this->remember(DashboardSection::Opportunities, $user, $geohash4);
    }

    /**
     * Get the user's contributions / reliability stats.
     *
     * @return array<string, mixed>
     */
    public function getContributions(User $user): array
    {
        return $this->remember(DashboardSection::Contributions, $user);
    }

    // ── Read methods — two-mode dashboard sections ─────

    /**
     * Get the user's action center (newcomer dashboard).
     *
     * Returns suggested next steps and pending actions for new users.
     *
     * @return array<string, mixed>
     */
    public function getActionCenter(User $user): array
    {
        return $this->remember(DashboardSection::ActionCenter, $user);
    }

    /**
     * Get the newcomer welcome section data.
     *
     * Returns personalised welcome content for newcomer-mode users.
     *
     * @return array<string, mixed>
     */
    public function getNewcomerWelcome(User $user): array
    {
        return $this->remember(DashboardSection::NewcomerWelcome, $user);
    }

    /**
     * Get the user's progress tracker (newcomer onboarding progress).
     *
     * @return array<string, mixed>
     */
    public function getProgressTracker(User $user): array
    {
        return $this->remember(DashboardSection::ProgressTracker, $user);
    }

    /**
     * Get nearby people for a user within a geohash tile.
     *
     * @return array<string, mixed>
     */
    public function getNearbyPeople(User $user, string $geohash4): array
    {
        return $this->remember(DashboardSection::NearbyPeople, $user, $geohash4);
    }

    /**
     * Get the "host again" section for the established dashboard.
     *
     * @return array<string, mixed>
     */
    public function getHostAgain(User $user): array
    {
        return $this->remember(DashboardSection::HostAgain, $user);
    }

    /**
     * Get the milestone cards for the established dashboard.
     *
     * @return array<string, mixed>
     */
    public function getMilestoneCards(User $user): array
    {
        return $this->remember(DashboardSection::MilestoneCards, $user);
    }

    // ── Invalidation methods ───────────────────────────

    /**
     * Invalidate specified dashboard sections for a user.
     *
     * @param  string  $userId  The user whose cache to invalidate.
     * @param  string[]  $sections  Sections to invalidate: week, feed, opportunities, contributions.
     */
    public function invalidateForUser(string $userId, array $sections = ['week', 'feed', 'opportunities', 'contributions', 'recaps']): void
    {
        $keysToForget = $this->sectionKeysForUser($userId, $sections);

        Cache::deleteMultiple(array_unique($keysToForget));

        Log::debug('dashboard.cache_invalidated', [
            'user_id' => $userId,
            'sections' => $sections,
            'keys_cleared' => count($keysToForget),
        ]);
    }

    /**
     * Batch-invalidate a section for multiple users at once.
     *
     * Computes all cache keys for all users, then forgets them in a single
     * pass. Avoids N individual Cache::forget calls when invalidating
     * participant lists (e.g., game completion, bulletin posted).
     *
     * @param  string[]  $userIds
     * @param  string[]  $sections
     */
    public function invalidateForUsers(array $userIds, array $sections): void
    {
        $allKeys = [];

        foreach ($userIds as $userId) {
            $allKeys = array_merge($allKeys, $this->sectionKeysForUser((string) $userId, $sections));
        }

        Cache::deleteMultiple(array_unique($allKeys));

        Log::debug('dashboard.cache_batch_invalidated', [
            'user_count' => count($userIds),
            'sections' => $sections,
            'total_keys_cleared' => count($allKeys),
        ]);
    }

    /**
     * Invalidate the trending cache for a geohash tile.
     */
    public function invalidateTrendingForGeohash(string $geohash4): void
    {
        Cache::forget("dashboard:trending:{$geohash4}");

        Log::debug('dashboard.cache_invalidated', [
            'section' => 'trending',
            'geohash_4' => $geohash4,
        ]);
    }

    /**
     * Invalidate dashboard caches affected by a game event.
     *
     * Invalidates week/trending/opportunities for the game owner and all
     * confirmed participants, since the game's state affects their dashboards.
     */
    public function invalidateForGameEvent(Game $game, string $event): void
    {
        $affectedUserIds = collect([$game->owner_id]);

        // Include approved participants
        if ($game->relationLoaded('participants')) {
            $participantIds = $game->participants
                ->where('status', ParticipantStatus::Approved)
                ->pluck('user_id');
        } else {
            $participantIds = $game->participants()
                ->where('status', ParticipantStatus::Approved->value)
                ->pluck('user_id');
        }

        $affectedUserIds = $affectedUserIds
            ->merge($participantIds)
            ->unique()
            ->values();

        $sections = ['week', 'opportunities', 'host_again', 'milestone_cards'];

        // If game has a location, also invalidate trending for its geohash
        $location = $game->linkedLocation;
        if ($location && $location->latitude && $location->longitude) {
            $geohash4 = Geohash::tilePrefix(
                (float) $location->latitude,
                (float) $location->longitude,
                4,
            );
            $this->invalidateTrendingForGeohash($geohash4);
        }

        $this->invalidateForUsers($affectedUserIds->map(fn (mixed $id): string => to_string_id($id))->all(), $sections);

        Log::debug('dashboard.game_event_invalidated', [
            'game_id' => $game->id,
            'event' => $event,
            'affected_users' => $affectedUserIds->count(),
        ]);
    }

    // ── Action Center invalidation methods ─────────────

    /**
     * Invalidate the action center when a participant's status changes.
     *
     * Affects both the participant (waitlist/invitation/attendance items)
     * and the game owner (pending applications, below-min-players).
     */
    public function invalidateActionCenterForParticipantChange(string $userId, ?string $gameId = null): void
    {
        $userIds = [(string) $userId];

        // Also invalidate the game owner's action center (pending applications, min-players)
        if ($gameId !== null) {
            $ownerId = Game::where('id', $gameId)->value('owner_id');
            if (is_string($ownerId) && $ownerId !== $userId) {
                $userIds[] = $ownerId;
            }
        }

        $this->invalidateForUsers($userIds, ['action_center']);

        Log::debug('dashboard.action_center_invalidated.participant_change', [
            'user_id' => $userId,
            'game_id' => $gameId,
        ]);
    }

    /**
     * Invalidate the action center for a game event (status change, recap added, etc).
     *
     * Affects the game owner and all approved/waitlisted participants.
     */
    public function invalidateActionCenterForGameEvent(string $gameId): void
    {
        $game = Game::find($gameId);

        if (! $game) {
            return;
        }

        $userIds = collect([$game->owner_id]);

        $participantIds = $game->participants()
            ->whereIn('status', [
                ParticipantStatus::Approved->value,
                ParticipantStatus::Waitlisted->value,
                ParticipantStatus::Pending->value,
            ])
            ->pluck('user_id');

        $userIds = $userIds->merge($participantIds)->unique()->values();

        $this->invalidateForUsers($userIds->map(fn (mixed $id): string => to_string_id($id))->all(), ['action_center']);

        Log::debug('dashboard.action_center_invalidated.game_event', [
            'game_id' => $gameId,
            'affected_users' => $userIds->count(),
        ]);
    }

    /**
     * Invalidate the action center when a new review is created.
     *
     * Affects the reviewed GM (new review item).
     */
    public function invalidateActionCenterForReview(string $userId): void
    {
        $this->invalidateForUser((string) $userId, ['action_center']);

        Log::debug('dashboard.action_center_invalidated.review', [
            'user_id' => $userId,
        ]);
    }

    /**
     * Invalidate the action center when the user gets a new follower.
     *
     * Affects the followed user (new follower item).
     */
    public function invalidateActionCenterForFollow(string $userId): void
    {
        $this->invalidateForUser((string) $userId, ['action_center']);

        Log::debug('dashboard.action_center_invalidated.follow', [
            'user_id' => $userId,
        ]);
    }

    /**
     * Invalidate the action center for attendance-related changes.
     *
     * Affects the user who needs to report attendance.
     */
    public function invalidateActionCenterForAttendance(string $userId): void
    {
        $this->invalidateForUser((string) $userId, ['action_center']);

        Log::debug('dashboard.action_center_invalidated.attendance', [
            'user_id' => $userId,
        ]);
    }

    // ── Warm methods (called by WarmDashboardCache job) ──

    /**
     * Warm the contributions cache for a user.
     *
     * Computes and stores without synchronous fallback — called from
     * the background job to pre-populate the cache for the next visit.
     *
     * @return array<string, mixed>
     */
    public function warmContributions(User $user): array
    {
        return $this->warmSection(DashboardSection::Contributions, $user);
    }

    /**
     * Warm the feed cache for a user.
     *
     * @return array<string, mixed>
     */
    public function warmFeed(User $user): array
    {
        return $this->warmSection(DashboardSection::Feed, $user);
    }

    /**
     * Warm the opportunities cache for a user within a geohash tile.
     *
     * Tracks the cache key for later invalidation.
     *
     * @return array<string, mixed>
     */
    public function warmOpportunities(User $user, string $geohash4): array
    {
        return $this->warmSection(DashboardSection::Opportunities, $user, $geohash4);
    }

    /**
     * Warm the trending-nearby cache for a geohash tile.
     *
     * Queries games within the tile's bounding box that are scheduled
     * in the next 14 days, sorted by confirmed participant count DESC
     * then created_at DESC. Stores top 5 as serializable arrays.
     *
     * @param  string  $geohash4  The 4-character geohash tile prefix.
     * @return int Number of games stored.
     */
    public function warmTrendingNearby(string $geohash4): int
    {
        $cacheKey = "dashboard:trending:{$geohash4}";

        // Get bounding box for the geohash-4 tile
        $bounds = Geohash::prefixBounds($geohash4);

        // Query games within the tile: scheduled, next 14 days, with location
        // Subquery counts confirmed participants for sorting (owner is an explicit participant)
        $participantCountSubquery = DB::table('game_participants')
            ->selectRaw('COUNT(*)')
            ->whereColumn('game_participants.game_id', 'games.id')
            ->where('game_participants.status', ParticipantStatus::Approved->value);

        /** @var Collection<int, Game> $games */
        $games = Game::query()
            ->select('games.*')
            ->selectSub($participantCountSubquery, 'participant_count')
            ->join('locations', 'games.location_id', '=', 'locations.id')
            ->whereNotNull('locations.latitude')
            ->whereNotNull('locations.longitude')
            ->whereBetween('locations.latitude', [$bounds->minLat, $bounds->maxLat])
            ->whereBetween('locations.longitude', [$bounds->minLng, $bounds->maxLng])
            ->where('games.status', GameStatus::Scheduled->value)
            ->where('games.visibility', 'public')
            ->where('games.date_time', '>=', now())
            ->where('games.date_time', '<=', now()->addDays(14))
            ->orderByDesc('participant_count')
            ->orderByDesc('games.created_at')
            ->limit(5)
            ->get();

        // Serialize each game to a cache-friendly array
        $data = [
            'games' => $games->map(function (Game $game) {
                $location = $game->linkedLocation;

                return [
                    'id' => $game->id,
                    'name' => $game->name,
                    'date_time' => $game->date_time?->toIso8601String(),
                    'expected_duration' => $game->expected_duration,
                    'game_type' => $game->game_type?->value,
                    'max_players' => $game->max_players,
                    'participant_count' => (int) ($game->participant_count ?? 0),
                    'game_system_id' => $game->game_system_id,
                    'location_city' => $location?->city,
                    'owner_id' => $game->owner_id,
                ];
            })->values()->toArray(),
        ];

        Cache::put($cacheKey, $data, self::TTL_TRENDING);

        Log::debug('dashboard.cache_warmed', [
            'section' => 'trending',
            'geohash_4' => $geohash4,
            'games_stored' => count($data['games']),
        ]);

        return count($data['games']);
    }

    // ── Warm methods — two-mode dashboard sections ─────

    /**
     * Warm the action center cache.
     *
     * @return array<string, mixed>
     */
    public function warmActionCenter(User $user): array
    {
        return $this->warmSection(DashboardSection::ActionCenter, $user);
    }

    /**
     * Warm the newcomer welcome cache.
     *
     * @return array<string, mixed>
     */
    public function warmNewcomerWelcome(User $user): array
    {
        return $this->warmSection(DashboardSection::NewcomerWelcome, $user);
    }

    /**
     * Warm the progress tracker cache.
     *
     * @return array<string, mixed>
     */
    public function warmProgressTracker(User $user): array
    {
        return $this->warmSection(DashboardSection::ProgressTracker, $user);
    }

    /**
     * Warm the nearby people cache for a geohash tile.
     *
     * @return array<string, mixed>
     */
    public function warmNearbyPeople(User $user, string $geohash4): array
    {
        return $this->warmSection(DashboardSection::NearbyPeople, $user, $geohash4);
    }

    /**
     * @return array<string, mixed>
     */
    public function warmNewcomerMatches(User $user, string $geohash4): array
    {
        return $this->warmSection(DashboardSection::NewcomerMatches, $user, $geohash4);
    }

    /**
     * Warm the host again cache.
     *
     * @return array<string, mixed>
     */
    public function warmHostAgain(User $user): array
    {
        return $this->warmSection(DashboardSection::HostAgain, $user);
    }

    /**
     * Warm the milestone cards cache.
     *
     * @return array<string, mixed>
     */
    public function warmMilestoneCards(User $user): array
    {
        return $this->warmSection(DashboardSection::MilestoneCards, $user);
    }

    // ── Internal helpers ───────────────────────────────

    /**
     * Compute a cache section with stampede protection.
     *
     * Acquires a short-lived lock before computing to prevent thundering-herd
     * scenarios on concurrent cold-cache misses. After acquiring the lock,
     * re-checks the cache (another request may have populated it).
     *
     * @param  string  $lockKey  Lock identifier (e.g., "dashboard:compute:newcomer_matches:{uid}")
     * @param  string  $cacheKey  The cache key to read/write
     * @param  int  $ttl  Cache TTL in seconds
     * @param  callable  $compute  Factory that returns the computed data
     * @return array<string, mixed>
     */
    private function computeWithLock(string $lockKey, string $cacheKey, int $ttl, callable $compute): array
    {
        $lock = Cache::lock($lockKey, 10);

        try {
            if ($lock->block(0.05)) {
                // Double-check: another request may have populated while we waited
                $cached = $this->getArrayFromCache($cacheKey);
                if ($cached !== null) {
                    return $cached;
                }

                $data = $compute();
                Cache::put($cacheKey, $data, $ttl);

                return $data;
            }

            // Could not acquire lock — fall through to synchronous compute without lock.
            // This is acceptable: the worst case is a duplicate compute, not a deadlock.
        } catch (LockTimeoutException) {
            // Lock acquisition timed out — compute without protection.
        } finally {
            if ($lock instanceof Lock) {
                $lock->release();
            }
        }

        $data = $compute();
        Cache::put($cacheKey, $data, $ttl);

        return $data;
    }

    /**
     * Compute the user's contributions / reliability stats.
     *
     * Queries games hosted, games played, longest campaign, recaps,
     * reviews, and follower count. Data changes slowly so TTL is 1 hour.
     *
     * @return array<string, mixed>
     */
    public function computeContributions(User $user): array
    {
        // 1. Games hosted (owner, completed) — aggregate queries avoid loading full collection
        $hostedCount = Game::where('owner_id', $user->id)
            ->where('status', GameStatus::Completed->value)
            ->count();

        $totalHours = (float) Game::where('owner_id', $user->id)
            ->where('status', GameStatus::Completed->value)
            ->sum('expected_duration');

        // Unique players across all hosted games (excluding self)
        $uniquePlayerCount = 0;
        if ($hostedCount > 0) {
            $uniquePlayerCount = GameParticipant::join('games', 'game_participants.game_id', '=', 'games.id')
                ->where('games.owner_id', $user->id)
                ->where('games.status', GameStatus::Completed->value)
                ->where('game_participants.user_id', '!=', $user->id)
                ->where('game_participants.status', ParticipantStatus::Approved->value)
                ->distinct('game_participants.user_id')
                ->count('game_participants.user_id');
        }

        // 2. Games played (participated, not owned, completed)
        $playedGameIds = GameParticipant::where('user_id', $user->id)
            ->where('status', ParticipantStatus::Approved->value)
            ->pluck('game_id');

        $playedCount = 0;
        $systemCount = 0;
        if ($playedGameIds->isNotEmpty()) {
            $playedQuery = Game::whereIn('id', $playedGameIds)
                ->where('owner_id', '!=', $user->id)
                ->where('status', GameStatus::Completed->value);

            $playedCount = $playedQuery->count();
            $systemCount = (clone $playedQuery)
                ->distinct('game_system_id')
                ->count('game_system_id');
        }

        // 3. Longest campaign (active, owned by user, most completed games)
        /** @var Campaign|null $longestCampaign */
        $longestCampaign = Campaign::where('owner_id', $user->id)
            ->where('status', CampaignStatus::Active->value)
            ->withCount(['sessions as completed_games_count' => function ($query) {
                $query->where('status', GameStatus::Completed->value);
            }])
            ->orderByDesc('completed_games_count')
            ->first();

        $campaignData = null;
        if ($longestCampaign && $longestCampaign->completed_games_count > 0) {
            $campaignData = [
                'name' => $longestCampaign->name,
                'session_count' => $longestCampaign->completed_games_count,
            ];
        }

        // 4. Recaps written
        $recapsCount = Game::where('owner_id', $user->id)
            ->whereNotNull('recap')
            ->count();

        // 5. Reviews given
        $reviewsCount = Review::where('reviewer_id', $user->id)
            ->count();

        // 6. Follower count
        $followerCount = UserRelationship::where('related_user_id', $user->id)
            ->where('type', RelationshipType::Follow->value)
            ->count();

        return [
            'hosted' => [
                'count' => $hostedCount,
                'hours' => $totalHours,
                'unique_players' => $uniquePlayerCount,
            ],
            'played' => [
                'count' => $playedCount,
                'system_count' => $systemCount,
            ],
            'campaigns' => $campaignData,
            'recaps_written' => $recapsCount,
            'reviews_given' => $reviewsCount,
            'followers' => $followerCount,
        ];
    }

    /**
     * Compute open-game opportunities and recruiting campaigns for a user.
     *
     * Finds games matching the user's preferred game systems that are:
     *   - Scheduled in the next 14 days
     *   - Within the geohash tile's bounding box
     *   - Have available spots (approved participants < max_players)
     *   - Not owned or participated in by the user
     *
     * Also finds active campaigns matching the user's game system preferences
     * that the user hasn't joined yet.
     *
     * Games are scored by proximity (40%), spots available (30%), and urgency (30%).
     * Returns top 4 games and top 2 campaigns.
     *
     * @return array<string, mixed>
     */
    public function computeOpportunities(User $user, string $geohash4): array
    {
        // Get user's preferred game system IDs
        $preferredSystemIds = $user->gameSystemPreferences()->pluck('game_systems.id')->toArray();

        // Early return if user has no preferences
        if (empty($preferredSystemIds)) {
            return [
                'games' => [],
                'campaigns' => [],
                'total_available' => 0,
            ];
        }

        // Get bounding box for the geohash tile
        $bounds = Geohash::prefixBounds($geohash4);

        // Games the user already owns or participates in
        $ownedGameIds = Game::where('owner_id', $user->id)->pluck('id');
        $participatingGameIds = GameParticipant::where('user_id', $user->id)
            ->where('status', ParticipantStatus::Approved->value)
            ->pluck('game_id');
        $excludeGameIds = $ownedGameIds->merge($participatingGameIds)->unique()->values()->toArray();

        // Collect allowed owner IDs for protected content (friends + teammates)
        // Computed once and reused for both games and campaigns visibility scoping.
        $allowedOwnerIds = $user->getAllowedOwnerIdsForProtectedContent();

        // ── Games query ─────────────────────────────────
        // Owner is an explicit participant, counted naturally
        $participantCountSubquery = DB::table('game_participants')
            ->selectRaw('COUNT(*)')
            ->whereColumn('game_participants.game_id', 'games.id')
            ->where('game_participants.status', ParticipantStatus::Approved->value);

        /** @var Collection<int, Game> $games */
        $games = Game::query()
            ->select('games.*')
            ->selectSub($participantCountSubquery, 'participant_count')
            ->join('locations', 'games.location_id', '=', 'locations.id')
            ->whereNotNull('locations.latitude')
            ->whereNotNull('locations.longitude')
            ->whereBetween('locations.latitude', [$bounds->minLat, $bounds->maxLat])
            ->whereBetween('locations.longitude', [$bounds->minLng, $bounds->maxLng])
            ->where('games.status', GameStatus::Scheduled->value)
            ->where('games.date_time', '>=', now())
            ->where('games.date_time', '<=', now()->addDays(14))
            ->whereIn('games.game_system_id', $preferredSystemIds)
            ->whereNotIn('games.id', $excludeGameIds)
            ->where(function ($q) {
                // Only games with available spots (or unlimited capacity).
                // Filtering at SQL level avoids fetching rows only to discard them,
                // and ensures the limit applies to visible results, not pre-filter.
                $q->whereNull('games.max_players')
                    ->orWhereRaw(
                        '(SELECT COUNT(*) FROM game_participants WHERE game_participants.game_id = games.id AND game_participants.status = ?) < games.max_players',
                        [ParticipantStatus::Approved->value],
                    );
            })
            ->where(function ($q) use ($user, $allowedOwnerIds) {
                // public = visible to everyone
                // protected = visible to friends/teammates of the owner, or participants
                // private = never visible here (user is already excluded via $excludeGameIds for their own games)
                $q->where('games.visibility', 'public')
                    ->orWhere(function ($q) use ($user, $allowedOwnerIds) {
                        $q->where('games.visibility', 'protected')
                            ->where(function ($q) use ($user, $allowedOwnerIds) {
                                $q->whereIn('games.owner_id', $allowedOwnerIds)
                                    ->orWhereHas('participants', fn ($pq) => $pq->where('user_id', $user->id));
                            });
                    });
            })
            ->with(['gameSystem', 'owner', 'linkedLocation'])
            ->get();

        // Score each game
        $userLocation = $user->linkedLocation;
        $userLat = $userLocation?->latitude ? (float) $userLocation->latitude : null;
        $userLng = $userLocation?->longitude ? (float) $userLocation->longitude : null;

        $scoredGames = $games->map(function ($game) use ($userLat, $userLng) {
            $spotsAvailable = $game->max_players - (int) ($game->participant_count ?? 0);

            // Proximity score (0-40): closer is better
            $proximityScore = 0;
            $distance = null;
            if ($userLat !== null && $userLng !== null && $game->linkedLocation) {
                $distance = ProximityQuery::haversineDistance(
                    $userLat,
                    $userLng,
                    (float) $game->linkedLocation->latitude,
                    (float) $game->linkedLocation->longitude,
                );
                // Max distance in a geohash-4 tile is ~40km
                $proximityScore = max(0, 40 * (1 - min($distance / 40, 1)));
            } else {
                // No location info — neutral score
                $proximityScore = 20;
            }

            // Spots score (0-30): more spots = higher
            $spotsScore = min(30, $spotsAvailable * 6);

            // Urgency score (0-30): sooner is better
            $daysUntil = max(0, now()->diffInDays($game->date_time, false));
            $urgencyScore = max(0, 30 * (1 - min($daysUntil / 14, 1)));

            $totalScore = $proximityScore + $spotsScore + $urgencyScore;

            return [
                'score' => $totalScore,
                'distance_km' => $distance ?? null,
                'spots_available' => $spotsAvailable,
                'game' => $game,
            ];
        });

        // Sort by score descending, take top 4
        $topGames = $scoredGames
            ->sortByDesc('score')
            ->take(4)
            ->values();

        $gameResults = $topGames->map(function ($item) {
            $game = $item['game'];

            return [
                'entity_type' => 'game',
                'entity_id' => $game->id,
                'entity_name' => $game->name,
                'game_system_name' => $game->gameSystem?->name,
                'date_time' => $game->date_time?->toIso8601String(),
                'spots_available' => $item['spots_available'],
                'distance_km' => $item['distance_km'] !== null ? round($item['distance_km'], 1) : null,
                'owner_name' => $game->owner?->name,
            ];
        })->toArray();

        // ── Campaigns query ──────────────────────────────
        // Campaigns the user already participates in
        $participatingCampaignIds = CampaignParticipant::where('user_id', $user->id)
            ->where('status', ParticipantStatus::Approved->value)
            ->pluck('campaign_id')
            ->map(fn (mixed $id): string => to_string_id($id))
            ->all();

        $ownedCampaignIds = Campaign::where('owner_id', $user->id)
            ->pluck('id')
            ->map(fn (mixed $id): string => to_string_id($id))
            ->all();
        /** @var string[] $excludeCampaignIds */
        $excludeCampaignIds = array_unique(array_merge($participatingCampaignIds, $ownedCampaignIds));

        $campaigns = Campaign::query()
            ->where('status', CampaignStatus::Active->value)
            ->whereIn('game_system_id', $preferredSystemIds)
            ->whereNotIn('id', $excludeCampaignIds)
            ->where(function ($q) use ($user, $allowedOwnerIds) {
                $q->where('visibility', 'public')
                    ->orWhere(function ($q) use ($user, $allowedOwnerIds) {
                        $q->where('visibility', 'protected')
                            ->where(function ($q) use ($user, $allowedOwnerIds) {
                                $q->whereIn('owner_id', $allowedOwnerIds)
                                    ->orWhereHas('participants', fn ($pq) => $pq->where('user_id', $user->id));
                            });
                    });
            })
            ->with(['gameSystem', 'owner'])
            ->withCount(['participants as approved_participant_count' => function ($query) {
                $query->where('status', ParticipantStatus::Approved->value);
            }])
            ->orderByDesc('created_at')
            ->limit(2)
            ->get();

        /** @var Collection<int, Campaign> $campaigns */
        $campaignResults = $campaigns->map(function ($campaign) {
            $participantCount = $campaign->approved_participant_count; // Owner counted naturally

            $spotsAvailable = $campaign->max_players
                ? max(0, $campaign->max_players - $participantCount)
                : null;

            return [
                'entity_type' => 'campaign',
                'entity_id' => $campaign->id,
                'entity_name' => $campaign->name,
                'game_system_name' => $campaign->gameSystem?->name,
                'recurrence' => $campaign->recurrence,
                'spots_available' => $spotsAvailable,
                'distance_km' => null,
                'owner_name' => $campaign->owner?->name,
            ];
        })->toArray();

        return [
            'games' => $gameResults,
            'campaigns' => $campaignResults,
            'total_available' => count($gameResults) + count($campaignResults),
        ];
    }

    /**
     * Compute the user's "this week" game data.
     *
     * Queries all games where the user is owner or approved participant,
     * with date_time falling within the current week (Mon–Sun).
     * Returns a serializable array grouped by date with summary stats.
     *
     * @return array<string, mixed>
     */
    public function computeWeekData(User $user): array
    {
        $startOfWeek = now()->startOfWeek();
        $endOfWeek = now()->endOfWeek();

        // Collect game IDs where user is owner or approved participant
        $ownedGameIds = Game::where('owner_id', $user->id)
            ->pluck('id');

        $participatingGameIds = GameParticipant::where('user_id', $user->id)
            ->where('status', ParticipantStatus::Approved->value)
            ->pluck('game_id');

        $gameIds = $ownedGameIds->merge($participatingGameIds)->unique()->values();

        // Query games this week with eager loading
        $games = Game::whereIn('id', $gameIds)
            ->whereIn('status', [
                GameStatus::Scheduled->value,
                GameStatus::Completed->value,
                GameStatus::Canceled->value,
            ])
            ->whereBetween('date_time', [$startOfWeek, $endOfWeek])
            ->with(['gameSystem', 'participants' => fn ($q) => $q->where('status', ParticipantStatus::Approved->value), 'campaign'])
            ->orderBy('date_time')
            ->get();

        /** @var Collection<int, Game> $games */

        // Build the day structure (Mon–Sun)
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $startOfWeek->copy()->addDays($i);
            $dateKey = $date->format('Y-m-d');
            $days[$dateKey] = [
                'date' => $dateKey,
                'day_name' => $date->format('D'),
                'is_today' => $date->isToday(),
                'games' => [],
            ];
        }

        $summary = [
            'total' => 0,
            'past' => 0,
            'upcoming' => 0,
            'hosting' => 0,
            'playing' => 0,
        ];

        foreach ($games as $game) {
            if ($game->date_time === null) {
                continue;
            }

            $isPast = $game->date_time->isBefore(now());
            $isHosting = (string) $game->owner_id === (string) $user->id;
            $playerCount = $game->participants->count();

            $userParticipant = $game->participants->firstWhere('user_id', $user->id);

            $gameData = [
                'id' => $game->id,
                'name' => $game->name,
                'date_time' => $game->date_time->toIso8601String(),
                'expected_duration' => $game->expected_duration,
                'status' => $game->status->value ?? '',
                'game_system_name' => $game->gameSystem?->name,
                'campaign_name' => $game->campaign?->name,
                'max_players' => $game->max_players,
                'is_past' => $isPast,
                'is_hosting' => $isHosting,
                'player_count' => $playerCount,
                'needs_recap' => $isPast && empty($game->recap) && $isHosting,
                'needs_attendance' => $isPast && ! $isHosting && $userParticipant && $userParticipant->attendance_status === null,
            ];

            $dateKey = $game->date_time->format('Y-m-d');
            if (isset($days[$dateKey])) {
                $days[$dateKey]['games'][] = $gameData;
            }

            $summary['total']++;
            if ($isPast) {
                $summary['past']++;
            } else {
                $summary['upcoming']++;
            }
            if ($isHosting) {
                $summary['hosting']++;
            } else {
                $summary['playing']++;
            }
        }

        return [
            'days' => array_values($days),
            'summary' => $summary,
        ];
    }

    /**
     * Compute the user's community feed — recent activity from their social circle.
     *
     * Queries game and campaign activity from followed users, converts to
     * FeedItem DTOs, and returns a serializable array for cache storage.
     *
     * @return array<string, mixed>
     */
    public function computeFeedData(User $user): array
    {
        $feedService = app(GameActivityFeedService::class);

        // Get social circle IDs directly (same as GameActivityFeedService::getSocialCircleUserIds)
        $socialCircleIds = $user->followings()
            ->pluck('related_user_id')
            ->unique()
            ->values()
            ->toArray();

        if (empty($socialCircleIds)) {
            return [
                'items' => [],
                'source' => 'friends',
                'fetched_at' => now()->toISOString(),
            ];
        }

        // Merge game + campaign activity, take top 10
        // Fetch 20 each — enough for a fair merge while avoiding
        // hydrating 100 items only to discard 90.
        /** @var LengthAwarePaginator<int, Game> $gameActivities */
        $gameActivities = $feedService->getFeed($user, 20);
        /** @var LengthAwarePaginator<int, Campaign> $campaignActivities */
        $campaignActivities = $feedService->getCampaignFeed($user, 20);

        // Convert both to FeedItem DTOs
        /** @var \Illuminate\Support\Collection<int, ActivityFeedItem> $gameCollection */
        $gameCollection = $gameActivities->getCollection();
        $gameItems = $feedService->toFeedItems($gameCollection);
        /** @var \Illuminate\Support\Collection<int, ActivityFeedItem> $campaignCollection */
        $campaignCollection = $campaignActivities->getCollection();
        $campaignItems = $feedService->toFeedItems($campaignCollection);

        // Merge, sort by created_at desc, take 10
        $merged = $gameItems
            ->merge($campaignItems)
            ->sortByDesc(fn (FeedItem $item) => $item->createdAt->timestamp)
            ->take(10)
            ->values();

        return [
            'items' => $merged->map(fn (FeedItem $item) => $item->toArray())->toArray(),
            'source' => 'friends',
            'fetched_at' => now()->toISOString(),
        ];
    }

    /**
     * Get recent recaps from games the user participated in.
     *
     * Cache key: dashboard:recaps:{userId}, TTL 15 min.
     *
     * @return array<string, mixed>
     */
    public function getRecaps(User $user): array
    {
        return $this->remember(DashboardSection::Recaps, $user);
    }

    /**
     * Warm the recaps cache (called by WarmDashboardCache job).
     *
     * @return array<string, mixed>
     */
    public function warmRecaps(User $user): array
    {
        return $this->warmSection(DashboardSection::Recaps, $user);
    }

    /**
     * Compute recent recaps — games the user played in (not owned) with new recaps.
     *
     * @return array<string, mixed>
     */
    private function computeRecaps(User $user): array
    {
        $games = Game::whereHas('participants', fn ($q) => $q
            ->where('user_id', $user->id)
            ->where('status', ParticipantStatus::Approved->value)
        )
            ->where('owner_id', '!=', $user->id)
            ->whereNotNull('recap')
            ->where('recap', '!=', '')
            ->where('status', GameStatus::Completed->value)
            ->where('updated_at', '>', now()->subDays(7))
            ->with('owner')
            ->orderByDesc('updated_at')
            ->limit(3)
            ->get();

        return $games->map(fn (Game $game) => [
            'id' => $game->id,
            'name' => $game->name,
            'owner_name' => $game->owner?->name,
        ])->toArray();
    }

    // ── Section computers with inline logic ──────────

    /**
     * @return array<string, mixed>
     */
    private function computeActionCenter(User $user): array
    {
        $items = app(ActionCenterService::class)->getItems($user);

        return array_map(fn (ActionItem $item) => $item->toArray(), $items);
    }

    /**
     * Get newcomer preference-weighted matches via cache.
     *
     * @return array<string, mixed>
     */
    public function getNewcomerMatches(User $user, string $geohash4): array
    {
        return $this->remember(DashboardSection::NewcomerMatches, $user, $geohash4);
    }

    /**
     * @return array<string, mixed>
     */
    private function computeHostAgain(User $user): array
    {
        /** @var Game|null $lastGame */
        $lastGame = Game::where('owner_id', $user->id)
            ->where('status', GameStatus::Completed->value)
            ->with('gameSystem')
            ->orderByDesc('date_time')
            ->first();

        if ($lastGame === null) {
            return [];
        }

        return [
            'game' => [
                'id' => $lastGame->id,
                'name' => $lastGame->name,
                'system' => $lastGame->gameSystem?->name,
                'expected_duration' => $lastGame->expected_duration,
            ],
            'clone_url' => route('games.create', ['clone' => $lastGame->id]),
        ];
    }
}

<?php

namespace App\Services;

use App\Enums\DashboardSection;
use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Jobs\WarmDashboardCache;
use App\Jobs\WarmTrendingNearby;
use App\Models\Game;
use App\Models\User;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
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
                fn () => $this->computeAndStore($section, $user, $geohash4, $cacheKey),
            )
            : $this->computeAndStore($section, $user, $geohash4, $cacheKey);

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
        $data = $this->computeAndStore($section, $user, $geohash4, $cacheKey);

        $this->trackSectionKey($section, $userId, $cacheKey);

        Log::debug('dashboard.cache_warmed', [
            'section' => $section->value,
            'user_id' => $user->id,
            ...($geohash4 !== null ? ['geohash_4' => $geohash4] : []),
        ]);

        return $data;
    }

    /**
     * Resolve a section's computer. Sibling computers (EstablishedService,
     * NewcomerService, DiscoveryService) are resolved lazily via app() —
     * one-directional: the cache module calls siblings for compute; siblings
     * never call back.
     *
     * @return array<int|string, mixed>
     *
     * @throws \Throwable When the section's computer fails (handled by {@see computeAndStore}).
     */
    private function dispatchCompute(DashboardSection $section, User $user, ?string $geohash4): array
    {
        return match ($section) {
            DashboardSection::Week => app(DashboardEstablishedService::class)->computeWeekData($user),
            DashboardSection::Feed => app(DashboardEstablishedService::class)->computeFeedData($user),
            DashboardSection::Opportunities => app(DashboardEstablishedService::class)->computeOpportunities($user, (string) $geohash4),
            DashboardSection::Contributions => app(DashboardEstablishedService::class)->computeContributions($user),
            DashboardSection::Recaps => app(DashboardEstablishedService::class)->computeRecaps($user),
            DashboardSection::ActionCenter => app(DashboardEstablishedService::class)->computeActionCenter($user),
            DashboardSection::NewcomerWelcome => app(DashboardNewcomerService::class)->computeWelcomeData($user),
            DashboardSection::ProgressTracker => app(DashboardNewcomerService::class)->computeProgressTracker($user),
            DashboardSection::NearbyPeople => app(DashboardNewcomerService::class)->computeNearbyPeople($user, (string) $geohash4),
            DashboardSection::NewcomerMatches => app(DashboardNewcomerService::class)->computePreferenceWeightedMatches($user, (string) $geohash4),
            DashboardSection::HostAgain => app(DashboardEstablishedService::class)->computeHostAgain($user),
            DashboardSection::MilestoneCards => app(DashboardDiscoveryService::class)->computeMilestoneCardsPublic($user),
        };
    }

    /**
     * Compute a section's data and cache it, selecting the TTL by outcome.
     *
     * A healthy compute is cached at the section's normal {@see DashboardSection::ttl()}.
     * A degraded compute (the computer threw) degrades to the section's
     * {@see DashboardSection::fallback()} view-safe empty shape, is cached at the
     * short {@see DashboardSection::degradedTtl()} so a transient failure self-heals
     * within a minute, and is logged for observability. This is the single place
     * that owns failure isolation: one throwing section renders empty instead of
     * propagating and blanking the whole Dashboard.
     *
     * @return array<int|string, mixed>
     */
    private function computeAndStore(DashboardSection $section, User $user, ?string $geohash4, string $cacheKey): array
    {
        try {
            $data = $this->dispatchCompute($section, $user, $geohash4);
            Cache::put($cacheKey, $data, $section->ttl());

            return $data;
        } catch (\Throwable $e) {
            $this->logSectionFailure($section, $user, $geohash4, $e);

            $fallback = $section->fallback();
            Cache::put($cacheKey, $fallback, $section->degradedTtl());

            return $fallback;
        }
    }

    /**
     * Log a section-compute failure with full context and stack trace.
     */
    private function logSectionFailure(DashboardSection $section, User $user, ?string $geohash4, \Throwable $e): void
    {
        Log::warning('dashboard.section_compute_failed', [
            'section' => $section->value,
            'user_id' => $user->id,
            ...($geohash4 !== null ? ['geohash_4' => $geohash4] : []),
            'error' => $e->getMessage(),
            'exception_class' => $e::class,
            // The throwable itself: Laravel's formatter renders the stack trace,
            // which is essential for locating the throwing line across a large computer.
            'exception' => $e,
        ]);
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
            ->with('gameSystems')
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
                    'game_system_id' => $game->gameSystems->first()?->id,
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
     * @param  callable  $computeAndStore  Store-aware compute: owns both the compute and the Cache::put at the correct TTL
     * @return array<string, mixed>
     */
    private function computeWithLock(string $lockKey, string $cacheKey, callable $computeAndStore): array
    {
        $lock = Cache::lock($lockKey, 10);

        try {
            if ($lock->block(0.05)) {
                // Double-check: another request may have populated while we waited
                $cached = $this->getArrayFromCache($cacheKey);
                if ($cached !== null) {
                    return $cached;
                }

                // The closure owns the compute + Cache::put so it can select the
                // correct TTL (healthy vs degraded) and apply failure isolation.
                return $computeAndStore();
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

        return $computeAndStore();
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
     * Get newcomer preference-weighted matches via cache.
     *
     * @return array<string, mixed>
     */
    public function getNewcomerMatches(User $user, string $geohash4): array
    {
        return $this->remember(DashboardSection::NewcomerMatches, $user, $geohash4);
    }
}

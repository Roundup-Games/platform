<?php

namespace App\Services;

use App\Dto\ActionItem;
use App\Dto\ActivityFeedItem;
use App\Dto\FeedItem;
use App\Enums\CampaignStatus;
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

    // ── TTL constants (seconds) ────────────────────────

    private const TTL_WEEK = 300;        // 5 minutes

    private const TTL_FEED = 900;        // 15 minutes

    private const TTL_TRENDING = 600;    // 10 minutes

    private const TTL_OPPORTUNITIES = 600; // 10 minutes

    private const TTL_CONTRIBUTIONS = 3600; // 1 hour

    // ── TTL constants — two-mode dashboard sections ────

    private const TTL_ACTION_CENTER = 300;      // 5 minutes

    private const TTL_NEWCOMER_WELCOME = 600;   // 10 minutes

    private const TTL_PROGRESS_TRACKER = 300;   // 5 minutes

    private const TTL_NEARBY_PEOPLE = 900;      // 15 minutes

    private const TTL_NEWCOMER_MATCHES = 600;     // 10 minutes

    private const TTL_HOST_AGAIN = 600;         // 10 minutes

    private const TTL_MILESTONE_CARDS = 3600;   // 1 hour

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
        $weekKey = now()->startOfWeek()->format('Y-m-d');
        $cacheKey = "dashboard:week:{$user->id}:{$weekKey}";

        $cached = $this->getArrayFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        Log::debug('dashboard.cache_miss', [
            'section' => 'week',
            'user_id' => $user->id,
            'cache_key' => $cacheKey,
        ]);

        $data = $this->computeWeekData($user);

        Cache::put($cacheKey, $data, self::TTL_WEEK);

        WarmDashboardCache::dispatch((string) $user->id, 'cache_miss_week');

        return $data;
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
        $cacheKey = "dashboard:feed:{$user->id}";

        $cached = $this->getArrayFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        Log::debug('dashboard.cache_miss', [
            'section' => 'feed',
            'user_id' => $user->id,
            'cache_key' => $cacheKey,
        ]);

        $data = $this->computeFeedData($user);

        Cache::put($cacheKey, $data, self::TTL_FEED);

        WarmDashboardCache::dispatch((string) $user->id, 'cache_miss_feed');

        return $data;
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
        $cacheKey = "dashboard:opportunities:{$user->id}:{$geohash4}";

        $cached = $this->getArrayFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        Log::debug('dashboard.cache_miss', [
            'section' => 'opportunities',
            'user_id' => $user->id,
            'geohash_4' => $geohash4,
            'cache_key' => $cacheKey,
        ]);

        $data = $this->computeWithLock(
            "dashboard:compute:opportunities:{$user->id}",
            $cacheKey,
            self::TTL_OPPORTUNITIES,
            fn () => $this->computeOpportunities($user, $geohash4),
        );

        // Track key for invalidation
        $this->trackOpportunityKey((string) $user->id, $cacheKey);

        WarmDashboardCache::dispatch((string) $user->id, 'cache_miss_opportunities');

        return $data;
    }

    /**
     * Get the user's contributions / reliability stats.
     *
     * @return array<string, mixed>
     */
    public function getContributions(User $user): array
    {
        $cacheKey = "dashboard:contributions:{$user->id}";

        $cached = $this->getArrayFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        Log::debug('dashboard.cache_miss', [
            'section' => 'contributions',
            'user_id' => $user->id,
            'cache_key' => $cacheKey,
        ]);

        $data = $this->computeContributions($user);

        Cache::put($cacheKey, $data, self::TTL_CONTRIBUTIONS);

        WarmDashboardCache::dispatch((string) $user->id, 'cache_miss_contributions');

        return $data;
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
        $cacheKey = "dashboard:action_center:{$user->id}";

        $cached = $this->getArrayFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        Log::debug('dashboard.cache_miss', [
            'section' => 'action_center',
            'user_id' => $user->id,
            'cache_key' => $cacheKey,
        ]);

        $data = $this->computeWithLock(
            "dashboard:compute:action_center:{$user->id}",
            $cacheKey,
            self::TTL_ACTION_CENTER,
            fn () => $this->computeActionCenter($user),
        );

        WarmDashboardCache::dispatch((string) $user->id, 'cache_miss_action_center');

        return $data;
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
        $cacheKey = "dashboard:newcomer_welcome:{$user->id}";

        $cached = $this->getArrayFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        Log::debug('dashboard.cache_miss', [
            'section' => 'newcomer_welcome',
            'user_id' => $user->id,
            'cache_key' => $cacheKey,
        ]);

        $data = $this->computeNewcomerWelcome($user);

        Cache::put($cacheKey, $data, self::TTL_NEWCOMER_WELCOME);

        WarmDashboardCache::dispatch((string) $user->id, 'cache_miss_newcomer_welcome');

        return $data;
    }

    /**
     * Get the user's progress tracker (newcomer onboarding progress).
     *
     * @return array<string, mixed>
     */
    public function getProgressTracker(User $user): array
    {
        $cacheKey = "dashboard:progress_tracker:{$user->id}";

        $cached = $this->getArrayFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        Log::debug('dashboard.cache_miss', [
            'section' => 'progress_tracker',
            'user_id' => $user->id,
            'cache_key' => $cacheKey,
        ]);

        $data = $this->computeProgressTracker($user);

        Cache::put($cacheKey, $data, self::TTL_PROGRESS_TRACKER);

        WarmDashboardCache::dispatch((string) $user->id, 'cache_miss_progress_tracker');

        return $data;
    }

    /**
     * Get nearby people for a user within a geohash tile.
     *
     * @return array<string, mixed>
     */
    public function getNearbyPeople(User $user, string $geohash4): array
    {
        $cacheKey = "dashboard:nearby_people:{$user->id}:{$geohash4}";

        $cached = $this->getArrayFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        Log::debug('dashboard.cache_miss', [
            'section' => 'nearby_people',
            'user_id' => $user->id,
            'geohash_4' => $geohash4,
            'cache_key' => $cacheKey,
        ]);

        $data = $this->computeWithLock(
            "dashboard:compute:nearby_people:{$user->id}",
            $cacheKey,
            self::TTL_NEARBY_PEOPLE,
            fn () => $this->computeNearbyPeople($user, $geohash4),
        );

        $this->trackNearbyPeopleKey((string) $user->id, $cacheKey);

        WarmDashboardCache::dispatch((string) $user->id, 'cache_miss_nearby_people');

        return $data;
    }

    /**
     * Get the "host again" section for the established dashboard.
     *
     * @return array<string, mixed>
     */
    public function getHostAgain(User $user): array
    {
        $cacheKey = "dashboard:host_again:{$user->id}";

        $cached = $this->getArrayFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        Log::debug('dashboard.cache_miss', [
            'section' => 'host_again',
            'user_id' => $user->id,
            'cache_key' => $cacheKey,
        ]);

        $data = $this->computeHostAgain($user);

        Cache::put($cacheKey, $data, self::TTL_HOST_AGAIN);

        WarmDashboardCache::dispatch((string) $user->id, 'cache_miss_host_again');

        return $data;
    }

    /**
     * Get the milestone cards for the established dashboard.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMilestoneCards(User $user): array
    {
        $cacheKey = "dashboard:milestone_cards:{$user->id}";

        $cached = $this->getArrayFromCache($cacheKey);
        if ($cached !== null) {
            /** @var array<int, array<string, mixed>> $cached */
            return $cached;
        }

        Log::debug('dashboard.cache_miss', [
            'section' => 'milestone_cards',
            'user_id' => $user->id,
            'cache_key' => $cacheKey,
        ]);

        $data = $this->computeMilestoneCards($user);

        Cache::put($cacheKey, $data, self::TTL_MILESTONE_CARDS);

        WarmDashboardCache::dispatch((string) $user->id, 'cache_miss_milestone_cards');

        return $data;
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
        $keysToForget = [];

        if (in_array('week', $sections)) {
            $weekKey = now()->startOfWeek()->format('Y-m-d');
            $keysToForget[] = "dashboard:week:{$userId}:{$weekKey}";
        }

        if (in_array('feed', $sections)) {
            $keysToForget[] = "dashboard:feed:{$userId}";
        }

        if (in_array('opportunities', $sections)) {
            // Opportunities keys are geohash-dependent; clear the tracking set
            $trackingKey = "dashboard:opportunities:keys:{$userId}";
            $opportunityKeys = $this->getTrackedKeys($trackingKey);
            foreach ($opportunityKeys as $key) {
                $keysToForget[] = $key;
            }
            $keysToForget[] = $trackingKey;
        }

        if (in_array('contributions', $sections)) {
            $keysToForget[] = "dashboard:contributions:{$userId}";
        }

        if (in_array('recaps', $sections)) {
            $keysToForget[] = "dashboard:recaps:{$userId}";
        }

        // ── Two-mode dashboard sections ─────────────────

        if (in_array('action_center', $sections)) {
            $keysToForget[] = "dashboard:action_center:{$userId}";
        }

        if (in_array('newcomer_welcome', $sections)) {
            $keysToForget[] = "dashboard:newcomer_welcome:{$userId}";
        }

        if (in_array('progress_tracker', $sections)) {
            $keysToForget[] = "dashboard:progress_tracker:{$userId}";
        }

        if (in_array('nearby_people', $sections)) {
            $trackingKey = "dashboard:nearby_people:keys:{$userId}";
            $trackedKeys = $this->getTrackedKeys($trackingKey);
            foreach ($trackedKeys as $key) {
                $keysToForget[] = $key;
            }
            $keysToForget[] = $trackingKey;
        }

        if (in_array('newcomer_matches', $sections)) {
            $trackingKey = "dashboard:newcomer_matches:keys:{$userId}";
            $trackedKeys = $this->getTrackedKeys($trackingKey);
            foreach ($trackedKeys as $key) {
                $keysToForget[] = $key;
            }
            $keysToForget[] = $trackingKey;
        }

        if (in_array('host_again', $sections)) {
            $keysToForget[] = "dashboard:host_again:{$userId}";
        }

        if (in_array('milestone_cards', $sections)) {
            $keysToForget[] = "dashboard:milestone_cards:{$userId}";
        }

        // Use deleteMultiple for batch efficiency (single Redis pipeline on Redis driver)
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
            $userId = (string) $userId;

            if (in_array('week', $sections)) {
                $weekKey = now()->startOfWeek()->format('Y-m-d');
                $allKeys[] = "dashboard:week:{$userId}:{$weekKey}";
            }

            if (in_array('feed', $sections)) {
                $allKeys[] = "dashboard:feed:{$userId}";
            }

            if (in_array('opportunities', $sections)) {
                $trackingKey = "dashboard:opportunities:keys:{$userId}";
                $trackedKeys = $this->getTrackedKeys($trackingKey);
                foreach ($trackedKeys as $key) {
                    $allKeys[] = $key;
                }
                $allKeys[] = $trackingKey;
            }

            if (in_array('recaps', $sections)) {
                $allKeys[] = "dashboard:recaps:{$userId}";
            }

            if (in_array('action_center', $sections)) {
                $allKeys[] = "dashboard:action_center:{$userId}";
            }

            if (in_array('newcomer_welcome', $sections)) {
                $allKeys[] = "dashboard:newcomer_welcome:{$userId}";
            }

            if (in_array('progress_tracker', $sections)) {
                $allKeys[] = "dashboard:progress_tracker:{$userId}";
            }

            if (in_array('nearby_people', $sections)) {
                $trackingKey = "dashboard:nearby_people:keys:{$userId}";
                $trackedKeys = $this->getTrackedKeys($trackingKey);
                foreach ($trackedKeys as $key) {
                    $allKeys[] = $key;
                }
                $allKeys[] = $trackingKey;
            }

            if (in_array('newcomer_matches', $sections)) {
                $trackingKey = "dashboard:newcomer_matches:keys:{$userId}";
                $trackedKeys = $this->getTrackedKeys($trackingKey);
                foreach ($trackedKeys as $key) {
                    $allKeys[] = $key;
                }
                $allKeys[] = $trackingKey;
            }

            if (in_array('host_again', $sections)) {
                $allKeys[] = "dashboard:host_again:{$userId}";
            }

            if (in_array('milestone_cards', $sections)) {
                $allKeys[] = "dashboard:milestone_cards:{$userId}";
            }
        }

        // Use deleteMultiple for batch efficiency (single Redis pipeline on Redis driver)
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
        $cacheKey = "dashboard:contributions:{$user->id}";

        $data = $this->computeContributions($user);

        Cache::put($cacheKey, $data, self::TTL_CONTRIBUTIONS);

        Log::debug('dashboard.cache_warmed', [
            'section' => 'contributions',
            'user_id' => $user->id,
        ]);

        return $data;
    }

    /**
     * Warm the feed cache for a user.
     *
     * @return array<string, mixed>
     */
    public function warmFeed(User $user): array
    {
        $cacheKey = "dashboard:feed:{$user->id}";

        $data = $this->computeFeedData($user);

        Cache::put($cacheKey, $data, self::TTL_FEED);

        Log::debug('dashboard.cache_warmed', [
            'section' => 'feed',
            'user_id' => $user->id,
        ]);

        return $data;
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
        $cacheKey = "dashboard:opportunities:{$user->id}:{$geohash4}";

        $data = $this->computeOpportunities($user, $geohash4);

        Cache::put($cacheKey, $data, self::TTL_OPPORTUNITIES);

        // Track key for invalidation
        $this->trackOpportunityKey((string) $user->id, $cacheKey);

        Log::debug('dashboard.cache_warmed', [
            'section' => 'opportunities',
            'user_id' => $user->id,
            'geohash_4' => $geohash4,
        ]);

        return $data;
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
        $cacheKey = "dashboard:action_center:{$user->id}";

        $data = $this->computeActionCenter($user);

        Cache::put($cacheKey, $data, self::TTL_ACTION_CENTER);

        Log::debug('dashboard.cache_warmed', [
            'section' => 'action_center',
            'user_id' => $user->id,
        ]);

        return $data;
    }

    /**
     * Warm the newcomer welcome cache.
     *
     * @return array<string, mixed>
     */
    public function warmNewcomerWelcome(User $user): array
    {
        $cacheKey = "dashboard:newcomer_welcome:{$user->id}";

        $data = $this->computeNewcomerWelcome($user);

        Cache::put($cacheKey, $data, self::TTL_NEWCOMER_WELCOME);

        Log::debug('dashboard.cache_warmed', [
            'section' => 'newcomer_welcome',
            'user_id' => $user->id,
        ]);

        return $data;
    }

    /**
     * Warm the progress tracker cache.
     *
     * @return array<string, mixed>
     */
    public function warmProgressTracker(User $user): array
    {
        $cacheKey = "dashboard:progress_tracker:{$user->id}";

        $data = $this->computeProgressTracker($user);

        Cache::put($cacheKey, $data, self::TTL_PROGRESS_TRACKER);

        Log::debug('dashboard.cache_warmed', [
            'section' => 'progress_tracker',
            'user_id' => $user->id,
        ]);

        return $data;
    }

    /**
     * Warm the nearby people cache for a geohash tile.
     *
     * @return array<string, mixed>
     */
    public function warmNearbyPeople(User $user, string $geohash4): array
    {
        $cacheKey = "dashboard:nearby_people:{$user->id}:{$geohash4}";

        $data = $this->computeNearbyPeople($user, $geohash4);

        Cache::put($cacheKey, $data, self::TTL_NEARBY_PEOPLE);

        $this->trackNearbyPeopleKey((string) $user->id, $cacheKey);

        Log::debug('dashboard.cache_warmed', [
            'section' => 'nearby_people',
            'user_id' => $user->id,
            'geohash_4' => $geohash4,
        ]);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function warmNewcomerMatches(User $user, string $geohash4): array
    {
        $cacheKey = "dashboard:newcomer_matches:{$user->id}:{$geohash4}";

        $data = $this->computeNewcomerMatches($user, $geohash4);

        Cache::put($cacheKey, $data, self::TTL_NEWCOMER_MATCHES);

        $this->trackNewcomerMatchesKey((string) $user->id, $cacheKey);

        Log::debug('dashboard.cache_warmed', [
            'section' => 'newcomer_matches',
            'user_id' => $user->id,
            'geohash_4' => $geohash4,
        ]);

        return $data;
    }

    /**
     * Warm the host again cache.
     *
     * @return array<string, mixed>
     */
    public function warmHostAgain(User $user): array
    {
        $cacheKey = "dashboard:host_again:{$user->id}";

        $data = $this->computeHostAgain($user);

        Cache::put($cacheKey, $data, self::TTL_HOST_AGAIN);

        Log::debug('dashboard.cache_warmed', [
            'section' => 'host_again',
            'user_id' => $user->id,
        ]);

        return $data;
    }

    /**
     * Warm the milestone cards cache.
     *
     * @return array<int, array<string, mixed>>
     */
    public function warmMilestoneCards(User $user): array
    {
        $cacheKey = "dashboard:milestone_cards:{$user->id}";

        $data = $this->computeMilestoneCards($user);

        Cache::put($cacheKey, $data, self::TTL_MILESTONE_CARDS);

        Log::debug('dashboard.cache_warmed', [
            'section' => 'milestone_cards',
            'user_id' => $user->id,
        ]);

        return $data;
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

        $ownedCampaignIds = Campaign::where('owner_id', $user->id)->pluck('id')->map(fn (mixed $id): string => to_string_id($id))->all();
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
        $cacheKey = "dashboard:recaps:{$user->id}";

        $cached = $this->getArrayFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $data = $this->computeRecaps($user);

        Cache::put($cacheKey, $data, self::TTL_FEED);

        return $data;
    }

    /**
     * Warm the recaps cache (called by WarmDashboardCache job).
     *
     * @return array<string, mixed>
     */
    public function warmRecaps(User $user): array
    {
        $cacheKey = "dashboard:recaps:{$user->id}";
        $data = $this->computeRecaps($user);

        Cache::put($cacheKey, $data, self::TTL_FEED);

        return $data;
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

    /**
     * Track an opportunities cache key in the user's key set for later invalidation.
     */
    private function trackOpportunityKey(string $userId, string $cacheKey): void
    {
        $keySetKey = "dashboard:opportunities:keys:{$userId}";
        $existingKeys = $this->getTrackedKeys($keySetKey);

        if (! in_array($cacheKey, $existingKeys)) {
            $existingKeys[] = $cacheKey;
            Cache::put($keySetKey, $existingKeys, self::TTL_OPPORTUNITIES);
        }
    }

    /**
     * Track a nearby-people cache key in the user's key set for later invalidation.
     */
    private function trackNearbyPeopleKey(string $userId, string $cacheKey): void
    {
        $keySetKey = "dashboard:nearby_people:keys:{$userId}";
        $existingKeys = $this->getTrackedKeys($keySetKey);

        if (! in_array($cacheKey, $existingKeys)) {
            $existingKeys[] = $cacheKey;
            Cache::put($keySetKey, $existingKeys, self::TTL_NEARBY_PEOPLE);
        }
    }

    /**
     * Track a newcomer-matches cache key in the user's key set for later invalidation.
     */
    private function trackNewcomerMatchesKey(string $userId, string $cacheKey): void
    {
        $keySetKey = "dashboard:newcomer_matches:keys:{$userId}";
        $existingKeys = $this->getTrackedKeys($keySetKey);

        if (! in_array($cacheKey, $existingKeys)) {
            $existingKeys[] = $cacheKey;
            Cache::put($keySetKey, $existingKeys, self::TTL_NEWCOMER_MATCHES);
        }
    }

    // ── Compute stubs — two-mode dashboard sections ────
    // These return empty data; future tasks will fill in real computation.

    /**
     * @return array<string, mixed>
     */
    private function computeActionCenter(User $user): array
    {
        $items = app(ActionCenterService::class)->getItems($user);

        return array_map(fn (ActionItem $item) => $item->toArray(), $items);
    }

    /**
     * @return array<string, mixed>
     */
    private function computeNewcomerWelcome(User $user): array
    {
        return app(DashboardNewcomerService::class)->computeWelcomeData($user);
    }

    /**
     * @return array<string, mixed>
     */
    private function computeProgressTracker(User $user): array
    {
        return app(DashboardNewcomerService::class)->computeProgressTracker($user);
    }

    /**
     * @return array<string, mixed>
     */
    private function computeNearbyPeople(User $user, string $geohash4): array
    {
        return app(DashboardNewcomerService::class)->computeNearbyPeople($user, $geohash4);
    }

    /**
     * Get newcomer preference-weighted matches via cache.
     *
     * Follows the same three-tier pattern as other newcomer sections.
     * Delegates compute to DashboardNewcomerService::computePreferenceWeightedMatches.
     *
     * @return array<string, mixed>
     */
    public function getNewcomerMatches(User $user, string $geohash4): array
    {
        $cacheKey = "dashboard:newcomer_matches:{$user->id}:{$geohash4}";

        $cached = $this->getArrayFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        Log::debug('dashboard.cache_miss', [
            'section' => 'newcomer_matches',
            'user_id' => $user->id,
            'geohash_4' => $geohash4,
            'cache_key' => $cacheKey,
        ]);

        $data = $this->computeWithLock(
            "dashboard:compute:newcomer_matches:{$user->id}",
            $cacheKey,
            self::TTL_NEWCOMER_MATCHES,
            fn () => $this->computeNewcomerMatches($user, $geohash4),
        );

        $this->trackNewcomerMatchesKey((string) $user->id, $cacheKey);

        WarmDashboardCache::dispatch((string) $user->id, 'cache_miss_newcomer_matches');

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function computeNewcomerMatches(User $user, string $geohash4): array
    {
        return app(DashboardNewcomerService::class)->computePreferenceWeightedMatches($user, $geohash4);
    }

    /**
     * @return array<string, mixed>
     */
    private function computeHostAgain(User $user): array
    {
        // Compute directly to avoid circular dependency with DashboardScheduleService::getHostAgainBridge
        // which calls back into DashboardCacheService::getHostAgain.
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function computeMilestoneCards(User $user): array
    {
        // Compute directly to avoid circular dependency with DashboardDiscoveryService::getMilestoneCards
        // which calls back into DashboardCacheService::getMilestoneCards.
        return app(DashboardDiscoveryService::class)->computeMilestoneCardsPublic($user);
    }
}

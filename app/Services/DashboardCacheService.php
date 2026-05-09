<?php

namespace App\Services;

use App\Dto\FeedItem;
use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Enums\RelationshipType;
use App\Jobs\WarmDashboardCache;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Review;
use App\Models\User;
use App\Models\UserRelationship;
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
    // ── TTL constants (seconds) ────────────────────────

    private const TTL_WEEK = 300;        // 5 minutes
    private const TTL_FEED = 900;        // 15 minutes
    private const TTL_TRENDING = 600;    // 10 minutes
    private const TTL_OPPORTUNITIES = 600; // 10 minutes
    private const TTL_CONTRIBUTIONS = 3600; // 1 hour

    // ── Read methods ───────────────────────────────────

    /**
     * Get the user's "this week" dashboard section.
     *
     * Cache key includes the start-of-week date so it naturally rotates weekly.
     */
    public function getWeekData(User $user): array
    {
        $weekKey = now()->startOfWeek()->format('Y-m-d');
        $cacheKey = "dashboard:week:{$user->id}:{$weekKey}";

        $cached = Cache::get($cacheKey);
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

        WarmDashboardCache::dispatch($user->id, 'cache_miss_week');

        return $data;
    }

    /**
     * Get the user's activity feed section.
     *
     * Shows recent activity from followed users and nearby games.
     */
    public function getFeedData(User $user): array
    {
        $cacheKey = "dashboard:feed:{$user->id}";

        $cached = Cache::get($cacheKey);
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

        WarmDashboardCache::dispatch($user->id, 'cache_miss_feed');

        return $data;
    }

    /**
     * Get trending games for a geohash tile.
     *
     * Not user-specific — shared cache key for all users in the same tile.
     */
    public function getTrendingNearby(string $geohash4): array
    {
        $cacheKey = "dashboard:trending:{$geohash4}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        Log::debug('dashboard.cache_miss', [
            'section' => 'trending',
            'geohash_4' => $geohash4,
            'cache_key' => $cacheKey,
        ]);

        $this->warmTrendingNearby($geohash4);

        return Cache::get($cacheKey, ['games' => []]);
    }

    /**
     * Get open-game opportunities for a user within a geohash tile.
     */
    public function getOpportunities(User $user, string $geohash4): array
    {
        $cacheKey = "dashboard:opportunities:{$user->id}:{$geohash4}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        Log::debug('dashboard.cache_miss', [
            'section' => 'opportunities',
            'user_id' => $user->id,
            'geohash_4' => $geohash4,
            'cache_key' => $cacheKey,
        ]);

        $data = $this->computeOpportunities($user, $geohash4);

        Cache::put($cacheKey, $data, self::TTL_OPPORTUNITIES);

        // Track key for invalidation
        $this->trackOpportunityKey($user->id, $cacheKey);

        WarmDashboardCache::dispatch($user->id, 'cache_miss_opportunities');

        return $data;
    }

    /**
     * Get the user's contributions / reliability stats.
     */
    public function getContributions(User $user): array
    {
        $cacheKey = "dashboard:contributions:{$user->id}";

        $cached = Cache::get($cacheKey);
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

        WarmDashboardCache::dispatch($user->id, 'cache_miss_contributions');

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
            $opportunityKeys = Cache::get($trackingKey, []);
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

        foreach ($keysToForget as $key) {
            Cache::forget($key);
        }

        Log::debug('dashboard.cache_invalidated', [
            'user_id' => $userId,
            'sections' => $sections,
            'keys_cleared' => count($keysToForget),
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

        $sections = ['week', 'opportunities'];

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

        foreach ($affectedUserIds as $userId) {
            $this->invalidateForUser((string) $userId, $sections);
        }

        Log::debug('dashboard.game_event_invalidated', [
            'game_id' => $game->id,
            'event' => $event,
            'affected_users' => $affectedUserIds->count(),
        ]);
    }

    // ── Warm methods (called by WarmDashboardCache job) ──

    /**
     * Warm the contributions cache for a user.
     *
     * Computes and stores without synchronous fallback — called from
     * the background job to pre-populate the cache for the next visit.
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
     */
    public function warmOpportunities(User $user, string $geohash4): array
    {
        $cacheKey = "dashboard:opportunities:{$user->id}:{$geohash4}";

        $data = $this->computeOpportunities($user, $geohash4);

        Cache::put($cacheKey, $data, self::TTL_OPPORTUNITIES);

        // Track key for invalidation
        $this->trackOpportunityKey($user->id, $cacheKey);

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
        // Subquery counts confirmed participants for sorting
        $participantCountSubquery = DB::table('game_participants')
            ->selectRaw('COUNT(*)')
            ->whereColumn('game_participants.game_id', 'games.id')
            ->where('game_participants.status', ParticipantStatus::Approved->value);

        $games = Game::query()
            ->select('games.*')
            ->selectSub($participantCountSubquery, 'participant_count')
            ->join('locations', 'games.location_id', '=', 'locations.id')
            ->whereNotNull('locations.latitude')
            ->whereNotNull('locations.longitude')
            ->whereBetween('locations.latitude', [$bounds['minLat'], $bounds['maxLat']])
            ->whereBetween('locations.longitude', [$bounds['minLng'], $bounds['maxLng']])
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
            'games' => $games->map(function ($game) {
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

    // ── Internal helpers ───────────────────────────────

    /**
     * Compute the user's contributions / reliability stats.
     *
     * Queries games hosted, games played, longest campaign, recaps,
     * reviews, and follower count. Data changes slowly so TTL is 1 hour.
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
     * @return array{games: array, campaigns: array, total_available: int}
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
        $participantCountSubquery = DB::table('game_participants')
            ->selectRaw('COUNT(*)')
            ->whereColumn('game_participants.game_id', 'games.id')
            ->where('game_participants.status', ParticipantStatus::Approved->value);

        $games = Game::query()
            ->select('games.*')
            ->selectSub($participantCountSubquery, 'participant_count')
            ->join('locations', 'games.location_id', '=', 'locations.id')
            ->whereNotNull('locations.latitude')
            ->whereNotNull('locations.longitude')
            ->whereBetween('locations.latitude', [$bounds['minLat'], $bounds['maxLat']])
            ->whereBetween('locations.longitude', [$bounds['minLng'], $bounds['maxLng']])
            ->where('games.status', GameStatus::Scheduled->value)
            ->where('games.date_time', '>=', now())
            ->where('games.date_time', '<=', now()->addDays(14))
            ->whereIn('games.game_system_id', $preferredSystemIds)
            ->whereNotIn('games.id', $excludeGameIds)
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
            ->get()
            ->filter(function ($game) {
                // Only games with spots available
                return ($game->max_players - (int) ($game->participant_count ?? 0)) > 0;
            });

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
            ->toArray();

        $ownedCampaignIds = Campaign::where('owner_id', $user->id)->pluck('id')->toArray();
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

        $campaignResults = $campaigns->map(function ($campaign) {
            $participantCount = $campaign->approved_participant_count;

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
            $isPast = $game->date_time->isBefore(now());
            $isHosting = $game->owner_id === $user->id;
            $playerCount = $game->participants->count();

            $userParticipant = $game->participants->firstWhere('user_id', $user->id);

            $gameData = [
                'id' => $game->id,
                'name' => $game->name,
                'date_time' => $game->date_time->toIso8601String(),
                'expected_duration' => $game->expected_duration,
                'status' => $game->status->value,
                'game_system_name' => $game->gameSystem?->name,
                'campaign_name' => $game->campaign?->name,
                'max_players' => $game->max_players,
                'is_past' => $isPast,
                'is_hosting' => $isHosting,
                'player_count' => $playerCount,
                'needs_recap' => $isPast && empty($game->recap) && $isHosting,
                'needs_attendance' => $isPast && !$isHosting && $userParticipant && $userParticipant->attendance_status === null,
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
     * @return array{items: array, source: string, fetched_at: string}
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
        $gameActivities = $feedService->getFeed($user, 20);
        $campaignActivities = $feedService->getCampaignFeed($user, 20);

        // Convert both to FeedItem DTOs
        $gameItems = $feedService->toFeedItems($gameActivities->getCollection());
        $campaignItems = $feedService->toFeedItems($campaignActivities->getCollection());

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
     */
    public function getRecaps(User $user): array
    {
        $cacheKey = "dashboard:recaps:{$user->id}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $data = $this->computeRecaps($user);

        Cache::put($cacheKey, $data, self::TTL_FEED);

        return $data;
    }

    /**
     * Warm the recaps cache (called by WarmDashboardCache job).
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
        $existingKeys = Cache::get($keySetKey, []);

        if (! in_array($cacheKey, $existingKeys)) {
            $existingKeys[] = $cacheKey;
            Cache::put($keySetKey, $existingKeys, self::TTL_OPPORTUNITIES);
        }
    }
}

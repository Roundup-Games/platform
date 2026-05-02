<?php

namespace App\Services;

use App\Jobs\UpdateUserDiscoveryCache;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Discovers nearby users scored by taste compatibility and social proof.
 *
 * Workflow:
 *   Phase 1 — Candidate Retrieval: Find users via geohash tiles (tier expansion).
 *   Phase 2 — Preference Loading: Bulk-load game systems, vibes, teams, follows.
 *   Phase 3 — Scoring: Jaccard similarity on tastes + social overlap, privacy-aware.
 *   Phase 4 — Pagination: Return scored, paginated results.
 *
 * Query budget: ≤ 6 strategic queries per discover() call:
 *   1. Exclusion IDs (blocks + follows for viewer)
 *   2–4. Candidate retrieval (up to 3 tiers, stops early when ≥10 found)
 *   5. Bulk loads: game_system_prefs, vibe_prefs, team_members, candidate follows
 *   6. Viewer preferences (game_systems, vibes, teams) + candidate user models
 */
class PeopleDiscoveryService
{
    private ProfileVisibilityResolver $visibility;

    public function __construct(ProfileVisibilityResolver $visibility)
    {
        $this->visibility = $visibility;
    }

    /**
     * Discover nearby users compatible with the viewer.
     *
     * Reads from cache first. On cache miss, falls back to synchronous
     * computeAndCache() to preserve current UX, then dispatches a background
     * refresh job so subsequent views are faster.
     *
     * @return array{results: LengthAwarePaginator<int, object{user: User, compatibility_score: float, match_reasons: string[], tier: int, distance_km: float}>, status: string}
     */
    public function discover(User $viewer, ?float $lat = null, ?float $lng = null, int $perPage = 12, int $page = 1): array
    {
        // Edge case: viewer has no location
        if ($lat === null || $lng === null) {
            Log::debug('discovery.stats', [
                'viewer_id' => $viewer->id,
                'status' => 'no_location',
                'candidates' => 0,
            ]);

            return [
                'results' => new LengthAwarePaginator([], 0, $perPage, $page),
                'status' => 'no_location',
            ];
        }

        $viewerGeohash = Geohash::tilePrefix($lat, $lng, 4);
        $cacheKey = "people:nearby:{$viewer->id}:{$viewerGeohash}";

        // ── Cache read ──
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            Log::debug('discovery.cache_hit', [
                'viewer_id' => $viewer->id,
                'geohash' => $viewerGeohash,
                'page' => $page,
            ]);

            // Guard against stale cache entries that stored User models
            // instead of user_id. If the first item lacks 'user_id',
            // invalidate and fall through to fresh computation.
            $firstItem = $cached[0] ?? null;
            if ($firstItem && ! isset($firstItem['user_id'])) {
                Log::warning('discovery.stale_cache_format', [
                    'viewer_id' => $viewer->id,
                    'cache_key' => $cacheKey,
                ]);
                Cache::forget($cacheKey);
                // Fall through to fresh computation below
            } else {
                return $this->paginatedFromCache($cached, $perPage, $page);
            }
        }

        // ── Cache miss: synchronous fallback ──
        Log::debug('discovery.cache_miss', [
            'viewer_id' => $viewer->id,
            'geohash' => $viewerGeohash,
        ]);

        $scored = $this->computeAndCache($viewer, $lat, $lng);

        // Dispatch background refresh so next view is faster
        // (uses the viewer's linked location, not guest location)
        if ($viewer->linkedLocation) {
            UpdateUserDiscoveryCache::dispatch($viewer->id, 'cache_miss_refresh');
        }

        // Paginate from the computed results
        $total = count($scored);
        $items = collect($scored)->forPage($page, $perPage)->values();

        return [
            'results' => new LengthAwarePaginator($items, $total, $perPage, $page, [
                'path' => request()?->url(),
            ]),
            'status' => 'ok',
        ];
    }

    /**
     * Build a paginated response from cached user_id-based data.
     *
     * Reconstructs User models from cached user_ids and returns a
     * paginated result set.
     *
     * @param  array[]  $cached  Array of cached items with user_id keys.
     * @return array{results: LengthAwarePaginator, status: string}
     */
    private function paginatedFromCache(array $cached, int $perPage, int $page): array
    {
        $userIds = array_column($cached, 'user_id');
        $users = User::whereIn('id', $userIds)->get()->keyBy('id');

        $items = collect($cached)->map(function (array $cachedItem) use ($users) {
            $user = $users->get($cachedItem['user_id']);
            if (! $user) {
                return null;
            }

            return [
                'user' => $user,
                'compatibility_score' => $cachedItem['compatibility_score'],
                'match_reasons' => $cachedItem['match_reasons'],
                'tier' => $cachedItem['tier'],
                'distance_km' => $cachedItem['distance_km'],
            ];
        })->filter()->values();

        $total = count($cached);
        $paginatedItems = $items->forPage($page, $perPage)->values();

        return [
            'results' => new LengthAwarePaginator($paginatedItems, $total, $perPage, $page, [
                'path' => request()?->url(),
            ]),
            'status' => 'ok',
        ];
    }

    /**
     * Compute and cache discovery results for a user.
     *
     * Contains the full Phase 1–4 pipeline: candidate retrieval, preference
     * loading, scoring, and cache storage. Called by:
     *   - UpdateUserDiscoveryCache job (async cache population)
     *   - discover() on cache miss (synchronous fallback)
     *
     * Invalidates existing cache first, then computes and stores fresh results.
     * Stores user_id instead of User model to prevent deserialization errors.
     *
     * @return array<int, array{user: User, compatibility_score: float, match_reasons: string[], tier: int, distance_km: float}> Scored results sorted by compatibility descending.
     */
    public function computeAndCache(User $viewer, float $lat, float $lng): array
    {
        $viewerGeohash = Geohash::tilePrefix($lat, $lng, 4);
        $cacheKey = "people:nearby:{$viewer->id}:{$viewerGeohash}";
        $ttl = now()->addMinutes(5);

        // Invalidate any stale cache entries first
        self::invalidateCacheFor($viewer->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        // Phase 1 — Candidate retrieval with tier expansion
        [$candidateRows, $tierMap] = $this->retrieveCandidates($viewer, $viewerGeohash);

        if ($candidateRows->isEmpty()) {
            // Store empty cache to prevent repeated empty computations
            Cache::put($cacheKey, [], $ttl);
            $this->trackCacheKey($viewer->id, $cacheKey, $ttl);

            if ($queryCount > 10) {
                Log::warning('discovery.query_count_high', [
                    'viewer_id' => $viewer->id,
                    'query_count' => $queryCount,
                    'candidates' => 0,
                ]);
            }

            Log::debug('discovery.stats', [
                'viewer_id' => $viewer->id,
                'candidates' => 0,
                'queries' => $queryCount,
            ]);

            return [];
        }

        $candidateIds = $candidateRows->pluck('id')->all();
        $candidateLocationMap = [];
        foreach ($candidateRows as $row) {
            $candidateLocationMap[$row->id] = [
                'lat' => (float) $row->latitude,
                'lng' => (float) $row->longitude,
            ];
        }

        // Eager-load candidate users (1 query)
        $candidateUsers = User::whereIn('id', $candidateIds)->get()->keyBy('id');

        // Phase 2 — Bulk preference loading (4 queries via raw DB)
        $gameSystemPrefs = $this->loadGameSystemPreferences($candidateIds);
        $vibePrefs = $this->loadVibePreferences($candidateIds);
        $teamMemberships = $this->loadTeamMemberships($candidateIds);
        $candidateFollows = $this->loadCandidateFollows($candidateIds);

        // Viewer's own preferences (4 queries — could be pre-cached by caller)
        $viewerGameIds = $viewer->favoriteGameSystems()->pluck('game_system_id')->all();
        $viewerVibes = $viewer->favoriteVibes()->pluck('vibe_preference_value')
            ->map(fn ($flag) => $flag->value)->unique()->values()->all();
        $viewerTeamIds = $viewer->teams()->wherePivot('status', 'active')->pluck('teams.id')->all();
        $viewerFollows = UserRelationship::where('user_id', $viewer->id)
            ->where('type', 'follow')
            ->pluck('related_user_id')
            ->all();

        // Phase 3 — Scoring
        $scored = collect();
        foreach ($candidateIds as $userId) {
            $candidate = $candidateUsers->get($userId);
            if (! $candidate) {
                continue;
            }

            $result = $this->scoreCandidate(
                $viewer,
                $candidate,
                $lat,
                $lng,
                $tierMap[$userId] ?? 1,
                $candidateLocationMap[$userId] ?? null,
                $gameSystemPrefs[$userId] ?? [],
                $vibePrefs[$userId] ?? [],
                $teamMemberships[$userId] ?? [],
                $viewerFollows,
                $candidateFollows[$userId] ?? [],
                $viewerGameIds,
                $viewerVibes,
                $viewerTeamIds,
            );

            if ($result !== null) {
                $scored->push($result);
            }
        }

        $scored = $scored->sortByDesc('compatibility_score')->values();

        // Store the full scored set as user_id-based cache entries.
        // This happens unconditionally (not page-dependent) since the job
        // populates the full result set.
        $cacheable = $scored->map(fn (array $item) => [
            'user_id' => $item['user']->id,
            'compatibility_score' => $item['compatibility_score'],
            'match_reasons' => $item['match_reasons'],
            'tier' => $item['tier'],
            'distance_km' => $item['distance_km'],
        ])->all();

        Cache::put($cacheKey, $cacheable, $ttl);
        $this->trackCacheKey($viewer->id, $cacheKey, $ttl);

        $total = $scored->count();

        if ($queryCount > 10) {
            Log::warning('discovery.query_count_high', [
                'viewer_id' => $viewer->id,
                'query_count' => $queryCount,
                'candidates' => $total,
            ]);
        }

        Log::debug('discovery.stats', [
            'viewer_id' => $viewer->id,
            'candidates' => $total,
            'queries' => $queryCount,
            'tier_distribution' => array_count_values(array_values($tierMap)),
        ]);

        return $scored->all();
    }

    /**
     * Track a cache key in the user's key set for later invalidation.
     */
    private function trackCacheKey(string $userId, string $cacheKey, $ttl): void
    {
        $keySetKey = "people:nearby:keys:{$userId}";
        $existingKeys = Cache::get($keySetKey, []);
        if (! in_array($cacheKey, $existingKeys)) {
            $existingKeys[] = $cacheKey;
            Cache::put($keySetKey, $existingKeys, $ttl);
        }
    }

    /**
     * Invalidate the discovery cache for a user.
     *
     * Called after follow/unfollow/block/unblock actions that change
     * the candidate pool for the given user.
     */
    public static function invalidateCacheFor(string $userId): void
    {
        $keySetKey = "people:nearby:keys:{$userId}";
        $keys = Cache::get($keySetKey, []);

        foreach ($keys as $key) {
            Cache::forget($key);
        }

        Cache::forget($keySetKey);

        Log::debug('discovery.cache_invalidated', [
            'user_id' => $userId,
            'keys_cleared' => count($keys),
        ]);
    }

    /**
     * Phase 1: Retrieve candidate users via geohash tiles.
     *
     * Tries geohash_4 prefix (tier 1). If < 10 results, expands to geohash_3
     * (tier 2). If still < 10, expands to geohash_2 (tier 3).
     *
     * Excludes: self, blocked users (both directions), existing follows,
     * incomplete profiles, disabled users.
     *
     * @return array{0: Collection<int, object>, 1: array<int, int>}
     */
    private function retrieveCandidates(User $viewer, string $viewerGeohash): array
    {
        // Single query for all exclusion IDs (blocks + follows)
        $excludedIds = $this->getExcludedUserIds($viewer);

        $tiers = [
            $viewerGeohash => 1,
            substr($viewerGeohash, 0, 3) => 2,
            substr($viewerGeohash, 0, 2) => 3,
        ];

        $found = collect();
        $tierMap = [];

        foreach ($tiers as $prefix => $tier) {
            if ($found->count() >= 10 && $tier > 1) {
                break;
            }

            $rows = DB::table('users')
                ->join('locations', 'users.location_id', '=', 'locations.id')
                ->where('locations.geohash_4', 'LIKE', $prefix . '%')
                ->whereNotIn('users.id', $excludedIds)
                ->where('users.profile_complete', true)
                ->where(function ($q) {
                    $q->where('users.is_disabled', false)
                      ->orWhereNull('users.is_disabled');
                })
                ->select('users.id', 'locations.latitude', 'locations.longitude')
                ->get();

            foreach ($rows as $row) {
                if (! isset($tierMap[$row->id])) {
                    $tierMap[$row->id] = $tier;
                    $found->push($row);
                }
            }

            if ($found->count() >= 10) {
                break;
            }
        }

        return [$found, $tierMap];
    }

    /**
     * Get user IDs to exclude: self, blocked (both directions), existing follows.
     *
     * Single query via UNION to fetch both blocks and follows for the viewer.
     */
    private function getExcludedUserIds(User $viewer): array
    {
        // Single query: all user relationships involving the viewer
        $rows = DB::table('user_relationships')
            ->where(function ($q) use ($viewer) {
                $q->where('user_id', $viewer->id)
                  ->orWhere('related_user_id', $viewer->id);
            })
            ->select('user_id', 'related_user_id', 'type')
            ->get();

        $ids = [$viewer->id];

        foreach ($rows as $row) {
            if ($row->type === 'block') {
                // Both directions of blocking
                if ($row->user_id != $viewer->id) {
                    $ids[] = $row->user_id;
                }
                if ($row->related_user_id != $viewer->id) {
                    $ids[] = $row->related_user_id;
                }
            } elseif ($row->type === 'follow' && $row->user_id == $viewer->id) {
                // Viewer follows this person — exclude
                $ids[] = $row->related_user_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Load favorite game system IDs for all candidates.
     *
     * @param  int[]  $candidateIds
     * @return array<int, int[]> userId => [gameSystemId, ...]
     */
    private function loadGameSystemPreferences(array $candidateIds): array
    {
        $rows = DB::table('user_game_system_preferences')
            ->whereIn('user_id', $candidateIds)
            ->where('preference_type', 'favorite')
            ->select('user_id', 'game_system_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->user_id][] = $row->game_system_id;
        }

        return $map;
    }

    /**
     * Load favorite vibe values for all candidates.
     *
     * @param  int[]  $candidateIds
     * @return array<int, string[]> userId => [vibeValue, ...]
     */
    private function loadVibePreferences(array $candidateIds): array
    {
        $rows = DB::table('user_vibe_preferences')
            ->whereIn('user_id', $candidateIds)
            ->where('preference_type', 'favorite')
            ->select('user_id', 'vibe_preference_value')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->user_id][] = $row->vibe_preference_value;
        }

        return $map;
    }

    /**
     * Load active team memberships for candidates.
     *
     * @param  int[]  $candidateIds
     * @return array<int, int[]> userId => [teamId, ...]
     */
    private function loadTeamMemberships(array $candidateIds): array
    {
        $rows = DB::table('team_members')
            ->whereIn('user_id', $candidateIds)
            ->where('status', 'active')
            ->select('user_id', 'team_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->user_id][] = $row->team_id;
        }

        return $map;
    }

    /**
     * Load outgoing follows for all candidates (for mutual follow detection).
     *
     * @param  int[]  $candidateIds
     * @return array<int, int[]> userId => [followedUserId, ...]
     */
    private function loadCandidateFollows(array $candidateIds): array
    {
        $rows = DB::table('user_relationships')
            ->whereIn('user_id', $candidateIds)
            ->where('type', 'follow')
            ->select('user_id', 'related_user_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->user_id][] = $row->related_user_id;
        }

        return $map;
    }

    /**
     * Score a single candidate against the viewer.
     *
     * Privacy-aware: checks ProfileVisibilityResolver to determine which
     * candidate fields are visible, and reweights scores when components
     * are hidden.
     */
    private function scoreCandidate(
        User $viewer,
        User $candidate,
        float $viewerLat,
        float $viewerLng,
        int $tier,
        ?array $candidateLocation,
        array $candidateGameIds,
        array $candidateVibes,
        array $candidateTeamIds,
        array $viewerFollows,
        array $candidateFollowsOut,
        array $viewerGameIds,
        array $viewerVibes,
        array $viewerTeamIds,
    ): ?array {
        // Privacy check: candidate must allow location visibility to viewer
        $visibleFields = $this->visibility->profileFieldsVisible($viewer, $candidate);
        if (! in_array('location', $visibleFields)) {
            return null;
        }

        // Compute distance
        $distanceKm = 0.0;
        if ($candidateLocation) {
            $distanceKm = $this->haversineDistance(
                $viewerLat, $viewerLng,
                $candidateLocation['lat'], $candidateLocation['lng']
            );
        }

        // ── Taste score ──
        $tasteComponents = [];
        $matchReasons = [];

        $gameSystemsVisible = in_array('game_systems', $visibleFields);
        $vibesVisible = in_array('vibes', $visibleFields);

        if ($gameSystemsVisible && ! empty($viewerGameIds) && ! empty($candidateGameIds)) {
            $jaccard = $this->jaccard($viewerGameIds, $candidateGameIds);
            $tasteComponents[] = $jaccard;
            if ($jaccard > 0) {
                $matchReasons[] = 'shared_game_systems';
            }
        }

        if ($vibesVisible && ! empty($viewerVibes) && ! empty($candidateVibes)) {
            $jaccard = $this->jaccard($viewerVibes, $candidateVibes);
            $tasteComponents[] = $jaccard;
            if ($jaccard > 0) {
                $matchReasons[] = 'shared_vibes';
            }
        }

        $tasteScore = ! empty($tasteComponents)
            ? array_sum($tasteComponents) / count($tasteComponents)
            : 0.0;

        // ── Social score ──
        $socialComponents = [];

        $teamsVisible = in_array('teams', $visibleFields);
        $friendsListVisible = in_array('friends_list', $visibleFields);

        if ($teamsVisible && ! empty($viewerTeamIds) && ! empty($candidateTeamIds)) {
            $shared = count(array_intersect($viewerTeamIds, $candidateTeamIds));
            $socialComponents[] = min($shared / max(count($viewerTeamIds), 1), 1.0);
            if ($shared > 0) {
                $matchReasons[] = 'shared_teams';
            }
        }

        if ($friendsListVisible) {
            $viewerFollowsCandidate = in_array($candidate->id, $viewerFollows);
            $candidateFollowsViewer = in_array($viewer->id, $candidateFollowsOut);
            if ($viewerFollowsCandidate && $candidateFollowsViewer) {
                $socialComponents[] = 1.0;
                $matchReasons[] = 'mutual_follow';
            }
        }

        $socialScore = ! empty($socialComponents)
            ? array_sum($socialComponents) / count($socialComponents)
            : 0.0;

        // ── Composite with reweighting ──
        // When only one signal type is available, it gets 100% weight.
        $hasTaste = ! empty($tasteComponents);
        $hasSocial = ! empty($socialComponents);

        $compositeScore = 0.0;
        if ($hasTaste && $hasSocial) {
            $compositeScore = ($tasteScore * 0.7) + ($socialScore * 0.3);
        } elseif ($hasTaste) {
            $compositeScore = $tasteScore;
        } elseif ($hasSocial) {
            $compositeScore = $socialScore;
        }

        // Candidate with all signals hidden (only location visible) gets
        // score 0 and 'Nearby' as sole match reason so the UI can show
        // something meaningful instead of a blank card.
        if (empty($matchReasons)) {
            $matchReasons[] = 'Nearby';
        }

        return [
            'user' => $candidate,
            'compatibility_score' => round($compositeScore, 4),
            'match_reasons' => array_unique($matchReasons),
            'tier' => $tier,
            'distance_km' => round($distanceKm, 2),
        ];
    }

    /**
     * Compute Jaccard similarity between two arrays.
     *
     * J(A, B) = |A ∩ B| / |A ∪ B|
     */
    private function jaccard(array $a, array $b): float
    {
        if (empty($a) && empty($b)) {
            return 0.0;
        }

        $setA = array_flip($a);
        $setB = array_flip($b);

        $intersection = count(array_intersect_key($setA, $setB));
        $union = count($setA) + count($setB) - $intersection;

        if ($union === 0) {
            return 0.0;
        }

        return $intersection / $union;
    }

    /**
     * Approximate distance between two points using the Haversine formula (km).
     */
    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * asin(sqrt($a));
    }
}

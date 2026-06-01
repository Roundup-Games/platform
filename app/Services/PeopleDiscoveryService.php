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
 * Architecture (v2 — cache-only reads, SQL-first scoring):
 *
 *   discover() is cache-only: returns cached results or a "pending" status.
 *   It never computes synchronously. The caller (PeoplePage) dispatches a
 *   background warm-up job on first visit and uses wire:poll to hydrate.
 *
 *   computeAndCache() is the heavy method, called only by the background job:
 *
 *   Phase 1 — Candidate Retrieval: SQL with exclusion subquery + hard LIMIT.
 *             Geohash tier via CASE. Taste-based supplement via game-system overlap.
 *
 *   Phase 2 — Scoring: Single SQL JOIN computes game/vibe/team overlap and
 *             mutual-follow status. PHP computes Jaccard + composite (≤100 rows).
 *
 *   Phase 3 — Privacy + Distance: Hydrate User models for scored candidates,
 *             filter by profile visibility, compute haversine distance.
 *
 *   Phase 4 — Cache: Store user_id-based results for paginated reads.
 *
 * Memory budget: ≤ 100 candidate rows × 6 INT columns ≈ 5 KB,
 * plus ≤ 100 User models for final hydration. No bulk relationship loading.
 */
class PeopleDiscoveryService
{
    private ProfileVisibilityResolver $visibility;

    /**
     * Maximum candidates to retrieve and score.
     * Hard cap prevents unbounded memory growth on dense datasets.
     */
    private const MAX_CANDIDATES = 100;

    /**
     * Maximum candidates from geohash tiles before supplementing with taste-based.
     */
    private const MAX_GEO_CANDIDATES = 50;

    /**
     * Maximum taste-based supplement candidates.
     */
    private const MAX_TASTE_CANDIDATES = 20;

    public function __construct(ProfileVisibilityResolver $visibility)
    {
        $this->visibility = $visibility;
    }

    // ── Public API ────────────────────────────────────

    /**
     * Discover nearby users compatible with the viewer.
     *
     * CACHE-ONLY: returns cached results or a "pending" status.
     * Never triggers synchronous computation. The caller should
     * dispatch UpdateUserDiscoveryCache to warm the cache, then
     * poll via wire:poll until results are available.
     *
     * @return array{results: LengthAwarePaginator, status: string}
     *   status is one of: 'ok', 'pending', 'no_location'
     */
    public function discover(User $viewer, ?float $lat = null, ?float $lng = null, int $perPage = 12, int $page = 1): array
    {
        if ($lat === null || $lng === null) {
            return [
                'results' => new LengthAwarePaginator([], 0, $perPage, $page),
                'status' => 'no_location',
            ];
        }

        $viewerGeohash = Geohash::tilePrefix($lat, $lng, 4);
        $cacheKey = "people:nearby:{$viewer->id}:{$viewerGeohash}";

        $cached = Cache::get($cacheKey);

        // Cache miss → pending (caller will dispatch warm-up job)
        if ($cached === null) {
            Log::debug('discovery.cache_miss_pending', [
                'viewer_id' => $viewer->id,
                'geohash' => $viewerGeohash,
            ]);

            return [
                'results' => new LengthAwarePaginator([], 0, $perPage, $page),
                'status' => 'pending',
            ];
        }

        // Guard against stale cache entries that stored User models
        $firstItem = $cached[0] ?? null;
        if ($firstItem && ! isset($firstItem['user_id'])) {
            Log::warning('discovery.stale_cache_format', [
                'viewer_id' => $viewer->id,
                'cache_key' => $cacheKey,
            ]);
            Cache::forget($cacheKey);

            return [
                'results' => new LengthAwarePaginator([], 0, $perPage, $page),
                'status' => 'pending',
            ];
        }

        return $this->paginatedFromCache($cached, $perPage, $page);
    }

    /**
     * Check whether a warm-up job should be dispatched for this viewer.
     *
     * Returns true if:
     *   - The cache is cold (no entry exists), AND
     *   - The user has a location set
     *
     * This prevents re-dispatching on every poll while the job is running.
     * The ShouldBeUnique trait on the job also provides deduplication, but
     * this avoids the dispatch overhead entirely.
     */
    public function shouldWarmCache(User $viewer, ?float $lat, ?float $lng): bool
    {
        if ($lat === null || $lng === null) {
            return false;
        }

        $viewerGeohash = Geohash::tilePrefix($lat, $lng, 4);
        $cacheKey = "people:nearby:{$viewer->id}:{$viewerGeohash}";

        return Cache::get($cacheKey) === null;
    }

    /**
     * Compute and cache discovery results for a user.
     *
     * Called exclusively by UpdateUserDiscoveryCache (background job).
     * SQL-first pipeline:
     *   1. retrieveCandidateIds() — SQL with exclusion subquery, hard LIMIT
     *   2. computeScores() — Single SQL JOIN for all taste/social signals
     *   3. Hydrate User models, privacy filter, distance calc
     *   4. Cache user_id-based results
     *
     * @return array<int, array{user: User, compatibility_score: float, match_reasons: string[], tier: int, distance_km: float}>
     */
    public function computeAndCache(User $viewer, float $lat, float $lng): array
    {
        $viewerGeohash = Geohash::tilePrefix($lat, $lng, 4);
        $cacheKey = "people:nearby:{$viewer->id}:{$viewerGeohash}";
        $ttl = now()->addMinutes(5);

        self::invalidateCacheFor($viewer->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        // Phase 1 — Candidate retrieval via SQL (bounded)
        $candidateMeta = $this->retrieveCandidateIds($viewer, $viewerGeohash);

        if (empty($candidateMeta)) {
            Cache::put($cacheKey, [], $ttl);
            $this->trackCacheKey($viewer->id, $cacheKey, $ttl);

            Log::debug('discovery.stats', [
                'viewer_id' => $viewer->id,
                'candidates' => 0,
                'queries' => $queryCount,
            ]);

            return [];
        }

        $candidateIds = array_keys($candidateMeta);

        // Phase 2 — Score candidates via single SQL JOIN query
        $scoredRows = $this->computeScores($viewer, $candidateIds);

        // Phase 3 — Hydrate User models for scored candidates only (≤ MAX_CANDIDATES)
        $scoredUserIds = array_column($scoredRows, 'user_id');
        $candidateUsers = User::whereIn('id', $scoredUserIds)
            ->whereNull('anonymized_at')
            ->get()
            ->keyBy('id');

        // Pre-load viewer preferences for privacy-aware reweighting
        $viewerGameIds = $viewer->favoriteGameSystems()->pluck('game_system_id')->all();
        $viewerVibes = $viewer->favoriteVibes()->pluck('vibe_preference_value')
            ->map(fn ($flag) => $flag->value)->unique()->values()->all();
        $viewerTeamIds = $viewer->teams()->wherePivot('status', 'active')->pluck('teams.id')->all();

        // Build final results with privacy-aware scoring + distance
        $results = [];
        foreach ($scoredRows as $row) {
            $candidate = $candidateUsers->get($row['user_id']);
            if (! $candidate) {
                continue;
            }

            $visibleFields = $this->visibility->profileFieldsVisible($viewer, $candidate);
            if (! in_array('location', $visibleFields)) {
                continue;
            }

            $result = $this->applyPrivacyReweight(
                $row, $visibleFields,
                $viewerGameIds, $viewerVibes, $viewerTeamIds,
            );

            $meta = $candidateMeta[$row['user_id']] ?? null;
            $distanceKm = 0.0;
            if ($meta) {
                $distanceKm = $this->haversineDistance(
                    $lat, $lng,
                    $meta['latitude'], $meta['longitude'],
                );
            }

            $results[] = [
                'user' => $candidate,
                'compatibility_score' => $result['compatibility_score'],
                'match_reasons' => $result['match_reasons'],
                'tier' => $meta['tier'] ?? 4,
                'distance_km' => round($distanceKm, 2),
            ];
        }

        usort($results, fn (array $a, array $b) => $b['compatibility_score'] <=> $a['compatibility_score']);

        // Cache as user_id-based entries
        $cacheable = array_map(fn (array $item) => [
            'user_id' => $item['user']->id,
            'compatibility_score' => $item['compatibility_score'],
            'match_reasons' => $item['match_reasons'],
            'tier' => $item['tier'],
            'distance_km' => $item['distance_km'],
        ], $results);

        Cache::put($cacheKey, $cacheable, $ttl);
        $this->trackCacheKey($viewer->id, $cacheKey, $ttl);

        $total = count($results);

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
            'tier_distribution' => array_count_values(
                array_map(fn (array $m) => $m['tier'], $candidateMeta)
            ),
        ]);

        return $results;
    }

    /**
     * Invalidate the discovery cache for a user.
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

    // ── Phase 1: Candidate Retrieval ──────────────────

    /**
     * Retrieve candidate user IDs via SQL with exclusion subquery.
     *
     * Single SQL query with:
     *   - Exclusion via subquery (no PHP bulk loading of relationships)
     *   - Tier assignment via CASE on geohash prefix
     *   - Hard LIMIT to prevent unbounded growth
     *
     * Then supplements with taste-based candidates.
     *
     * @return array<int, array{latitude: float, longitude: float, tier: int}>
     */
    private function retrieveCandidateIds(User $viewer, string $viewerGeohash): array
    {
        $geohash4 = $viewerGeohash;
        $geohash3 = substr($viewerGeohash, 0, 3);

        $rows = DB::select("
            SELECT u.id, l.latitude, l.longitude,
                CASE
                    WHEN l.geohash_4 LIKE ? THEN 1
                    WHEN l.geohash_4 LIKE ? THEN 2
                    ELSE 3
                END AS tier
            FROM users u
            JOIN locations l ON u.location_id = l.id
            WHERE (l.geohash_4 LIKE ? OR l.geohash_4 LIKE ?)
              AND u.id NOT IN (
                  SELECT related_user_id FROM user_relationships
                  WHERE user_id = ? AND type IN ('follow', 'block')
                  UNION
                  SELECT user_id FROM user_relationships
                  WHERE related_user_id = ? AND type = 'block'
                  UNION SELECT ?
              )
              AND u.profile_complete IS TRUE
              AND (u.is_disabled IS NOT TRUE)
              AND u.anonymized_at IS NULL
            ORDER BY tier ASC
            LIMIT ?
        ", [
            $geohash4 . '%', $geohash3 . '%',
            $geohash4 . '%', $geohash3 . '%',
            $viewer->id, $viewer->id, $viewer->id,
            self::MAX_GEO_CANDIDATES,
        ]);

        $candidates = [];
        foreach ($rows as $row) {
            $candidates[$row->id] = [
                'latitude' => (float) $row->latitude,
                'longitude' => (float) $row->longitude,
                'tier' => (int) $row->tier,
            ];
        }

        if (count($candidates) < self::MAX_CANDIDATES) {
            $candidates = $this->supplementWithTasteCandidates($viewer, $candidates);
        }

        if (count($candidates) > self::MAX_CANDIDATES) {
            $candidates = array_slice($candidates, 0, self::MAX_CANDIDATES, true);
        }

        return $candidates;
    }

    /**
     * Supplement candidates with users sharing favorite game systems.
     *
     * @param  array<int, array{latitude: float, longitude: float, tier: int}>  $existing
     * @return array<int, array{latitude: float, longitude: float, tier: int}>
     */
    private function supplementWithTasteCandidates(User $viewer, array $existing): array
    {
        $viewerGameIds = $viewer->favoriteGameSystems()->pluck('game_system_id')->all();
        if (empty($viewerGameIds)) {
            return $existing;
        }

        $existingIds = array_keys($existing);
        $viewerId = $viewer->id;

        $existingSql = '';
        $existingParams = [];
        if (! empty($existingIds)) {
            $existingPlaceholders = implode(',', array_fill(0, count($existingIds), '?'));
            $existingSql = "AND ugs.user_id NOT IN ({$existingPlaceholders})";
            $existingParams = $existingIds;
        }

        $gamePlaceholders = implode(',', array_fill(0, count($viewerGameIds), '?'));

        $tasteRows = DB::select("
            SELECT DISTINCT ugs.user_id
            FROM user_game_system_preferences ugs
            INNER JOIN users u ON u.id = ugs.user_id
            WHERE ugs.game_system_id IN ({$gamePlaceholders})
              AND ugs.preference_type = 'favorite'
              AND u.profile_complete IS TRUE
              AND (u.is_disabled IS NOT TRUE)
              AND u.anonymized_at IS NULL
              AND ugs.user_id NOT IN (
                  SELECT related_user_id FROM user_relationships
                  WHERE user_id = ? AND type IN ('follow', 'block')
                  UNION
                  SELECT user_id FROM user_relationships
                  WHERE related_user_id = ? AND type = 'block'
                  UNION SELECT ?
              )
              {$existingSql}
            LIMIT ?
        ", array_merge(
            $viewerGameIds,
            [$viewerId, $viewerId, $viewerId],
            $existingParams,
            [self::MAX_TASTE_CANDIDATES],
        ));

        $newIds = array_map(fn (\stdClass $row) => $row->user_id, $tasteRows);

        if (empty($newIds)) {
            return $existing;
        }

        $newPlaceholders = implode(',', array_fill(0, count($newIds), '?'));
        $locRows = DB::select("
            SELECT u.id, l.latitude, l.longitude
            FROM users u
            LEFT JOIN locations l ON u.location_id = l.id
            WHERE u.id IN ({$newPlaceholders})
        ", $newIds);

        foreach ($locRows as $row) {
            $id = $row->id;
            if (! isset($existing[$id])) {
                $existing[$id] = [
                    'latitude' => (float) ($row->latitude ?? 0),
                    'longitude' => (float) ($row->longitude ?? 0),
                    'tier' => 4,
                ];
            }
        }

        return $existing;
    }

    // ── Phase 2: Scoring ──────────────────────────────

    /**
     * Compute taste and social overlap scores for all candidates in one SQL query.
     *
     * Uses LEFT JOINs with conditional aggregation:
     *   - shared_games / candidate_game_count
     *   - shared_vibes / candidate_vibe_count
     *   - shared_teams
     *   - mutual_follow
     *
     * @param  int[]  $candidateIds
     * @return array<int, array{user_id: int, compatibility_score: float, match_reasons: string[], tier: int, shared_games: int, candidate_game_count: int, shared_vibes: int, candidate_vibe_count: int, shared_teams: int, mutual_follow: bool}>
     */
    private function computeScores(User $viewer, array $candidateIds): array
    {
        if (empty($candidateIds)) {
            return [];
        }

        $viewerId = $viewer->id;

        // Pre-load viewer preferences (3 tiny queries)
        $viewerGameIds = $viewer->favoriteGameSystems()->pluck('game_system_id')->all();
        $viewerVibes = $viewer->favoriteVibes()->pluck('vibe_preference_value')
            ->map(fn ($flag) => $flag->value)->unique()->values()->all();
        $viewerTeamIds = $viewer->teams()->wherePivot('status', 'active')->pluck('teams.id')->all();

        $viewerGameCount = count($viewerGameIds);
        $viewerVibeCount = count($viewerVibes);

        [$sql, $params] = $this->buildScoreSQL(
            $viewerId, $viewerGameIds, $viewerVibes, $viewerTeamIds, $candidateIds,
        );

        $rows = DB::select($sql, $params);

        $results = [];
        foreach ($rows as $row) {
            $sharedGames = (int) $row->shared_games;
            $candidateGameCount = (int) $row->candidate_game_count;
            $sharedVibes = (int) $row->shared_vibes;
            $candidateVibeCount = (int) $row->candidate_vibe_count;
            $sharedTeams = (int) $row->shared_teams;
            $mutualFollow = (bool) $row->mutual_follow;

            $tasteComponents = [];
            $matchReasons = [];

            if ($sharedGames > 0 && $viewerGameCount > 0) {
                $union = $viewerGameCount + $candidateGameCount - $sharedGames;
                $tasteComponents[] = $union > 0 ? $sharedGames / $union : 0;
                $matchReasons[] = 'shared_game_systems';
            }

            if ($sharedVibes > 0 && $viewerVibeCount > 0) {
                $union = $viewerVibeCount + $candidateVibeCount - $sharedVibes;
                $tasteComponents[] = $union > 0 ? $sharedVibes / $union : 0;
                $matchReasons[] = 'shared_vibes';
            }

            $tasteScore = ! empty($tasteComponents)
                ? array_sum($tasteComponents) / count($tasteComponents)
                : 0.0;

            $socialComponents = [];

            if ($sharedTeams > 0) {
                $socialComponents[] = min($sharedTeams / max(count($viewerTeamIds), 1), 1.0);
                $matchReasons[] = 'shared_teams';
            }

            if ($mutualFollow) {
                $socialComponents[] = 1.0;
                $matchReasons[] = 'mutual_follow';
            }

            $socialScore = ! empty($socialComponents)
                ? array_sum($socialComponents) / count($socialComponents)
                : 0.0;

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

            if (empty($matchReasons)) {
                $matchReasons[] = 'Nearby';
            }

            $results[] = [
                'user_id' => (string) $row->user_id,
                'compatibility_score' => round($compositeScore, 4),
                'match_reasons' => $matchReasons,
                'tier' => 0,
                'shared_games' => $sharedGames,
                'candidate_game_count' => $candidateGameCount,
                'shared_vibes' => $sharedVibes,
                'candidate_vibe_count' => $candidateVibeCount,
                'shared_teams' => $sharedTeams,
                'mutual_follow' => $mutualFollow,
            ];
        }

        usort($results, fn (array $a, array $b) => $b['compatibility_score'] <=> $a['compatibility_score']);

        return $results;
    }

    /**
     * Build the dynamic scoring SQL with LEFT JOINs.
     *
     * Each JOIN computes overlap counts via conditional aggregation.
     *
     * @return array{0: string, 1: array} [sql, params]
     */
    private function buildScoreSQL(
        string $viewerId,
        array $viewerGameIds,
        array $viewerVibes,
        array $viewerTeamIds,
        array $candidateIds,
    ): array {
        $candidateCount = count($candidateIds);
        $cPh = implode(',', array_fill(0, $candidateCount, '?'));
        $joins = [];
        $params = [];

        // Game systems JOIN
        if (! empty($viewerGameIds)) {
            $gPh = implode(',', array_fill(0, count($viewerGameIds), '?'));
            $joins[] = "LEFT JOIN (
                SELECT ug.user_id,
                    SUM(CASE WHEN ug.game_system_id IN ({$gPh}) THEN 1 ELSE 0 END) AS shared,
                    COUNT(*) AS candidate_total
                FROM user_game_system_preferences ug
                WHERE ug.user_id IN ({$cPh}) AND ug.preference_type = 'favorite'
                GROUP BY ug.user_id
            ) g ON g.user_id = u.id";
            $params = array_merge($params, $viewerGameIds, $candidateIds);
        } else {
            $joins[] = "LEFT JOIN (SELECT user_id, 0 AS shared, 0 AS candidate_total FROM user_game_system_preferences WHERE 1=0) g ON g.user_id = u.id";
        }

        // Vibes JOIN
        if (! empty($viewerVibes)) {
            $vPh = implode(',', array_fill(0, count($viewerVibes), '?'));
            $joins[] = "LEFT JOIN (
                SELECT uv.user_id,
                    SUM(CASE WHEN uv.vibe_preference_value IN ({$vPh}) THEN 1 ELSE 0 END) AS shared,
                    COUNT(*) AS candidate_total
                FROM user_vibe_preferences uv
                WHERE uv.user_id IN ({$cPh}) AND uv.preference_type = 'favorite'
                GROUP BY uv.user_id
            ) v ON v.user_id = u.id";
            $params = array_merge($params, $viewerVibes, $candidateIds);
        } else {
            $joins[] = "LEFT JOIN (SELECT user_id, 0 AS shared, 0 AS candidate_total FROM user_vibe_preferences WHERE 1=0) v ON v.user_id = u.id";
        }

        // Teams JOIN
        if (! empty($viewerTeamIds)) {
            $tPh = implode(',', array_fill(0, count($viewerTeamIds), '?'));
            $joins[] = "LEFT JOIN (
                SELECT tm.user_id,
                    SUM(CASE WHEN tm.team_id IN ({$tPh}) THEN 1 ELSE 0 END) AS shared
                FROM team_members tm
                WHERE tm.user_id IN ({$cPh}) AND tm.status = 'active'
                GROUP BY tm.user_id
            ) t ON t.user_id = u.id";
            $params = array_merge($params, $viewerTeamIds, $candidateIds);
        } else {
            $joins[] = "LEFT JOIN (SELECT user_id, 0 AS shared FROM team_members WHERE 1=0) t ON t.user_id = u.id";
        }

        // Mutual follow JOIN (targeted: only "does candidate follow viewer?")
        $joins[] = "LEFT JOIN (
            SELECT user_id FROM user_relationships
            WHERE user_id IN ({$cPh}) AND related_user_id = ? AND type = 'follow'
        ) mf ON mf.user_id = u.id";
        $params = array_merge($params, $candidateIds, [$viewerId]);

        $params = array_merge($params, $candidateIds);

        $sql = "
            SELECT
                u.id AS user_id,
                COALESCE(g.shared, 0) AS shared_games,
                COALESCE(g.candidate_total, 0) AS candidate_game_count,
                COALESCE(v.shared, 0) AS shared_vibes,
                COALESCE(v.candidate_total, 0) AS candidate_vibe_count,
                COALESCE(t.shared, 0) AS shared_teams,
                CASE WHEN mf.user_id IS NOT NULL THEN 1 ELSE 0 END AS mutual_follow
            FROM users u
            " . implode("\n", $joins) . "
            WHERE u.id IN ({$cPh})
        ";

        return [$sql, $params];
    }

    // ── Phase 3: Privacy Reweighting ──────────────────

    /**
     * Recompute composite score respecting profile visibility.
     *
     * Hidden fields are excluded from scoring as if the viewer had zero overlap.
     *
     * @return array{compatibility_score: float, match_reasons: string[]}
     */
    private function applyPrivacyReweight(
        array $row,
        array $visibleFields,
        array $viewerGameIds,
        array $viewerVibes,
        array $viewerTeamIds,
    ): array {
        $gameSystemsVisible = in_array('game_systems', $visibleFields);
        $vibesVisible = in_array('vibes', $visibleFields);
        $teamsVisible = in_array('teams', $visibleFields);
        $friendsListVisible = in_array('friends_list', $visibleFields);

        $tasteComponents = [];
        $matchReasons = [];

        if ($gameSystemsVisible && $row['shared_games'] > 0 && ! empty($viewerGameIds)) {
            $union = count($viewerGameIds) + $row['candidate_game_count'] - $row['shared_games'];
            $tasteComponents[] = $union > 0 ? $row['shared_games'] / $union : 0;
            $matchReasons[] = 'shared_game_systems';
        }

        if ($vibesVisible && $row['shared_vibes'] > 0 && ! empty($viewerVibes)) {
            $union = count($viewerVibes) + $row['candidate_vibe_count'] - $row['shared_vibes'];
            $tasteComponents[] = $union > 0 ? $row['shared_vibes'] / $union : 0;
            $matchReasons[] = 'shared_vibes';
        }

        $tasteScore = ! empty($tasteComponents)
            ? array_sum($tasteComponents) / count($tasteComponents)
            : 0.0;

        $socialComponents = [];

        if ($teamsVisible && $row['shared_teams'] > 0 && ! empty($viewerTeamIds)) {
            $socialComponents[] = min($row['shared_teams'] / count($viewerTeamIds), 1.0);
            $matchReasons[] = 'shared_teams';
        }

        if ($friendsListVisible && $row['mutual_follow']) {
            $socialComponents[] = 1.0;
            $matchReasons[] = 'mutual_follow';
        }

        $socialScore = ! empty($socialComponents)
            ? array_sum($socialComponents) / count($socialComponents)
            : 0.0;

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

        if (empty($matchReasons)) {
            $matchReasons[] = 'Nearby';
        }

        return [
            'compatibility_score' => round($compositeScore, 4),
            'match_reasons' => $matchReasons,
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

    // ── Cache Helpers ─────────────────────────────────

    /**
     * Build a paginated response from cached user_id-based data.
     */
    private function paginatedFromCache(array $cached, int $perPage, int $page): array
    {
        $userIds = array_column($cached, 'user_id');
        $users = User::whereIn('id', $userIds)
            ->whereNull('anonymized_at')
            ->get()
            ->keyBy('id');

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

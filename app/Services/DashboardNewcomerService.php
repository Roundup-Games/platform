<?php

namespace App\Services;

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\Concerns\DashboardFormatting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for the newcomer dashboard mode.
 *
 * Provides personalised welcome data, preference-weighted nearby game matches,
 * an onboarding progress tracker, and nearby compatible people preview.
 *
 * Each public method delegates caching to DashboardCacheService, which handles
 * the three-tier pattern (cache read → synchronous fallback → background warm).
 */
class DashboardNewcomerService
{
    use DashboardFormatting;

    /** @var array<string, array<string>> Memoized exclusion lists per user to avoid redundant queries */
    private array $excludedGameIdsCache = [];

    public function __construct(
        private readonly DashboardCacheService $cache,
    ) {}

    // ── Public API ─────────────────────────────────────

    /**
     * Get welcome data for a newcomer user.
     *
     * Returns personalised information for the welcome card: name, city,
     * preferred game systems, count of nearby games matching preferences,
     * and a computed welcome message key.
     */
    public function getWelcomeData(User $user): array
    {
        return $this->cache->getNewcomerWelcome($user);
    }

    /**
     * Get preference-weighted nearby game matches for a newcomer.
     *
     * Queries nearby scheduled games (next 14 days, within geohash tile,
     * not already participating). Scores by preference match, proximity,
     * and spots available. Returns top 6 games with relevance tags.
     *
     * @return array{games: array, total_nearby: int, preference_match_rate: float}
     */
    public function getPreferenceWeightedMatches(User $user, string $geohash4): array
    {
        return $this->cache->getNewcomerMatches($user, $geohash4);
    }

    /**
     * Get the newcomer onboarding progress tracker.
     *
     * Returns a 4-step progress tracker: Profile, Preferences, Find Game,
     * Attend Session. Each step has a completion status and route.
     *
     * @return array{steps: array, current_step: int, completion_percentage: int}
     */
    public function getProgressTracker(User $user): array
    {
        return $this->cache->getProgressTracker($user);
    }

    /**
     * Get nearby compatible people for a newcomer.
     *
     * Queries users in the same geohash tile with public profiles,
     * sorted by taste compatibility (shared game systems).
     *
     * @return array{people: array, total_nearby: int}
     */
    public function getNearbyPeople(User $user, string $geohash4): array
    {
        return $this->cache->getNearbyPeople($user, $geohash4);
    }

    // ── Compute methods (called by DashboardCacheService) ──

    /**
     * Compute welcome data for a newcomer user.
     *
     * @return array{first_name: string, city: string|null, preferred_systems: string[], matching_games_count: int, has_location: bool, welcome_message_key: string}
     */
    public function computeWelcomeData(User $user): array
    {
        $firstName = explode(' ', $user->name ?? '')[0] ?: 'Adventurer';
        $location = $user->linkedLocation;
        $city = $location?->city;
        $hasLocation = $location !== null && $location->latitude !== null && $location->longitude !== null;

        // Top 3 preferred game system names
        $preferredSystems = $user->gameSystemPreferences()
            ->pluck('game_systems.name')
            ->take(3)
            ->values()
            ->toArray();

        // Count nearby games matching preferred systems
        $matchingGamesCount = 0;
        if ($hasLocation) {
            $preferredSystemIds = $user->gameSystemPreferences()->pluck('game_systems.id')->toArray();
            $matchingGamesCount = $this->countMatchingNearbyGames($user, $preferredSystemIds, $location);
        }

        // Compute welcome message key based on available data
        $welcomeMessageKey = $this->resolveWelcomeMessageKey($hasLocation, $preferredSystems, $matchingGamesCount);

        Log::debug('dashboard.newcomer_welcome_computed', [
            'user_id' => $user->id,
            'has_location' => $hasLocation,
            'preferred_systems_count' => count($preferredSystems),
            'matching_games_count' => $matchingGamesCount,
            'welcome_message_key' => $welcomeMessageKey,
        ]);

        return [
            'first_name' => $firstName,
            'city' => $city,
            'preferred_systems' => $preferredSystems,
            'matching_games_count' => $matchingGamesCount,
            'has_location' => $hasLocation,
            'welcome_message_key' => $welcomeMessageKey,
        ];
    }

    /**
     * Compute preference-weighted nearby game matches for a newcomer.
     *
     * Scoring:
     *   - Preference match (preferred system): +50
     *   - Proximity (0–30): closer games score higher
     *   - Spots available (0–20): more spots score higher
     *
     * Each game gets relevance_tags for UI badges:
     *   - matches_your_taste: game uses a preferred system
     *   - popular_nearby: participant count ≥ 3
     *   - filling_fast: ≤ 2 spots remaining
     *   - starting_soon: within 3 days
     *
     * @return array{games: array, total_nearby: int, preference_match_rate: float}
     */
    public function computePreferenceWeightedMatches(User $user, string $geohash4): array
    {
        $preferredSystemIds = $user->gameSystemPreferences()->pluck('game_systems.id')->toArray();
        $bounds = Geohash::prefixBounds($geohash4);

        // Exclude games the user already participates in or owns
        $excludeGameIds = $this->getExcludedGameIds($user);

        // Participant count subquery.
        // +1 accounts for the game owner who does not have a GameParticipant record.
        // This is safe because the join/request flow explicitly blocks owners from
        // creating participant records for their own games (canJoinViaShareLink checks
        // owner_id, ParticipantService rejects self-invites).
        $participantCountSubquery = DB::table('game_participants')
            ->selectRaw('COUNT(*) + 1')
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
            ->whereNotIn('games.id', $excludeGameIds)
            ->visibleTo($user)
            ->where(function ($q) {
                // Only games with available spots (or unlimited capacity).
                // Filtering at SQL level avoids fetching full games only to discard them.
                $q->whereNull('games.max_players')
                    ->orWhereRaw(
                        '(SELECT COUNT(*) + 1 FROM game_participants WHERE game_participants.game_id = games.id AND game_participants.status = ?) < games.max_players',
                        [ParticipantStatus::Approved->value],
                    );
            })
            ->with(['gameSystem', 'owner', 'linkedLocation'])
            ->limit(30)
            ->get();

        // Score each game
        $userLocation = $user->linkedLocation;
        $userLat = $userLocation?->latitude ? (float) $userLocation->latitude : null;
        $userLng = $userLocation?->longitude ? (float) $userLocation->longitude : null;

        $scoredGames = $games->map(function ($game) use ($preferredSystemIds, $userLat, $userLng) {
            $spotsAvailable = $game->max_players - (int) ($game->participant_count ?? 0);
            $participantCount = (int) ($game->participant_count ?? 0);

            // Preference match score (+50 if preferred system)
            $preferenceScore = in_array($game->game_system_id, $preferredSystemIds) ? 50 : 0;

            // Proximity score (0-30)
            $proximityScore = 0;
            $distance = null;
            if ($userLat !== null && $userLng !== null && $game->linkedLocation) {
                $distance = ProximityQuery::haversineDistance(
                    $userLat,
                    $userLng,
                    (float) $game->linkedLocation->latitude,
                    (float) $game->linkedLocation->longitude,
                );
                $proximityScore = max(0, 30 * (1 - min($distance / 40, 1)));
            } else {
                $proximityScore = 15;
            }

            // Spots available score (0-20)
            $spotsScore = min(20, $spotsAvailable * 4);

            $totalScore = $preferenceScore + $proximityScore + $spotsScore;

            // Relevance tags for UI badges
            $relevanceTags = [
                'matches_your_taste' => in_array($game->game_system_id, $preferredSystemIds),
                'popular_nearby' => $participantCount >= 3,
                'filling_fast' => $spotsAvailable <= 2,
                'starting_soon' => $game->date_time !== null && now()->diffInDays($game->date_time, false) <= 3,
            ];

            return [
                'score' => $totalScore,
                'distance_km' => $distance !== null ? round($distance, 1) : null,
                'spots_available' => $spotsAvailable,
                'relevance_tags' => $relevanceTags,
                'game' => $game,
            ];
        });

        $topGames = $scoredGames
            ->sortByDesc('score')
            ->take(6)
            ->values();

        $totalNearby = $games->count();
        $preferenceMatchCount = $topGames->filter(
            fn ($item) => $item['relevance_tags']['matches_your_taste'] === true
        )->count();
        $preferenceMatchRate = $topGames->count() > 0
            ? round($preferenceMatchCount / $topGames->count(), 2)
            : 0.0;

        $gameResults = $topGames->map(function ($item) {
            $game = $item['game'];

            return [
                'id' => $game->id,
                'name' => $game->name,
                'date_time' => $game->date_time?->toIso8601String(),
                'expected_duration' => $game->expected_duration,
                'game_system_name' => $game->gameSystem?->name,
                'game_system_id' => $game->game_system_id,
                'max_players' => $game->max_players,
                'participant_count' => (int) ($game->participant_count ?? 0),
                'spots_available' => $item['spots_available'],
                'distance_km' => $item['distance_km'],
                'owner_name' => $game->owner?->name,
                'location_city' => $game->linkedLocation?->city,
                'relevance_tags' => $item['relevance_tags'],
                'score' => $item['score'],
            ];
        })->toArray();

        Log::debug('dashboard.newcomer_matches_computed', [
            'user_id' => $user->id,
            'geohash_4' => $geohash4,
            'total_nearby' => $totalNearby,
            'returned_count' => count($gameResults),
            'preference_match_rate' => $preferenceMatchRate,
        ]);

        return [
            'games' => $gameResults,
            'total_nearby' => $totalNearby,
            'preference_match_rate' => $preferenceMatchRate,
        ];
    }

    /**
     * Compute the 4-step newcomer onboarding progress tracker.
     *
     * Steps:
     *   1. Profile — complete if profile_complete = true
     *   2. Preferences — complete if user has game system preferences
     *   3. Find Game — complete if user has any game participation (even pending)
     *   4. Attend Session — complete if user has attended a completed game
     *
     * @return array{steps: array, current_step: int, completion_percentage: int}
     */
    public function computeProgressTracker(User $user): array
    {
        $steps = [
            [
                'name' => __('profile.dashboard_newcomer_step_profile'),
                'route' => 'profile.edit',
                'is_complete' => false,
            ],
            [
                'name' => __('profile.dashboard_newcomer_step_preferences'),
                'route' => 'preferences.index',
                'is_complete' => false,
            ],
            [
                'name' => __('profile.dashboard_newcomer_step_find_game'),
                'route' => 'games.index',
                'is_complete' => false,
            ],
            [
                'name' => __('profile.dashboard_newcomer_step_attend_session'),
                'route' => 'games.index',
                'is_complete' => false,
            ],
        ];

        // Step 1: Profile complete
        if ($user->profile_complete) {
            $steps[0]['is_complete'] = true;
        }

        // Step 2: Has game system preferences
        $hasPreferences = $user->gameSystemPreferences()->exists();
        if ($hasPreferences) {
            $steps[1]['is_complete'] = true;
        }

        // Step 3: Has any game participation (any status including pending)
        $hasParticipation = GameParticipant::where('user_id', $user->id)->exists();
        if ($hasParticipation) {
            $steps[2]['is_complete'] = true;
        }

        // Step 4: Has attended a completed game (as participant, not owner)
        $hasAttended = GameParticipant::where('user_id', $user->id)
            ->where('status', ParticipantStatus::Approved->value)
            ->whereHas('game', fn ($q) => $q
                ->where('status', GameStatus::Completed->value)
                ->where('owner_id', '!=', $user->id)
            )
            ->exists();
        if ($hasAttended) {
            $steps[3]['is_complete'] = true;
        }

        // Compute current step and completion percentage
        $completedCount = count(array_filter($steps, fn ($s) => $s['is_complete']));
        $currentStep = min($completedCount + 1, 4);
        $completionPercentage = (int) round(($completedCount / 4) * 100);

        Log::debug('dashboard.progress_tracker_computed', [
            'user_id' => $user->id,
            'completed_steps' => $completedCount,
            'current_step' => $currentStep,
            'completion_percentage' => $completionPercentage,
        ]);

        return [
            'steps' => $steps,
            'current_step' => $currentStep,
            'completion_percentage' => $completionPercentage,
        ];
    }

    /**
     * Compute nearby compatible people for a newcomer.
     *
     * Queries users in the same geohash tile with complete public profiles,
     * excluding self. Sorted by taste compatibility (shared game systems).
     *
     * @return array{people: array, total_nearby: int}
     */
    public function computeNearbyPeople(User $user, string $geohash4): array
    {
        $bounds = Geohash::prefixBounds($geohash4);

        // Viewer's preferred game system IDs
        $viewerSystemIds = $user->gameSystemPreferences()->pluck('game_systems.id')->toArray();

        // Query nearby users with public profiles
        $nearbyUsers = User::query()
            ->join('locations', 'users.location_id', '=', 'locations.id')
            ->whereNotNull('locations.latitude')
            ->whereNotNull('locations.longitude')
            ->whereBetween('locations.latitude', [$bounds['minLat'], $bounds['maxLat']])
            ->whereBetween('locations.longitude', [$bounds['minLng'], $bounds['maxLng']])
            ->where('users.id', '!=', $user->id)
            ->where('users.profile_complete', true)
            ->whereNull('users.anonymized_at')
            ->where(function ($q) {
                $q->where('users.is_disabled', false)
                    ->orWhereNull('users.is_disabled');
            })
            ->select('users.*', 'locations.latitude', 'locations.longitude')
            ->limit(50)
            ->get();

        if ($nearbyUsers->isEmpty()) {
            Log::debug('dashboard.newcomer_people_computed', [
                'user_id' => $user->id,
                'geohash_4' => $geohash4,
                'total_nearby' => 0,
                'returned_count' => 0,
            ]);

            return ['people' => [], 'total_nearby' => 0];
        }

        // Bulk-load game system preferences for all candidates
        $candidateIds = $nearbyUsers->pluck('id')->toArray();
        $candidateSystemPrefs = $this->bulkLoadGameSystemPreferences($candidateIds);

        // Bulk-load all unique game system names to avoid per-candidate queries
        $allSystemIds = collect($candidateSystemPrefs)->flatten()->unique()->filter()->values()->toArray();
        $systemNames = ! empty($allSystemIds)
            ? GameSystem::whereIn('id', $allSystemIds)->pluck('name', 'id')->toArray()
            : [];

        // Score by shared game systems
        $scored = $nearbyUsers->map(function ($candidate) use ($viewerSystemIds, $candidateSystemPrefs, $systemNames) {
            $candidateSystemIds = $candidateSystemPrefs[$candidate->id] ?? [];
            $sharedSystems = array_intersect($viewerSystemIds, $candidateSystemIds);
            $sharedCount = count($sharedSystems);

            // Resolve the candidate's top system name from the bulk-loaded map
            $topSystemName = null;
            if (! empty($candidateSystemIds)) {
                $topSystemName = $systemNames[$candidateSystemIds[0]] ?? null;
            }

            return [
                'id' => $candidate->id,
                'name' => $candidate->name,
                'avatar_url' => $candidate->avatar_url,
                'top_system_name' => $topSystemName,
                'reliability_score' => $candidate->reliability_score,
                'shared_systems_count' => $sharedCount,
                'score' => $sharedCount,
            ];
        });

        $topPeople = $scored
            ->sortByDesc('score')
            ->take(6)
            ->values();

        // Remove internal score from output
        $peopleResults = $topPeople->map(fn ($p) => [
            'id' => $p['id'],
            'name' => $p['name'],
            'avatar_url' => $p['avatar_url'],
            'top_system_name' => $p['top_system_name'],
            'reliability_score' => $p['reliability_score'],
            'shared_systems_count' => $p['shared_systems_count'],
        ])->toArray();

        Log::debug('dashboard.newcomer_people_computed', [
            'user_id' => $user->id,
            'geohash_4' => $geohash4,
            'total_nearby' => $nearbyUsers->count(),
            'returned_count' => count($peopleResults),
        ]);

        return [
            'people' => $peopleResults,
            'total_nearby' => $nearbyUsers->count(),
        ];
    }

    // ── Private helpers ────────────────────────────────

    /**
     * Count nearby games matching the user's preferred game systems.
     */
    private function countMatchingNearbyGames(User $user, array $preferredSystemIds, $location): int
    {
        if (empty($preferredSystemIds) || ! $location?->latitude || ! $location?->longitude) {
            return 0;
        }

        $geohash4 = Geohash::tilePrefix(
            (float) $location->latitude,
            (float) $location->longitude,
            4,
        );
        $bounds = Geohash::prefixBounds($geohash4);

        $excludeGameIds = $this->getExcludedGameIds($user);

        return Game::query()
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
            ->visibleTo($user)
            ->count();
    }

    /**
     * Get game IDs the user already owns or participates in.
     */
    private function getExcludedGameIds(User $user): array
    {
        $cacheKey = $user->id;
        if (isset($this->excludedGameIdsCache[$cacheKey])) {
            return $this->excludedGameIdsCache[$cacheKey];
        }

        $ownedGameIds = Game::where('owner_id', $user->id)->pluck('id');
        $participatingGameIds = GameParticipant::where('user_id', $user->id)
            ->pluck('game_id');

        return $this->excludedGameIdsCache[$cacheKey] = $ownedGameIds->merge($participatingGameIds)->unique()->values()->toArray();
    }

    /**
     * Determine the welcome message key based on available data.
     *
     * Returns one of several keys that map to localized welcome messages.
     */
    private function resolveWelcomeMessageKey(bool $hasLocation, array $preferredSystems, int $matchingGamesCount): string
    {
        if ($hasLocation && ! empty($preferredSystems) && $matchingGamesCount > 0) {
            return 'welcome_with_matches';
        }

        if ($hasLocation && ! empty($preferredSystems)) {
            return 'welcome_no_matches';
        }

        if ($hasLocation) {
            return 'welcome_with_location';
        }

        if (! empty($preferredSystems)) {
            return 'welcome_with_preferences';
        }

        return 'welcome_basic';
    }

}

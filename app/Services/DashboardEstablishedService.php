<?php

namespace App\Services;

use App\Dto\ActionItem;
use App\Dto\ActivityFeedItem;
use App\Dto\FeedItem;
use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Enums\RelationshipType;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Review;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Computer for established-mode Dashboard sections.
 *
 * Provides the pure SQL→array computers behind the week, feed, opportunities,
 * contributions, recaps, action_center, and host_again Dashboard sections.
 * These methods are projections — given a {@see User} (and a geohash tile where
 * proximity is involved) they return a serializable array. The cache lifecycle
 * (read/write/invalidate/warm) is owned entirely by {@see DashboardCacheService},
 * which calls these methods via its section registry's match arm. This service
 * holds NO cache dependency and NO per-request mutable state.
 *
 * Mirrors {@see DashboardNewcomerService}: both are the sibling computers
 * dispatched by {@see DashboardCacheService::dispatchCompute()} via the
 * container, keeping the cache module single-responsibility.
 */
class DashboardEstablishedService
{
    /**
     * Compute the user's contribution summary — hosting, playing, campaigns, recaps, reviews, followers.
     *
     * Pure aggregate queries over completed games and related entities.
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
     * Compute recent recaps from games the user participated in.
     *
     * @return array<int, array<string, mixed>>
     */
    public function computeRecaps(User $user): array
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

        return array_map(fn (Game $game): array => [
            'id' => $game->id,
            'name' => $game->name,
            'owner_name' => $game->owner?->name,
        ], $games->all());
    }

    /**
     * Compute the user's action-center items.
     *
     * Delegates to {@see ActionCenterService} and projects each item to an array.
     *
     * @return array<int, array<string, mixed>>
     */
    public function computeActionCenter(User $user): array
    {
        $items = app(ActionCenterService::class)->getItems($user);

        return array_map(fn (ActionItem $item) => $item->toArray(), $items);
    }

    /**
     * Compute the "host again" suggestion — the user's most recent completed game.
     *
     * @return array<string, mixed>
     */
    public function computeHostAgain(User $user): array
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

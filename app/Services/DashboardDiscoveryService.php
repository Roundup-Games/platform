<?php

namespace App\Services;

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GMProfile;
use App\Models\Review;
use App\Models\User;
use App\Services\Concerns\DashboardFormatting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the "Nearby and Noteworthy", "Your Story" (milestone cards),
 * and "Community Pulse" sections for the established-mode dashboard.
 *
 * Unlike the newcomer-mode DiscoveryQueryService, this service does NOT
 * filter by user preference — it shows all nearby scheduled games and
 * computes relevance tags per game to help the user decide.
 */
class DashboardDiscoveryService
{
    use DashboardFormatting;
    /**
     * Get nearby noteworthy games — scheduled games in the next 14 days
     * within the user's geohash tile that they are not already participating in.
     *
     * Unlike newcomer DiscoveryQueryService, this does NOT filter by user
     * preferences. Instead it computes relevance tags per game:
     *   - matches_your_taste: game_system_id in user's preferences
     *   - popular_nearby: participant_count >= 3
     *   - filling_fast: participant_count >= max_players * 0.7
     *   - starting_soon: date_time within 48 hours
     *   - friends_are_going: any social circle participant
     *
     * Returns 3–6 games sorted by relevance tag count (desc), then date_time.
     *
     * Each game: id, name, system_badge, date_time, relative_time,
     * spots_available, distance_km, relevance_tags (array of tag keys).
     */
    public function getNearbyNoteworthy(User $user, string $geohash4): array
    {
        return $this->computeNearbyNoteworthy($user, $geohash4);
    }

    /**
     * Get milestone identity cards for the user.
     *
     * Computes earned milestone cards:
     *   - veteran_host:      hosted >= 10 completed games
     *   - community_builder: >= 5 unique players across hosted sessions
     *   - campaign_commitment: active in campaign with >= 5 completed sessions
     *   - trusted_voice:     received >= 3 reviews with avg >= 4.5
     *   - explorer:          played >= 5 different game systems
     *
     * Each card: key, title_key (i18n), description_key, icon, earned_at (Carbon|null),
     * is_new (earned within 7 days). Only returns earned cards.
     */
    public function getMilestoneCards(User $user): array
    {
        $cached = app(DashboardCacheService::class)->getMilestoneCards($user);

        if (! empty($cached)) {
            return $cached;
        }

        $cards = $this->computeMilestoneCards($user);

        // The cache layer will warm on the read; return directly.
        return $cards;
    }

    /**
     * Whether to show the Community Pulse section.
     *
     * Returns true if user follows 3+ users and the feed has items.
     */
    public function shouldShowCommunityPulse(User $user): bool
    {
        $followingCount = $user->followings()->count();

        if ($followingCount < 3) {
            return false;
        }

        $feedData = app(DashboardCacheService::class)->getFeedData($user);

        return ! empty($feedData['items']);
    }

    // ── Nearby Noteworthy computation ──────────────────

    /**
     * Compute nearby noteworthy games from scratch.
     */
    private function computeNearbyNoteworthy(User $user, string $geohash4): array
    {
        $bounds = Geohash::prefixBounds($geohash4);

        // Games the user already owns or participates in
        $ownedGameIds = Game::where('owner_id', $user->id)->pluck('id');
        $participatingGameIds = GameParticipant::where('user_id', $user->id)
            ->whereIn('status', [
                ParticipantStatus::Approved->value,
                ParticipantStatus::Pending->value,
            ])
            ->pluck('game_id');
        $excludeGameIds = $ownedGameIds->merge($participatingGameIds)->unique()->values()->toArray();

        // User's preferred system IDs for relevance tag
        $preferredSystemIds = $user->gameSystemPreferences()->pluck('game_systems.id')->toArray();

        // Social circle IDs for friends_are_going tag
        $socialCircleIds = $user->followings()
            ->pluck('related_user_id')
            ->unique()
            ->values()
            ->toArray();

        // +1 for the game owner who does not have a GameParticipant record.
        // Owners are blocked from self-joining (canJoinViaShareList blocks owner_id,
        // ParticipantService rejects self-invites), so the +1 never double-counts.
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
                // Filtering at SQL level avoids fetching rows only to discard them.
                $q->whereNull('games.max_players')
                    ->orWhereRaw(
                        '(SELECT COUNT(*) + 1 FROM game_participants WHERE game_participants.game_id = games.id AND game_participants.status = ?) < games.max_players',
                        [ParticipantStatus::Approved->value],
                    );
            })
            ->with(['gameSystem', 'linkedLocation'])
            // Cap at 20 candidates — we only take the top 6 by relevance after scoring.
            // Without this limit, dense metro areas could load hundreds of games.
            ->limit(20)
            ->get();

        // Compute user location for distance
        $userLocation = $user->linkedLocation;
        $userLat = $userLocation?->latitude ? (float) $userLocation->latitude : null;
        $userLng = $userLocation?->longitude ? (float) $userLocation->longitude : null;

        // Pre-fetch friend participant game IDs to avoid N+1 inside the loop
        $friendGameIds = [];
        if (! empty($socialCircleIds)) {
            $friendGameIds = GameParticipant::whereIn('game_id', $games->pluck('id'))
                ->where('status', ParticipantStatus::Approved->value)
                ->whereIn('user_id', $socialCircleIds)
                ->pluck('game_id')
                ->unique()
                ->values()
                ->toArray();
        }

        // Compute relevance tags and distance for each game
        $now = now();
        $scoredGames = $games->map(function ($game) use ($preferredSystemIds, $friendGameIds, $userLat, $userLng, $now) {
            $tags = [];
            $participantCount = (int) ($game->participant_count ?? 0);

            // matches_your_taste
            if (in_array($game->game_system_id, $preferredSystemIds)) {
                $tags[] = 'matches_your_taste';
            }

            // popular_nearby
            if ($participantCount >= 3) {
                $tags[] = 'popular_nearby';
            }

            // filling_fast
            if ($game->max_players && $participantCount >= ($game->max_players * 0.7)) {
                $tags[] = 'filling_fast';
            }

            // starting_soon
            if ($game->date_time && $game->date_time->isFuture() && $now->diffInHours($game->date_time) <= 48) {
                $tags[] = 'starting_soon';
            }

            // friends_are_going
            if (! empty($friendGameIds) && in_array($game->id, $friendGameIds, false)) {
                $tags[] = 'friends_are_going';
            }

            // Distance
            $distanceKm = null;
            if ($userLat !== null && $userLng !== null && $game->linkedLocation) {
                $distanceKm = ProximityQuery::haversineDistance(
                    $userLat,
                    $userLng,
                    (float) $game->linkedLocation->latitude,
                    (float) $game->linkedLocation->longitude,
                );
            }

            return [
                'id' => $game->id,
                'name' => $game->name,
                'system_badge' => [
                    'name' => $game->gameSystem?->name,
                    'icon' => $game->gameSystem?->coverImageUrl('thumb'),
                ],
                'date_time' => $game->date_time?->toIso8601String(),
                'relative_time' => $this->formatRelativeTime($game->date_time),
                'spots_available' => $game->max_players
                    ? max(0, $game->max_players - $participantCount)
                    : null,
                'distance_km' => $distanceKm !== null ? round($distanceKm, 1) : null,
                'relevance_tags' => $tags,
                'tag_count' => count($tags),
            ];
        });

        // Sort: more relevance tags first, then by date_time within same tag count
        $sorted = $scoredGames
            ->sortBy(function ($item) {
                $tagCount = $item['tag_count'];
                $dateTime = $item['date_time'] ?? '9999-99-99';

                // Negative tag count for DESC sort, date_time for ASC sort
                return [-$tagCount, $dateTime];
            }, SORT_REGULAR)
            ->take(6)
            ->values();

        // Remove internal tag_count field from output
        return $sorted->map(function ($item) {
            unset($item['tag_count']);

            return $item;
        })->toArray();
    }

    // ── Milestone Cards computation ────────────────────

    /**
     * Compute all milestone cards the user has earned.
     */
    public function computeMilestoneCardsPublic(User $user): array
    {
        return $this->computeMilestoneCards($user);
    }

    private function computeMilestoneCards(User $user): array
    {
        $cards = [];

        // veteran_host: hosted >= 10 completed games
        $veteranHost = $this->computeVeteranHost($user);
        if ($veteranHost !== null) {
            $cards[] = $veteranHost;
        }

        // community_builder: >= 5 unique players across hosted sessions
        $communityBuilder = $this->computeCommunityBuilder($user);
        if ($communityBuilder !== null) {
            $cards[] = $communityBuilder;
        }

        // campaign_commitment: active in campaign with >= 5 completed sessions
        $campaignCommitment = $this->computeCampaignCommitment($user);
        if ($campaignCommitment !== null) {
            $cards[] = $campaignCommitment;
        }

        // trusted_voice: received >= 3 reviews with avg >= 4.5
        $trustedVoice = $this->computeTrustedVoice($user);
        if ($trustedVoice !== null) {
            $cards[] = $trustedVoice;
        }

        // explorer: played >= 5 different game systems
        $explorer = $this->computeExplorer($user);
        if ($explorer !== null) {
            $cards[] = $explorer;
        }

        return $cards;
    }

    /**
     * veteran_host: hosted >= 10 completed games.
     * earned_at = date of the 10th completed game.
     */
    private function computeVeteranHost(User $user): ?array
    {
        $completedHostedCount = Game::where('owner_id', $user->id)
            ->where('status', GameStatus::Completed->value)
            ->count();

        if ($completedHostedCount < 10) {
            return null;
        }

        // Find when the 10th game was completed
        $tenthGame = Game::where('owner_id', $user->id)
            ->where('status', GameStatus::Completed->value)
            ->orderBy('date_time')
            ->skip(9)
            ->first();

        $earnedAt = $tenthGame?->date_time;

        return $this->buildCard(
            'veteran_host',
            'dashboard.milestones.veteran_host.title',
            'dashboard.milestones.veteran_host.description',
            'trophy',
            $earnedAt,
        );
    }

    /**
     * community_builder: >= 5 unique players across hosted sessions.
     * earned_at = date of the game that brought the 5th unique player.
     */
    private function computeCommunityBuilder(User $user): ?array
    {
        $hostedGameIds = Game::where('owner_id', $user->id)
            ->where('status', GameStatus::Completed->value)
            ->pluck('id');

        if ($hostedGameIds->count() === 0) {
            return null;
        }

        $uniquePlayers = GameParticipant::whereIn('game_id', $hostedGameIds)
            ->where('user_id', '!=', $user->id)
            ->where('status', ParticipantStatus::Approved->value)
            ->distinct('user_id')
            ->count('user_id');

        if ($uniquePlayers < 5) {
            return null;
        }

        // Approximate earned_at: date of the most recent completed hosted game
        $lastHosted = Game::where('owner_id', $user->id)
            ->where('status', GameStatus::Completed->value)
            ->orderByDesc('date_time')
            ->first();

        return $this->buildCard(
            'community_builder',
            'dashboard.milestones.community_builder.title',
            'dashboard.milestones.community_builder.description',
            'users',
            $lastHosted?->date_time,
        );
    }

    /**
     * campaign_commitment: active in campaign with >= 5 completed sessions.
     * earned_at = when the campaign reached 5 completed sessions.
     */
    private function computeCampaignCommitment(User $user): ?array
    {
        $campaignIds = CampaignParticipant::where('user_id', $user->id)
            ->where('status', ParticipantStatus::Approved->value)
            ->pluck('campaign_id');

        if ($campaignIds->isEmpty()) {
            return null;
        }

        // Find campaigns the user is in that have >= 5 completed sessions
        // Use whereHas with count check instead of havingRaw (PG grouping issue)
        $campaign = Campaign::whereIn('id', $campaignIds)
            ->where('status', CampaignStatus::Active->value)
            ->withCount(['sessions as completed_sessions_count' => function ($query) {
                $query->where('status', GameStatus::Completed->value);
            }])
            ->whereHas('sessions', function ($query) {
                $query->where('status', GameStatus::Completed->value);
            }, '>=', 5)
            ->orderByDesc('completed_sessions_count')
            ->first();

        if ($campaign === null) {
            return null;
        }

        // earned_at: date of the 5th completed session in this campaign
        $fifthSession = Game::where('campaign_id', $campaign->id)
            ->where('status', GameStatus::Completed->value)
            ->orderBy('date_time')
            ->skip(4)
            ->first();

        return $this->buildCard(
            'campaign_commitment',
            'dashboard.milestones.campaign_commitment.title',
            'dashboard.milestones.campaign_commitment.description',
            'book-open',
            $fifthSession?->date_time,
        );
    }

    /**
     * trusted_voice: received >= 3 reviews with avg >= 4.5.
     * earned_at = date of the review that satisfied the threshold.
     */
    private function computeTrustedVoice(User $user): ?array
    {
        // Get user's GM profile
        $gmProfile = GMProfile::where('user_id', $user->id)->first();

        if ($gmProfile === null) {
            return null;
        }

        $reviewStats = Review::where('gm_profile_id', $gmProfile->id)
            ->where('status', 'published')
            ->selectRaw('COUNT(*) as count, AVG(rating) as avg_rating')
            ->first();

        if ($reviewStats === null || $reviewStats->count < 3 || (float) $reviewStats->avg_rating < 4.5) {
            return null;
        }

        // earned_at: date of the 3rd review
        $thirdReview = Review::where('gm_profile_id', $gmProfile->id)
            ->where('status', 'published')
            ->orderBy('created_at')
            ->skip(2)
            ->first();

        return $this->buildCard(
            'trusted_voice',
            'dashboard.milestones.trusted_voice.title',
            'dashboard.milestones.trusted_voice.description',
            'star',
            $thirdReview?->created_at,
        );
    }

    /**
     * explorer: played >= 5 different game systems (hosted + participated).
     * earned_at = date of the most recent completed game in a unique system.
     */
    private function computeExplorer(User $user): ?array
    {
        // Count unique game systems across both hosted and participated games
        $hostedSystems = Game::where('owner_id', $user->id)
            ->where('status', GameStatus::Completed->value)
            ->distinct('game_system_id')
            ->pluck('game_system_id');

        $participatedGameIds = GameParticipant::where('user_id', $user->id)
            ->where('status', ParticipantStatus::Approved->value)
            ->pluck('game_id');

        $participatedSystems = $participatedGameIds->isNotEmpty()
            ? Game::whereIn('id', $participatedGameIds)
                ->where('status', GameStatus::Completed->value)
                ->distinct('game_system_id')
                ->pluck('game_system_id')
            : collect();

        $allSystems = $hostedSystems->merge($participatedSystems)->unique();

        if ($allSystems->count() < 5) {
            return null;
        }

        // Approximate earned_at: most recent completed game
        $lastGame = Game::where(function ($q) use ($user, $participatedGameIds) {
            $q->where('owner_id', $user->id)
                ->orWhereIn('id', $participatedGameIds);
        })
            ->where('status', GameStatus::Completed->value)
            ->orderByDesc('date_time')
            ->first();

        return $this->buildCard(
            'explorer',
            'dashboard.milestones.explorer.title',
            'dashboard.milestones.explorer.description',
            'compass',
            $lastGame?->date_time,
        );
    }

    /**
     * Build a milestone card array.
     */
    private function buildCard(
        string $key,
        string $titleKey,
        string $descriptionKey,
        string $icon,
        ?Carbon $earnedAt,
    ): array {
        $isNew = false;
        if ($earnedAt !== null) {
            $isNew = $earnedAt->gt(now()->subDays(7));
        }

        return [
            'key' => $key,
            'title_key' => $titleKey,
            'description_key' => $descriptionKey,
            'icon' => $icon,
            'earned_at' => $earnedAt?->toIso8601String(),
            'is_new' => $isNew,
        ];
    }
}

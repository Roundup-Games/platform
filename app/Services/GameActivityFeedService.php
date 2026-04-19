<?php

namespace App\Services;

use App\Enums\RelationshipType;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GameActivityFeedService
{
    /**
     * Build a paginated activity feed of game-related actions by the user's friends and followed users.
     *
     * Activity types:
     *  - game_created: A friend/followed user created a game
     *  - player_joined: A friend/followed user joined someone's game (as approved player)
     *  - game_completed: A friend/followed user's game was completed
     *
     * Each item has: type, game, user (who performed the action), created_at, description
     */
    public function getFeed(User $viewer, int $perPage = 20): LengthAwarePaginator
    {
        $socialCircleIds = $this->getSocialCircleUserIds($viewer);

        if (empty($socialCircleIds)) {
            return new \Illuminate\Pagination\LengthAwarePaginator(
                [], 0, $perPage, 1
            );
        }

        // Union of three activity types, sorted by created_at desc
        $activities = collect()
            ->merge($this->getGamesCreated($socialCircleIds))
            ->merge($this->getPlayersJoined($socialCircleIds, $viewer))
            ->merge($this->getGamesCompleted($socialCircleIds))
            ->sortByDesc('created_at')
            ->values();

        $page = request()->get('page', 1);
        $offset = ($page - 1) * $perPage;

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $activities->slice($offset, $perPage)->values(),
            $activities->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * Build a paginated activity feed of campaign-related actions by the user's friends and followed users.
     *
     * Activity types:
     *  - campaign_created: A friend/followed user created a campaign
     *  - player_joined: A friend/followed user joined someone's campaign (as approved player)
     *  - campaign_completed: A friend/followed user's campaign was completed
     *  - session_scheduled: A new game/session was added to a campaign
     */
    public function getCampaignFeed(User $viewer, int $perPage = 20): LengthAwarePaginator
    {
        $socialCircleIds = $this->getSocialCircleUserIds($viewer);

        if (empty($socialCircleIds)) {
            return new \Illuminate\Pagination\LengthAwarePaginator(
                [], 0, $perPage, 1
            );
        }

        $activities = collect()
            ->merge($this->getCampaignsCreated($socialCircleIds))
            ->merge($this->getCampaignPlayersJoined($socialCircleIds, $viewer))
            ->merge($this->getCampaignsCompleted($socialCircleIds))
            ->merge($this->getSessionsScheduled($socialCircleIds))
            ->sortByDesc('created_at')
            ->values();

        $page = request()->get('page', 1);
        $offset = ($page - 1) * $perPage;

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $activities->slice($offset, $perPage)->values(),
            $activities->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * Get user IDs that the viewer follows or is friends with.
     */
    protected function getSocialCircleUserIds(User $viewer): array
    {
        // Users the viewer follows (outgoing follows)
        return $viewer->followings()
            ->pluck('related_user_id')
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Games created by social circle members.
     */
    protected function getGamesCreated(array $socialCircleIds): Collection
    {
        return Game::whereIn('owner_id', $socialCircleIds)
            ->with(['owner', 'gameSystem'])
            ->withCount('participants')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn (Game $game) => (object) [
                'id' => "game_created_{$game->id}",
                'type' => 'game_created',
                'entity' => $game,
                'entity_type' => 'game',
                'user' => $game->owner,
                'created_at' => $game->created_at,
            ]);
    }

    /**
     * Games where social circle members joined as players.
     */
    protected function getPlayersJoined(array $socialCircleIds, User $viewer): Collection
    {
        // Get approved player participations by social circle users
        // We only have game_participants.created_at because the table has timestamps()
        // but the model sets $timestamps = false — so we use the games.created_at as proxy
        // since participants are created at roughly the same time.
        //
        // For now, find games where social circle users are approved players and the viewer
        // isn't the owner — this shows "your friend joined X's game"
        $gameIds = GameParticipant::whereIn('user_id', $socialCircleIds)
            ->where('role', 'player')
            ->where('status', 'approved')
            ->pluck('game_id')
            ->unique();

        // Don't show games the viewer already owns or participates in — those show in other sections
        $viewerGameIds = Game::where('owner_id', $viewer->id)
            ->orWhereHas('participants', fn ($q) => $q->where('user_id', $viewer->id))
            ->pluck('id');

        $gameIds = $gameIds->diff($viewerGameIds);

        return Game::whereIn('id', $gameIds)
            ->with(['owner', 'gameSystem', 'participants' => fn ($q) => $q->whereIn('user_id', $socialCircleIds)->where('role', 'player')->where('status', 'approved')])
            ->withCount('participants')
            ->orderBy('updated_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function (Game $game) {
                $joinedFriends = $game->participants->filter(
                    fn ($p) => true // already filtered in eager load
                );
                $friend = $joinedFriends->first();

                return (object) [
                    'id' => "player_joined_game_{$game->id}",
                    'type' => 'player_joined',
                    'entity' => $game,
                    'entity_type' => 'game',
                    'user' => $friend?->user,
                    'users' => $joinedFriends->pluck('user')->filter(),
                    'created_at' => $game->updated_at ?? $game->created_at,
                ];
            });
    }

    /**
     * Games by social circle members that were recently completed.
     */
    protected function getGamesCompleted(array $socialCircleIds): Collection
    {
        return Game::whereIn('owner_id', $socialCircleIds)
            ->where('status', 'completed')
            ->with(['owner', 'gameSystem'])
            ->withCount('participants')
            ->orderBy('updated_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn (Game $game) => (object) [
                'id' => "game_completed_{$game->id}",
                'type' => 'game_completed',
                'entity' => $game,
                'entity_type' => 'game',
                'user' => $game->owner,
                'created_at' => $game->updated_at,
            ]);
    }

    /**
     * Campaigns created by social circle members.
     */
    protected function getCampaignsCreated(array $socialCircleIds): Collection
    {
        return Campaign::whereIn('owner_id', $socialCircleIds)
            ->with(['owner', 'gameSystem'])
            ->withCount('participants')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn (Campaign $campaign) => (object) [
                'id' => "campaign_created_{$campaign->id}",
                'type' => 'campaign_created',
                'entity' => $campaign,
                'entity_type' => 'campaign',
                'user' => $campaign->owner,
                'created_at' => $campaign->created_at,
            ]);
    }

    /**
     * Campaigns where social circle members joined as players.
     */
    protected function getCampaignPlayersJoined(array $socialCircleIds, User $viewer): Collection
    {
        $campaignIds = CampaignParticipant::whereIn('user_id', $socialCircleIds)
            ->where('role', 'player')
            ->where('status', 'approved')
            ->pluck('campaign_id')
            ->unique();

        $viewerCampaignIds = Campaign::where('owner_id', $viewer->id)
            ->orWhereHas('participants', fn ($q) => $q->where('user_id', $viewer->id))
            ->pluck('id');

        $campaignIds = $campaignIds->diff($viewerCampaignIds);

        return Campaign::whereIn('id', $campaignIds)
            ->with(['owner', 'gameSystem', 'participants' => fn ($q) => $q->whereIn('user_id', $socialCircleIds)->where('role', 'player')->where('status', 'approved')])
            ->withCount('participants')
            ->orderBy('updated_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function (Campaign $campaign) {
                $joinedFriends = $campaign->participants;
                $friend = $joinedFriends->first();

                return (object) [
                    'id' => "player_joined_campaign_{$campaign->id}",
                    'type' => 'player_joined',
                    'entity' => $campaign,
                    'entity_type' => 'campaign',
                    'user' => $friend?->user,
                    'users' => $joinedFriends->pluck('user')->filter(),
                    'created_at' => $campaign->updated_at ?? $campaign->created_at,
                ];
            });
    }

    /**
     * Campaigns by social circle members that were completed.
     */
    protected function getCampaignsCompleted(array $socialCircleIds): Collection
    {
        return Campaign::whereIn('owner_id', $socialCircleIds)
            ->where('status', 'completed')
            ->with(['owner', 'gameSystem'])
            ->withCount('participants')
            ->orderBy('updated_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn (Campaign $campaign) => (object) [
                'id' => "campaign_completed_{$campaign->id}",
                'type' => 'campaign_completed',
                'entity' => $campaign,
                'entity_type' => 'campaign',
                'user' => $campaign->owner,
                'created_at' => $campaign->updated_at,
            ]);
    }

    /**
     * Sessions (games) scheduled for campaigns owned by social circle members.
     */
    protected function getSessionsScheduled(array $socialCircleIds): Collection
    {
        return Game::whereNotNull('campaign_id')
            ->whereHas('campaign', fn ($q) => $q->whereIn('owner_id', $socialCircleIds))
            ->where('status', 'scheduled')
            ->with(['owner', 'gameSystem', 'campaign'])
            ->withCount('participants')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function (Game $game) {
                return (object) [
                    'id' => "session_scheduled_{$game->id}",
                    'type' => 'session_scheduled',
                    'entity' => $game,
                    'entity_type' => 'game',
                    'entity_campaign' => $game->campaign,
                    'user' => $game->campaign?->owner,
                    'created_at' => $game->created_at,
                ];
            });
    }
}

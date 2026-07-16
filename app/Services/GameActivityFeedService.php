<?php

namespace App\Services;

use App\Dto\ActivityFeedItem;
use App\Dto\FeedItem;
use App\Enums\ParticipantRole;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

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
     *
     * @return LengthAwarePaginator<int, FeedItem>
     */
    public function getFeed(User $viewer, int $perPage = 20): LengthAwarePaginator
    {
        $socialCircleIds = $this->getSocialCircleUserIds($viewer);

        if (empty($socialCircleIds)) {
            return new \Illuminate\Pagination\LengthAwarePaginator(
                [], 0, $perPage, 1
            );
        }

        // Union of activity types, sorted by created_at desc
        $activities = collect()
            ->merge($this->getGamesCreated($socialCircleIds, $viewer))
            ->merge($this->getPlayersJoined($socialCircleIds, $viewer))
            ->merge($this->getGamesCompleted($socialCircleIds, $viewer))
            ->merge($this->getSessionRecaps($socialCircleIds, $viewer))
            ->sortByDesc('created_at')
            ->values();

        $page = is_int($p = request()->get('page')) || is_numeric($p) ? (int) $p : 1;
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
     *
     * @return LengthAwarePaginator<int, FeedItem>
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
            ->merge($this->getCampaignsCreated($socialCircleIds, $viewer))
            ->merge($this->getCampaignPlayersJoined($socialCircleIds, $viewer))
            ->merge($this->getCampaignsCompleted($socialCircleIds, $viewer))
            ->merge($this->getSessionsScheduled($socialCircleIds, $viewer))
            ->sortByDesc('created_at')
            ->values();

        $page = is_int($p = request()->get('page')) || is_numeric($p) ? (int) $p : 1;
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
     *
     * @return array<string, mixed>
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
     *
     * @param  array<string, mixed>  $socialCircleIds
     */
    protected function getGamesCreated(array $socialCircleIds, User $viewer): Collection // @phpstan-ignore missingType.generics
    {
        return Game::whereIn('owner_id', $socialCircleIds)
            ->visibleTo($viewer)
            ->with(['owner', 'gameSystems'])
            ->withCount('participants')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn (Game $game) => new ActivityFeedItem(
                id: "game_created_{$game->id}",
                type: 'game_created',
                entity: $game,
                entityType: 'game',
                user: $game->owner,
                createdAt: $game->created_at,
            ));
    }

    /**
     * Games where social circle members joined as players.
     *
     * @param  array<string, mixed>  $socialCircleIds
     */
    protected function getPlayersJoined(array $socialCircleIds, User $viewer): Collection // @phpstan-ignore missingType.generics
    {
        // Get approved player participations by social circle users
        // We only have game_participants.created_at because the table has timestamps()
        // but the model sets $timestamps = false — so we use the games.created_at as proxy
        // since participants are created at roughly the same time.
        //
        // For now, find games where social circle users are approved players and the viewer
        // isn't the owner — this shows "your friend joined X's game"
        $gameIds = GameParticipant::whereIn('user_id', $socialCircleIds)
            ->where('role', ParticipantRole::Player->value)
            ->where('status', 'approved')
            ->pluck('game_id')
            ->unique();

        // Don't show games the viewer already owns or participates in — those show in other sections
        $viewerGameIds = Game::whereBelongsTo($viewer, 'owner')
            ->orWhereHas('participants', fn ($q) => $q->whereBelongsTo($viewer))
            ->pluck('id');

        $gameIds = $gameIds->diff($viewerGameIds);

        return Game::whereIn('id', $gameIds)
            ->visibleTo($viewer)
            ->with(['owner', 'gameSystems', 'participants' => fn ($q) => $q->whereIn('user_id', $socialCircleIds)->where('role', ParticipantRole::Player->value)->where('status', 'approved')])
            ->withCount('participants')
            ->orderBy('updated_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function (Game $game) {
                $joinedFriends = $game->participants->filter(
                    fn ($p) => true // already filtered in eager load
                );
                $friend = $joinedFriends->first();

                return new ActivityFeedItem(
                    id: "player_joined_game_{$game->id}",
                    type: 'player_joined',
                    entity: $game,
                    entityType: 'game',
                    user: $friend?->user,
                    users: $joinedFriends->pluck('user')->filter(fn (mixed $u) => $u instanceof User)->values(),
                    createdAt: $game->updated_at ?? $game->created_at,
                );
            });
    }

    /**
     * Games by social circle members that were recently completed.
     *
     * @param  array<string, mixed>  $socialCircleIds
     */
    protected function getGamesCompleted(array $socialCircleIds, User $viewer): Collection // @phpstan-ignore missingType.generics
    {
        return Game::whereIn('owner_id', $socialCircleIds)
            ->visibleTo($viewer)
            ->where('status', 'completed')
            ->with(['owner', 'gameSystems'])
            ->withCount('participants')
            ->orderBy('updated_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn (Game $game) => new ActivityFeedItem(
                id: "game_completed_{$game->id}",
                type: 'game_completed',
                entity: $game,
                entityType: 'game',
                user: $game->owner,
                createdAt: $game->updated_at,
            ));
    }

    /**
     * Games where social circle members wrote a post-session recap.
     *
     * @param  array<string, mixed>  $socialCircleIds
     */
    protected function getSessionRecaps(array $socialCircleIds, User $viewer): Collection // @phpstan-ignore missingType.generics
    {
        return Game::whereIn('owner_id', $socialCircleIds)
            ->visibleTo($viewer)
            ->where('status', 'completed')
            ->whereNotNull('recap')
            ->where('recap', '!=', '')
            ->with(['owner', 'gameSystems'])
            ->withCount('participants')
            ->orderBy('updated_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn (Game $game) => new ActivityFeedItem(
                id: "session_recapped_{$game->id}",
                type: 'session_recapped',
                entity: $game,
                entityType: 'game',
                user: $game->owner,
                createdAt: $game->updated_at,
            ));
    }

    /**
     * Campaigns created by social circle members.
     *
     * @param  array<string, mixed>  $socialCircleIds
     */
    protected function getCampaignsCreated(array $socialCircleIds, User $viewer): Collection // @phpstan-ignore missingType.generics
    {
        return Campaign::whereIn('owner_id', $socialCircleIds)
            ->visibleTo($viewer)
            ->with(['owner', 'gameSystems'])
            ->withCount('participants')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn (Campaign $campaign) => new ActivityFeedItem(
                id: "campaign_created_{$campaign->id}",
                type: 'campaign_created',
                entity: $campaign,
                entityType: 'campaign',
                user: $campaign->owner,
                createdAt: $campaign->created_at,
            ));
    }

    /**
     * Campaigns where social circle members joined as players.
     *
     * @param  array<string, mixed>  $socialCircleIds
     */
    protected function getCampaignPlayersJoined(array $socialCircleIds, User $viewer): Collection // @phpstan-ignore missingType.generics
    {
        $campaignIds = CampaignParticipant::whereIn('user_id', $socialCircleIds)
            ->where('role', ParticipantRole::Player->value)
            ->where('status', 'approved')
            ->pluck('campaign_id')
            ->unique();

        $viewerCampaignIds = Campaign::whereBelongsTo($viewer, 'owner')
            ->orWhereHas('participants', fn ($q) => $q->whereBelongsTo($viewer))
            ->pluck('id');

        $campaignIds = $campaignIds->diff($viewerCampaignIds);

        return Campaign::whereIn('id', $campaignIds)
            ->visibleTo($viewer)
            ->with(['owner', 'gameSystems', 'participants' => fn ($q) => $q->whereIn('user_id', $socialCircleIds)->where('role', ParticipantRole::Player->value)->where('status', 'approved')])
            ->withCount('participants')
            ->orderBy('updated_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function (Campaign $campaign) {
                $joinedFriends = $campaign->participants;
                $friend = $joinedFriends->first();

                return new ActivityFeedItem(
                    id: "player_joined_campaign_{$campaign->id}",
                    type: 'player_joined',
                    entity: $campaign,
                    entityType: 'campaign',
                    user: $friend?->user,
                    users: $joinedFriends->pluck('user')->filter(fn (mixed $u) => $u instanceof User)->values(),
                    createdAt: $campaign->updated_at ?? $campaign->created_at,
                );
            });
    }

    /**
     * Campaigns by social circle members that were completed.
     *
     * @param  array<string, mixed>  $socialCircleIds
     */
    protected function getCampaignsCompleted(array $socialCircleIds, User $viewer): Collection // @phpstan-ignore missingType.generics
    {
        return Campaign::whereIn('owner_id', $socialCircleIds)
            ->visibleTo($viewer)
            ->where('status', 'completed')
            ->with(['owner', 'gameSystems'])
            ->withCount('participants')
            ->orderBy('updated_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn (Campaign $campaign) => new ActivityFeedItem(
                id: "campaign_completed_{$campaign->id}",
                type: 'campaign_completed',
                entity: $campaign,
                entityType: 'campaign',
                user: $campaign->owner,
                createdAt: $campaign->updated_at,
            ));
    }

    /**
     * Sessions (games) scheduled for campaigns owned by social circle members.
     *
     * @param  array<string, mixed>  $socialCircleIds
     */
    protected function getSessionsScheduled(array $socialCircleIds, User $viewer): Collection // @phpstan-ignore missingType.generics
    {
        return Game::whereNotNull('campaign_id')
            ->visibleTo($viewer)
            ->whereHas('campaign', fn ($q) => $q->whereIn('owner_id', $socialCircleIds))
            ->where('status', 'scheduled')
            ->with(['owner', 'gameSystems', 'campaign'])
            ->withCount('participants')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function (Game $game) {
                return new ActivityFeedItem(
                    id: "session_scheduled_{$game->id}",
                    type: 'session_scheduled',
                    entity: $game,
                    entityType: 'game',
                    user: $game->campaign?->owner,
                    createdAt: $game->created_at,
                    entityCampaign: $game->campaign,
                );
            });
    }

    /**
     * Convert the raw (object) activity items from getFeed()/getCampaignFeed()
     * into serializable FeedItem DTOs safe for cache storage.
     *
     * @param  Collection<int, ActivityFeedItem>  $activities
     * @return Collection<int, FeedItem>
     */
    public function toFeedItems(Collection $activities): Collection
    {
        return $activities->map(function (ActivityFeedItem $item): FeedItem {
            $entity = $item->entity;
            $user = $item->user;

            $gameSystemName = $entity->gameSystems->first()?->name;
            $participantCount = $entity->participants_count ?? null;
            $maxPlayers = $entity->max_players ?? null;
            $imageUrl = $this->resolveImageUrl($entity);

            return new FeedItem(
                id: $item->id,
                type: $item->type,
                entityType: $item->entityType,
                entityId: (string) $entity->id,
                entityName: $entity->name,
                userName: $user->name ?? 'Unknown',
                userId: (string) ($user->id ?? ''),
                createdAt: Carbon::parse($item->createdAt),
                gameSystemName: $gameSystemName,
                participantCount: $participantCount,
                maxPlayers: $maxPlayers,
                imageUrl: $imageUrl,
            );
        });
    }

    /**
     * Resolve an image URL from a game or campaign entity.
     *
     * Routes through ResolvesCoverImage::resolveCoverUrl() (S07) so the feed
     * honors the host-uploaded cover -> representative GameSystem cover ->
     * og-default.jpg fallback chain. Callers eager-load 'gameSystems' (see the
     * ->with(['owner', 'gameSystems']) calls in the query builders above),
     * which keeps the representative rung N+1-safe on feed pages.
     */
    protected function resolveImageUrl(Game|Campaign $entity): ?string
    {
        return $entity->resolveCoverUrl();
    }
}

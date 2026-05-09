<?php

namespace App\Livewire;

use App\Dto\FeedItem;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\DashboardCacheService;
use App\Services\DashboardSmartPromptService;
use App\Services\Geohash;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    public function render()
    {
        $user = Auth::user();
        $gamesThisWeek = $this->gamesThisWeek();

        $smartPrompt = app(DashboardSmartPromptService::class)->getPrompt($user);
        $weekData = app(DashboardCacheService::class)->getWeekData($user);

        // Community Feed: blend friends activity with trending nearby
        $communityFeed = $this->getCommunityFeed($user);

        // Opportunities & Contributions from cache service
        $opportunities = $this->getOpportunities($user);
        $contributions = app(DashboardCacheService::class)->getContributions($user);

        // Quick actions derived from smart prompt state
        $quickActions = $this->deriveQuickActions($user, $smartPrompt);

        return view('livewire.dashboard', [
            // New dashboard sections
            'smartPrompt' => $smartPrompt,
            'weekData' => $weekData,
            'gamesThisWeek' => $gamesThisWeek,
            'gamesThisWeekCount' => $gamesThisWeek->count(),
            'unreadNotificationsCount' => $this->unreadNotificationsCount(),

            // Community Feed
            'communityFeed' => $communityFeed['friends'],
            'trendingItems' => $communityFeed['trending'],
            'hasTrendingSection' => $communityFeed['show_trending'],

            // GM Stats
            'gmAverageRating' => $this->gmAverageRating(),

            // Recaps
            'newRecaps' => collect(app(DashboardCacheService::class)->getRecaps($user))->map(fn ($r) => (object) $r),

            // Opportunities & Contributions
            'opportunities' => $opportunities,
            'contributions' => $contributions,

            // Quick Actions
            'quickActions' => $quickActions,
        ]);
    }


    public function unreadNotificationsCount(): int
    {
        return Auth::user()->unreadNotifications()->count();
    }

    #[Computed]
    public function gmAverageRating(): ?float
    {
        if (! Auth::user()->isGM()) {
            return null;
        }

        return Auth::user()->gmProfile?->average_rating;
    }

    /**
     * Build the blended community feed: friends' activity + trending nearby.
     *
     * Returns:
     *  - friends: FeedItem[] — max 10 items from social circle activity
     *  - trending: FeedItem[] — max 5 trending items (shown when friends < 5)
     *  - show_trending: bool — whether to show the trending subsection
     */
    private function getCommunityFeed(User $user): array
    {
        // Read feed data from cache service (avoids double-computation)
        $feedData = app(DashboardCacheService::class)->getFeedData($user);

        $friendsItems = collect($feedData['items'] ?? [])
            ->map(fn (array $item) => FeedItem::fromArray($item))
            ->take(10);

        // Get trending nearby games
        $trendingItems = collect();
        $showTrending = $friendsItems->count() < 5;

        if ($showTrending) {
            $trendingItems = $this->getTrendingFeedItems($user);
        }

        return [
            'friends' => $friendsItems,
            'trending' => $trendingItems,
            'show_trending' => $showTrending && $trendingItems->isNotEmpty(),
        ];
    }

    /**
     * Get trending nearby games as FeedItem DTOs.
     *
     * Uses DashboardCacheService trending cache for the user's geohash tile.
     * Converts raw cached arrays into FeedItem instances.
     */
    private function getTrendingFeedItems(User $user): \Illuminate\Support\Collection
    {
        $location = $user->linkedLocation;
        if (! $location || ! $location->latitude || ! $location->longitude) {
            return collect();
        }

        $geohash4 = Geohash::tilePrefix(
            (float) $location->latitude,
            (float) $location->longitude,
            4,
        );

        $trendingData = app(DashboardCacheService::class)->getTrendingNearby($geohash4);
        $games = $trendingData['games'] ?? [];

        return collect($games)->map(function (array $gameData): FeedItem {
            $participantCount = (int) ($gameData['participant_count'] ?? 0);
            $maxPlayers = $gameData['max_players'] ?? null;

            return new FeedItem(
                id: 'trending_' . $gameData['id'],
                type: 'trending',
                entityType: 'game',
                entityId: (string) $gameData['id'],
                entityName: $gameData['name'],
                userName: null,
                userId: null,
                createdAt: \Carbon\Carbon::parse($gameData['date_time']),
                gameSystemName: null,
                participantCount: $participantCount,
                maxPlayers: $maxPlayers !== null ? (int) $maxPlayers : null,
                imageUrl: null,
            );
        })->take(5);
    }

    /**
     * Get open-game opportunities for the user.
     *
     * Requires a geohash-4 prefix derived from the user's location.
     * Returns empty array if no location is set.
     */
    private function getOpportunities(User $user): array
    {
        $location = $user->linkedLocation;
        if (! $location || ! $location->latitude || ! $location->longitude) {
            return ['games' => [], 'campaigns' => [], 'total_available' => 0];
        }

        $geohash4 = Geohash::tilePrefix(
            (float) $location->latitude,
            (float) $location->longitude,
            4,
        );

        return app(DashboardCacheService::class)->getOpportunities($user, $geohash4);
    }

    /**
     * Derive 2-3 quick action buttons from the smart prompt state.
     *
     * Priority chain mirrors smart prompt checks but produces action buttons.
     * Each action has: label, url, style (primary|secondary), icon.
     */
    private function deriveQuickActions(User $user, array $smartPrompt): array
    {
        $actions = [];

        // 1. If there are pending invitations, show that first
        if ($smartPrompt['type'] === 'pending_invitations') {
            $actions[] = [
                'label' => __('profile.dashboard_prompt_view_invitations'),
                'url' => route('games.index'),
                'style' => 'primary',
                'icon' => 'mail',
            ];
        }

        // 2. If a session just completed, show write-recap action
        if ($smartPrompt['type'] === 'just_completed') {
            $actions[] = [
                'label' => __('profile.dashboard_prompt_write_recap'),
                'url' => $smartPrompt['action_url'] ?? route('games.index'),
                'style' => 'primary',
                'icon' => 'auto_stories',
            ];
        }

        // 3. Create game is always a useful action for GMs
        if ($user->isGM()) {
            $actions[] = [
                'label' => __('profile.dashboard_opportunities_create_cta'),
                'url' => route('games.create'),
                'style' => count($actions) === 0 ? 'primary' : 'secondary',
                'icon' => 'add_circle',
            ];
        }

        // 4. Discover / find games — always useful as secondary
        if (count($actions) < 3) {
            $actions[] = [
                'label' => __('discovery.action_discover'),
                'url' => route('discover'),
                'style' => count($actions) === 0 ? 'primary' : 'secondary',
                'icon' => 'explore',
            ];
        }

        // 5. View schedule if user has upcoming games
        if (count($actions) < 3 && ($smartPrompt['metadata']['upcoming_count'] ?? 0) > 0) {
            $actions[] = [
                'label' => __('profile.dashboard_prompt_view_schedule'),
                'url' => route('games.index'),
                'style' => 'secondary',
                'icon' => 'schedule',
            ];
        }

        return array_slice($actions, 0, 3);
    }

    /**
     * Get games occurring this week where the user is an owner or approved participant.
     * "This week" = start of Monday through end of Sunday in the app's timezone.
     */
    public function gamesThisWeek()
    {
        $user = Auth::user();
        $startOfWeek = now()->startOfWeek();
        $endOfWeek = now()->endOfWeek();

        // Games user owns this week (only active/scheduled)
        $ownedGameIds = Game::where('owner_id', $user->id)
            ->where('status', 'scheduled')
            ->whereBetween('date_time', [$startOfWeek, $endOfWeek])
            ->pluck('id');

        // Games user is an approved participant in this week (only active/scheduled)
        $participantGameIds = GameParticipant::where('user_id', $user->id)
            ->where('status', ParticipantStatus::Approved)
            ->whereHas('game', fn ($q) => $q
                ->where('status', 'scheduled')
                ->whereBetween('date_time', [$startOfWeek, $endOfWeek])
            )
            ->pluck('game_id');

        $gameIds = $ownedGameIds->merge($participantGameIds)->unique();

        return Game::whereIn('id', $gameIds)
            ->with(['participants' => fn ($q) => $q->where('user_id', $user->id), 'campaign'])
            ->orderBy('date_time')
            ->get();
    }

}

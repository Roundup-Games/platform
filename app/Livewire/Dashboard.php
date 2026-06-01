<?php

namespace App\Livewire;

use App\Dto\FeedItem;
use App\Models\User;
use App\Services\DashboardCacheService;
use App\Services\DashboardDiscoveryService;
use App\Services\DashboardModeService;
use App\Services\DashboardNewcomerService;
use App\Services\DashboardQuickActionsService;
use App\Services\DashboardScheduleService;
use App\Services\DashboardSmartPromptService;
use App\Services\Geohash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    public function render()
    {
        $user = Auth::user();

        // Resolve dashboard mode (newcomer vs established)
        $dashboardMode = app(DashboardModeService::class)->resolve($user);

        Log::debug('dashboard.render_mode', [
            'user_id' => $user->id,
            'mode' => $dashboardMode,
        ]);

        // ── Sections shared by both modes ──────────────

        $smartPrompt = app(DashboardSmartPromptService::class)->getPrompt($user);
        $unreadNotificationsCount = $this->unreadNotificationsCount();
        // Contributions and weekData are cheap cache reads needed by both modes and existing tests
        $contributions = app(DashboardCacheService::class)->getContributions($user);
        $weekData = app(DashboardCacheService::class)->getWeekData($user);

        // ── Mode-conditional sections ───────────────────
        // Only compute expensive sections for the active mode to avoid wasted
        // cache reads and DB queries. Each mode template only uses its own data.

        if ($dashboardMode === 'newcomer') {
            $newcomerData = $this->getNewcomerData($user, $dashboardMode);

            return view('livewire.dashboard', [
                'dashboardMode' => $dashboardMode,
                'smartPrompt' => $smartPrompt,
                'unreadNotificationsCount' => $unreadNotificationsCount,
                'quickActions' => $this->deriveQuickActions($user, $smartPrompt),
                'contributions' => $contributions,

                // Newcomer sections
                'newcomerWelcome' => $newcomerData['welcome'],
                'preferenceMatches' => $newcomerData['preference_matches'],
                'progressTracker' => $newcomerData['progress_tracker'],
                'nearbyPeople' => $newcomerData['nearby_people'],

                // Stub established props so the view never has undefined vars
                'gamesThisWeek' => collect(),
                'gamesThisWeekCount' => 0,
                'weekData' => $weekData,
                'communityFeed' => collect(),
                'trendingItems' => collect(),
                'hasTrendingSection' => false,
                'gmAverageRating' => null,
                'newRecaps' => collect(),
                'opportunities' => ['games' => [], 'campaigns' => [], 'total_available' => 0],
                'actionCenterItems' => [],
                'clearSummary' => null,
                'scheduleGroups' => ['today' => [], 'this_week' => [], 'coming_up' => []],
                'hostAgainBridge' => null,
                'nearbyNoteworthy' => [],
                'milestoneCards' => [],
                'establishedQuickActions' => [],
                'shouldShowCommunityPulse' => false,
            ]);
        }

        // ── Established mode ────────────────────────────

        $communityFeed = $this->getCommunityFeed($user);
        $opportunities = $this->getOpportunities($user);
        $establishedData = $this->getEstablishedData($user, $dashboardMode, $communityFeed);

        return view('livewire.dashboard', [
            'dashboardMode' => $dashboardMode,
            'smartPrompt' => $smartPrompt,
            'unreadNotificationsCount' => $unreadNotificationsCount,

            // Legacy established sections
            'weekData' => $weekData,
            'gamesThisWeek' => collect(),
            'gamesThisWeekCount' => 0,
            'communityFeed' => $communityFeed['friends'],
            'trendingItems' => $communityFeed['trending'],
            'hasTrendingSection' => $communityFeed['show_trending'],
            'gmAverageRating' => $this->gmAverageRating(),
            'newRecaps' => collect(app(DashboardCacheService::class)->getRecaps($user))->map(fn ($r) => (object) $r),
            'opportunities' => $opportunities,
            'contributions' => $contributions,

            // Newcomer stubs (unused in established mode)
            'quickActions' => [],
            'newcomerWelcome' => [],
            'preferenceMatches' => [],
            'progressTracker' => [],
            'nearbyPeople' => [],

            // Established dashboard data
            'actionCenterItems' => $establishedData['action_center_items'],
            'clearSummary' => $establishedData['clear_summary'],
            'scheduleGroups' => $establishedData['schedule_groups'],
            'hostAgainBridge' => $establishedData['host_again_bridge'],
            'nearbyNoteworthy' => $establishedData['nearby_noteworthy'],
            'milestoneCards' => $establishedData['milestone_cards'],
            'establishedQuickActions' => $establishedData['quick_actions'],
            'shouldShowCommunityPulse' => $establishedData['should_show_community_pulse'],
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
     * Get established-mode dashboard data for the user.
     *
     * Only loads data when the dashboard mode is 'established'.
     * Returns empty arrays for all sections when mode is 'newcomer',
     * so the view conditionals hide the established sections cleanly.
     *
     * @return array{action_center_items: array, clear_summary: array|null, schedule_groups: array, host_again_bridge: array|null, nearby_noteworthy: array, milestone_cards: array, quick_actions: array, should_show_community_pulse: bool}
     */
    private function getEstablishedData(User $user, string $dashboardMode, array $communityFeed): array
    {
        if ($dashboardMode !== 'established') {
            return [
                'action_center_items' => [],
                'clear_summary' => null,
                'schedule_groups' => ['today' => [], 'this_week' => [], 'coming_up' => []],
                'host_again_bridge' => null,
                'nearby_noteworthy' => [],
                'milestone_cards' => [],
                'quick_actions' => [],
                'should_show_community_pulse' => false,
            ];
        }

        $cacheService = app(DashboardCacheService::class);
        $scheduleService = app(DashboardScheduleService::class);
        $discoveryService = app(DashboardDiscoveryService::class);

        // Action center (already wired in S03)
        $actionCenterRaw = $cacheService->getActionCenter($user);
        $actionCenterItems = array_map(
            fn (array $item) => \App\Dto\ActionItem::fromArray($item),
            $actionCenterRaw,
        );
        // Pass the (empty) items to avoid getClearSummary re-querying all 11 sources
        $clearSummary = empty($actionCenterItems)
            ? app(\App\Services\ActionCenterService::class)->getClearSummary($user, [])
            : null;

        // Schedule timeline
        $scheduleGroups = $scheduleService->getUpcomingGames($user);
        $hostAgainBridge = $scheduleService->getHostAgainBridge($user);

        // Nearby & Noteworthy (requires location)
        $geohash4 = $user->geohash4();

        $nearbyNoteworthy = $geohash4
            ? $discoveryService->getNearbyNoteworthy($user, $geohash4)
            : [];

        // Milestone identity cards
        $milestoneCards = $discoveryService->getMilestoneCards($user);

        // Quick actions (role-adapted)
        $quickActions = app(DashboardQuickActionsService::class)->getQuickActions($user);

        // Community Pulse toggle
        $shouldShowCommunityPulse = $discoveryService->shouldShowCommunityPulse($user);

        Log::debug('dashboard.established_data_loaded', [
            'user_id' => $user->id,
            'has_geohash' => $geohash4 !== null,
            'action_center_count' => count($actionCenterItems),
            'schedule_today' => count($scheduleGroups['today'] ?? []),
            'schedule_week' => count($scheduleGroups['this_week'] ?? []),
            'schedule_coming' => count($scheduleGroups['coming_up'] ?? []),
            'nearby_count' => count($nearbyNoteworthy),
            'milestone_count' => count($milestoneCards),
            'quick_actions_count' => count($quickActions),
            'show_pulse' => $shouldShowCommunityPulse,
        ]);

        return [
            'action_center_items' => $actionCenterItems,
            'clear_summary' => $clearSummary,
            'schedule_groups' => $scheduleGroups,
            'host_again_bridge' => $hostAgainBridge,
            'nearby_noteworthy' => $nearbyNoteworthy,
            'milestone_cards' => $milestoneCards,
            'quick_actions' => $quickActions,
            'should_show_community_pulse' => $shouldShowCommunityPulse,
        ];
    }

    /**
     * Get newcomer dashboard data for the user.
     *
     * Only loads data when the dashboard mode is 'newcomer'.
     * Returns empty arrays for all sections when mode is 'established',
     * so the view conditionals hide the newcomer sections cleanly.
     *
     * @return array{welcome: array, preference_matches: array, progress_tracker: array, nearby_people: array}
     */
    private function getNewcomerData(User $user, string $dashboardMode): array
    {
        if ($dashboardMode !== 'newcomer') {
            return [
                'welcome' => [],
                'preference_matches' => [],
                'progress_tracker' => [],
                'nearby_people' => [],
            ];
        }

        $newcomerService = app(DashboardNewcomerService::class);
        $cacheService = app(DashboardCacheService::class);

        // Welcome data (no geohash needed)
        $welcome = $newcomerService->getWelcomeData($user);

        // Progress tracker (no geohash needed)
        $progressTracker = $newcomerService->getProgressTracker($user);

        // Geohash-dependent data — requires user location
        $geohash4 = $user->geohash4();

        $preferenceMatches = $geohash4
            ? $newcomerService->getPreferenceWeightedMatches($user, $geohash4)
            : ['games' => [], 'total_nearby' => 0, 'preference_match_rate' => 0.0];

        $nearbyPeople = $geohash4
            ? $newcomerService->getNearbyPeople($user, $geohash4)
            : ['people' => [], 'total_nearby' => 0];

        Log::debug('dashboard.newcomer_data_loaded', [
            'user_id' => $user->id,
            'has_geohash' => $geohash4 !== null,
            'matches_count' => count($preferenceMatches['games']),
            'people_count' => count($nearbyPeople['people']),
            'progress_step' => $progressTracker['current_step'] ?? 0,
        ]);

        return [
            'welcome' => $welcome,
            'preference_matches' => $preferenceMatches,
            'progress_tracker' => $progressTracker,
            'nearby_people' => $nearbyPeople,
        ];
    }

}

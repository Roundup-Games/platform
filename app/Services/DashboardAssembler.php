<?php

namespace App\Services;

use App\Dto\ActionItem;
use App\Dto\Dashboard\AssembledDashboard;
use App\Dto\Dashboard\CommunityFeed;
use App\Dto\Dashboard\EstablishedDashboard;
use App\Dto\Dashboard\NewcomerDashboard;
use App\Dto\Dashboard\SharedDashboard;
use App\Dto\FeedItem;
use App\Livewire\Dashboard;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Assembles the Dashboard view-model for one viewer.
 *
 * This is the deep presentation-data module behind {@see Dashboard::render()}:
 * it resolves Dashboard mode, reads every Dashboard section the active mode renders, blends
 * the community feed, derives quick actions, and returns one typed {@see AssembledDashboard}.
 *
 * Phase 1 (this class): a behaviour-identical lift of the assembly logic that previously
 * lived in the Livewire component's render() and its private helpers. Services are resolved
 * via `app(...)` inline — exactly as the Livewire component did — to preserve resolution
 * semantics (some collaborators, e.g. DashboardNewcomerService, hold per-instance state that
 * must reset per render the same way it did before). Constructor DI and the cache-registry
 * deepening arrive in Phase 2; see ADR-0001.
 *
 * @see Dashboard::render()
 */
class DashboardAssembler
{
    /**
     * Build the full Dashboard view-model for the given viewer.
     *
     * Deterministic for (user, resolved mode, cache contents). Side effects are limited to
     * dispatching WarmDashboardCache jobs on cache misses (same as the pre-refactor render())
     * and emitting the same Log::debug calls.
     */
    public function assemble(User $user): AssembledDashboard
    {
        $mode = app(DashboardModeService::class)->resolve($user);

        Log::debug('dashboard.render_mode', [
            'user_id' => $user->id,
            'mode' => $mode,
        ]);

        // ── Sections shared by both modes ──────────────
        $smartPrompt = app(DashboardSmartPromptService::class)->getPrompt($user);
        $contributions = app(DashboardCacheService::class)->getContributions($user);
        $weekData = app(DashboardCacheService::class)->getWeekData($user);

        $shared = new SharedDashboard(
            mode: $mode,
            unreadNotificationsCount: $user->unreadNotifications()->count(),
            smartPrompt: $smartPrompt,
            contributions: $contributions,
            weekData: $weekData,
        );

        if ($mode === 'newcomer') {
            return new AssembledDashboard(
                mode: $mode,
                shared: $shared,
                newcomer: $this->buildNewcomer($user, $smartPrompt),
                established: null,
            );
        }

        return new AssembledDashboard(
            mode: $mode,
            shared: $shared,
            newcomer: null,
            established: $this->buildEstablished($user),
        );
    }

    /**
     * Build the newcomer wing: welcome card, progress tracker, preference-weighted matches,
     * nearby people, and the derived quick actions.
     *
     * @param  array<string, mixed>  $smartPrompt
     */
    private function buildNewcomer(User $user, array $smartPrompt): NewcomerDashboard
    {
        $cacheService = app(DashboardCacheService::class);

        $welcome = $cacheService->getNewcomerWelcome($user);

        /** @var array{steps: array<int, array{name: string, route: string, is_complete: bool}>, current_step: int, completion_percentage: int} $progressTracker */
        $progressTracker = $cacheService->getProgressTracker($user);

        $geohash4 = $user->geohash4();

        $preferenceMatches = $geohash4
            ? $cacheService->getNewcomerMatches($user, $geohash4)
            : ['games' => [], 'total_nearby' => 0, 'preference_match_rate' => 0.0];

        $nearbyPeople = $geohash4
            ? $cacheService->getNearbyPeople($user, $geohash4)
            : ['people' => [], 'total_nearby' => 0];

        Log::debug('dashboard.newcomer_data_loaded', [
            'user_id' => $user->id,
            'has_geohash' => $geohash4 !== null,
            'matches_count' => count(is_array($preferenceMatches['games'] ?? null) ? $preferenceMatches['games'] : []),
            'people_count' => count(is_array($nearbyPeople['people'] ?? null) ? $nearbyPeople['people'] : []),
            'progress_step' => $progressTracker['current_step'],
        ]);

        return new NewcomerDashboard(
            quickActions: $this->deriveQuickActions($user, $smartPrompt),
            newcomerWelcome: $welcome,
            preferenceMatches: $preferenceMatches,
            progressTracker: $progressTracker,
            nearbyPeople: $nearbyPeople,
        );
    }

    /**
     * Build the established wing: community feed, opportunities, action center, schedule,
     * nearby noteworthy, milestone cards, role-adapted quick actions, and the pulse toggle.
     */
    private function buildEstablished(User $user): EstablishedDashboard
    {
        $communityFeed = $this->getCommunityFeed($user);
        $opportunities = $this->getOpportunities($user);

        $cacheService = app(DashboardCacheService::class);
        $scheduleService = app(DashboardScheduleService::class);
        $discoveryService = app(DashboardDiscoveryService::class);

        $actionCenterRaw = $cacheService->getActionCenter($user);
        /** @var array<int, array<string, mixed>> $actionCenterItemsRaw */
        $actionCenterItemsRaw = $actionCenterRaw;
        $actionCenterItems = array_values(array_map(
            fn (array $item) => ActionItem::fromArray($item),
            $actionCenterItemsRaw,
        ));
        // Pass the (empty) items to avoid getClearSummary re-querying all 11 sources.
        $clearSummary = empty($actionCenterItems)
            ? app(ActionCenterService::class)->getClearSummary($user, [])
            : null;

        $scheduleGroups = $scheduleService->getUpcomingGames($user);
        $hostAgainBridge = $scheduleService->getHostAgainBridge($user);

        $geohash4 = $user->geohash4();

        $nearbyNoteworthy = $geohash4
            ? $discoveryService->getNearbyNoteworthy($user, $geohash4)
            : [];

        $milestoneCards = $discoveryService->getMilestoneCards($user);
        $establishedQuickActions = app(DashboardQuickActionsService::class)->getQuickActions($user);
        $shouldShowCommunityPulse = $discoveryService->shouldShowCommunityPulse($user);

        Log::debug('dashboard.established_data_loaded', [
            'user_id' => $user->id,
            'has_geohash' => $geohash4 !== null,
            'action_center_count' => count($actionCenterItems),
            'schedule_today' => count(is_array($scheduleGroups['today'] ?? null) ? $scheduleGroups['today'] : []),
            'schedule_week' => count(is_array($scheduleGroups['this_week'] ?? null) ? $scheduleGroups['this_week'] : []),
            'schedule_coming' => count(is_array($scheduleGroups['coming_up'] ?? null) ? $scheduleGroups['coming_up'] : []),
            'nearby_count' => count($nearbyNoteworthy),
            'milestone_count' => count($milestoneCards),
            'quick_actions_count' => count($establishedQuickActions),
            'show_pulse' => $shouldShowCommunityPulse,
        ]);

        $gmAverageRating = ! $user->isGM() ? null : $user->gmProfile?->average_rating;

        /** @var Collection<int, mixed> $newRecaps */
        $newRecaps = collect(app(DashboardCacheService::class)->getRecaps($user))->map(fn ($r) => (object) $r);

        return new EstablishedDashboard(
            communityFeed: new CommunityFeed(
                friends: $communityFeed['friends'],
                trending: $communityFeed['trending'],
                showTrending: $communityFeed['show_trending'],
            ),
            gmAverageRating: $gmAverageRating,
            newRecaps: $newRecaps,
            opportunities: $opportunities,
            actionCenterItems: $actionCenterItems,
            clearSummary: $clearSummary,
            scheduleGroups: $scheduleGroups,
            hostAgainBridge: $hostAgainBridge,
            nearbyNoteworthy: $nearbyNoteworthy,
            milestoneCards: $milestoneCards,
            establishedQuickActions: $establishedQuickActions,
            shouldShowCommunityPulse: $shouldShowCommunityPulse,
        );
    }

    /**
     * Build the blended community feed: friends' activity + trending nearby.
     *
     * @return array{friends: Collection<int, FeedItem>, trending: Collection<int, FeedItem>, show_trending: bool}
     */
    private function getCommunityFeed(User $user): array
    {
        $feedData = app(DashboardCacheService::class)->getFeedData($user);

        /** @var array<int, array<string, mixed>> $feedItems */
        $feedItems = $feedData['items'] ?? [];
        $friendsItems = collect($feedItems)
            ->map(fn (array $item) => FeedItem::fromArray($item))
            ->take(10);

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
     * @return Collection<int, FeedItem>
     */
    private function getTrendingFeedItems(User $user): Collection
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

        /** @var array<int, array<string, mixed>> $gamesList */
        $gamesList = $games;

        return collect($gamesList)->map(function (array $gameData): FeedItem {
            $participantCount = is_int($gameData['participant_count'] ?? null) ? $gameData['participant_count'] : 0;
            $maxPlayers = $gameData['max_players'] ?? null;
            $id = to_string_id($gameData['id'] ?? null);
            $name = is_string($gameData['name'] ?? null) ? $gameData['name'] : '';
            $dateTime = $gameData['date_time'] ?? 'now';

            return new FeedItem(
                id: 'trending_'.$id,
                type: 'trending',
                entityType: 'game',
                entityId: $id,
                entityName: $name,
                userName: null,
                userId: null,
                createdAt: Carbon::parse(is_string($dateTime) || $dateTime instanceof \DateTimeInterface ? $dateTime : 'now'),
                gameSystemName: null,
                participantCount: $participantCount,
                maxPlayers: is_int($maxPlayers) ? $maxPlayers : null,
                imageUrl: null,
            );
        })->take(5);
    }

    /**
     * Get open-game opportunities for the user.
     *
     * @return array<string, mixed>
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
     * @param  array<string, mixed>  $smartPrompt
     * @return array<int, array{label: string, url: string, style: string, icon: string}>
     */
    private function deriveQuickActions(User $user, array $smartPrompt): array
    {
        $actions = [];

        // 1. If there are pending invitations, show that first.
        if ($smartPrompt['type'] === 'pending_invitations') {
            $actions[] = [
                'label' => (string) __('profile.dashboard_prompt_view_invitations'),
                'url' => route('games.index'),
                'style' => 'primary',
                'icon' => 'mail',
            ];
        }

        // 2. If a session just completed, show write-recap action.
        if ($smartPrompt['type'] === 'just_completed') {
            $actionUrl = is_string($smartPrompt['action_url'] ?? null) ? $smartPrompt['action_url'] : route('games.index');
            $actions[] = [
                'label' => (string) __('profile.dashboard_prompt_write_recap'),
                'url' => $actionUrl,
                'style' => 'primary',
                'icon' => 'auto_stories',
            ];
        }

        // 3. Create game is always a useful action for GMs.
        if ($user->isGM()) {
            $actions[] = [
                'label' => (string) __('plan.action_plan_something'),
                'url' => route('plan.create'),
                'style' => count($actions) === 0 ? 'primary' : 'secondary',
                'icon' => 'add_circle',
            ];
        }

        // 4. Discover / find games — always useful as secondary.
        $actions[] = [
            'label' => (string) __('discovery.action_discover'),
            'url' => route('discover'),
            'style' => count($actions) === 0 ? 'primary' : 'secondary',
            'icon' => 'explore',
        ];

        // 5. View schedule if user has upcoming games.
        $meta = is_array($smartPrompt['metadata'] ?? null) ? $smartPrompt['metadata'] : [];
        if (count($actions) < 3 && (is_int($meta['upcoming_count'] ?? null) ? $meta['upcoming_count'] : 0) > 0) {
            $actions[] = [
                'label' => (string) __('profile.dashboard_prompt_view_schedule'),
                'url' => route('games.index'),
                'style' => 'secondary',
                'icon' => 'schedule',
            ];
        }

        return array_slice($actions, 0, 3);
    }
}

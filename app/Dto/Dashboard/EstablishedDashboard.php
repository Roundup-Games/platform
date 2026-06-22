<?php

namespace App\Dto\Dashboard;

use App\Dto\ActionItem;
use Illuminate\Support\Collection;

/**
 * Dashboard sections and derived data rendered only in established mode.
 *
 * @see AssembledDashboard
 */
final readonly class EstablishedDashboard
{
    /**
     * @param  ?float  $gmAverageRating  Null when the viewer is not a GM.
     * @param  Collection<int, mixed>  $newRecaps  Recaps mapped to anonymous objects for the view.
     * @param  array<string, mixed>  $opportunities  Dashboard section: opportunities (geohash-tracked).
     * @param  list<ActionItem>  $actionCenterItems  Dashboard section: action_center, projected to DTOs.
     * @param  ?array<string, mixed>  $clearSummary  Null when there are pending action items.
     * @param  array<string, mixed>  $scheduleGroups  Owned by DashboardScheduleService.
     * @param  ?array<string, mixed>  $hostAgainBridge  Dashboard section: host_again.
     * @param  array<string, mixed>  $nearbyNoteworthy  Owned by DashboardDiscoveryService.
     * @param  array<int, array<string, mixed>>  $milestoneCards  Dashboard section: milestone_cards.
     * @param  array<int, array{label: string, url: string, style: string, icon: string}>  $establishedQuickActions
     */
    public function __construct(
        public CommunityFeed $communityFeed,
        public ?float $gmAverageRating,
        public Collection $newRecaps,
        public array $opportunities,
        public array $actionCenterItems,
        public ?array $clearSummary,
        public array $scheduleGroups,
        public ?array $hostAgainBridge,
        /** @var array<string, mixed> */
        public array $nearbyNoteworthy,
        public array $milestoneCards,
        public array $establishedQuickActions,
        public bool $shouldShowCommunityPulse,
    ) {}
}

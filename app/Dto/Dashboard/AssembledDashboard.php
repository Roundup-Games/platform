<?php

namespace App\Dto\Dashboard;

use App\Services\DashboardAssembler;

/**
 * The full Dashboard for one viewer, ready to render.
 *
 * Built exclusively by the Dashboard assembler. Exactly one of `$newcomer` / `$established`
 * is non-null, determined by `$shared->mode`. The inactive wing is null — no stub props —
 * which is what lets Phase 3 branch the Blade on `mode` and delete the stub symmetry.
 *
 * Phase 1 keeps the Blade untouched via {@see toViewProps()}, which projects the typed
 * view-model back into the flat 26-key dictionary `livewire.dashboard` consumes today,
 * emitting the legacy stub values for the inactive mode's keys. `toViewProps()` is deleted
 * in Phase 3 once the Blade reads typed props off the view-model directly.
 *
 * @see DashboardAssembler
 */
final class AssembledDashboard
{
    public function __construct(
        public readonly string $mode,
        public readonly SharedDashboard $shared,
        public readonly ?NewcomerDashboard $newcomer = null,
        public readonly ?EstablishedDashboard $established = null,
    ) {}

    public function isNewcomer(): bool
    {
        return $this->mode === 'newcomer';
    }

    public function isEstablished(): bool
    {
        return $this->mode === 'established';
    }

    /**
     * Legacy bridge: project the typed view-model into the flat view-variable dictionary
     * the `livewire.dashboard` Blade wrapper expects.
     *
     * The inactive mode's keys are emitted with stub values because the test suite
     * asserts on the full view-data contract regardless of mode (e.g.
     * `DashboardNewcomerTest::'dashboard does not render newcomer data for established user'`
     * reads `viewData('newcomerWelcome')` on an established-mode user and expects `[]`).
     * The Blade wrapper branches on `dashboardMode` and only passes each partial its own
     * vars, so the stubs are inert at render time — but they remain part of the view
     * contract until the test suite is updated alongside a Blade modernization.
     *
     * @return array<string, mixed>
     */
    public function toViewProps(): array
    {
        // Shared keys emitted in both modes.
        $props = [
            'dashboardMode' => $this->shared->mode,
            'smartPrompt' => $this->shared->smartPrompt,
            'unreadNotificationsCount' => $this->shared->unreadNotificationsCount,
            'contributions' => $this->shared->contributions,
            'weekData' => $this->shared->weekData,
        ];

        if ($this->isNewcomer() && $this->newcomer instanceof NewcomerDashboard) {
            return $props + [
                // Newcomer sections.
                'quickActions' => $this->newcomer->quickActions,
                'newcomerWelcome' => $this->newcomer->newcomerWelcome,
                'preferenceMatches' => $this->newcomer->preferenceMatches,
                'progressTracker' => $this->newcomer->progressTracker,
                'nearbyPeople' => $this->newcomer->nearbyPeople,

                // Established stubs (inert at render — Blade branches on mode — but
                // asserted by the test suite for the inactive mode).
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
            ];
        }

        $e = $this->established();

        return $props + [
            // Newcomer stubs (inert at render — asserted by the test suite for the inactive mode).
            'quickActions' => [],
            'newcomerWelcome' => [],
            'preferenceMatches' => [],
            'progressTracker' => [],
            'nearbyPeople' => [],

            // Established sections.
            'communityFeed' => $e->communityFeed->friends,
            'trendingItems' => $e->communityFeed->trending,
            'hasTrendingSection' => $e->communityFeed->showTrending,
            'gmAverageRating' => $e->gmAverageRating,
            'newRecaps' => $e->newRecaps,
            'opportunities' => $e->opportunities,
            'actionCenterItems' => $e->actionCenterItems,
            'clearSummary' => $e->clearSummary,
            'scheduleGroups' => $e->scheduleGroups,
            'hostAgainBridge' => $e->hostAgainBridge,
            'nearbyNoteworthy' => $e->nearbyNoteworthy,
            'milestoneCards' => $e->milestoneCards,
            'establishedQuickActions' => $e->establishedQuickActions,
            'shouldShowCommunityPulse' => $e->shouldShowCommunityPulse,
        ];
    }

    /**
     * Convenience for tests / Phase 3 Blade: the active newcomer wing, throwing if inactive.
     */
    public function newcomer(): NewcomerDashboard
    {
        return $this->newcomer ?? throw new \LogicException('Dashboard is not in newcomer mode.');
    }

    /**
     * Convenience for tests / Phase 3 Blade: the active established wing, throwing if inactive.
     */
    public function established(): EstablishedDashboard
    {
        return $this->established ?? throw new \LogicException('Dashboard is not in established mode.');
    }
}

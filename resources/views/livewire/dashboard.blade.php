@section('title', __('profile.content_dashboard'))

@php
    // Mode-based rendering on the typed view-model. The wrapper unpacks the
    // active mode's wing into the flat variables each partial already consumes;
    // the inactive mode is null (no stub props).
    $partial = null;
    $partialData = [];

    if ($dashboard !== null && $dashboard->isNewcomer() && $dashboard->newcomer !== null) {
        $newcomer = $dashboard->newcomer;
        $shared = $dashboard->shared;
        $partial = 'livewire.dashboard-newcomer';
        $partialData = [
            'newcomerWelcome' => $newcomer->newcomerWelcome,
            'preferenceMatches' => $newcomer->preferenceMatches,
            'progressTracker' => $newcomer->progressTracker,
            'nearbyPeople' => $newcomer->nearbyPeople,
            'quickActions' => $newcomer->quickActions,
            'unreadNotificationsCount' => $shared->unreadNotificationsCount,
            'smartPrompt' => $shared->smartPrompt,
        ];
    } elseif ($dashboard !== null && $dashboard->established !== null) {
        $established = $dashboard->established;
        $shared = $dashboard->shared;
        $partial = 'livewire.dashboard-established';
        $partialData = [
            'actionCenterItems' => $established->actionCenterItems,
            'clearSummary' => $established->clearSummary,
            'scheduleGroups' => $established->scheduleGroups,
            'hostAgainBridge' => $established->hostAgainBridge,
            'nearbyNoteworthy' => $established->nearbyNoteworthy,
            'milestoneCards' => $established->milestoneCards,
            'quickActions' => $established->establishedQuickActions,
            'communityFeed' => $established->communityFeed->friends,
            'shouldShowCommunityPulse' => $established->shouldShowCommunityPulse,
            'smartPrompt' => $shared->smartPrompt,
            'unreadNotificationsCount' => $shared->unreadNotificationsCount,
        ];
    }
@endphp

<div class="py-4">
    <div class="max-w-7xl mx-auto space-y-6">
        @if($partial !== null)
            @include($partial, $partialData)
        @endif
    </div>
</div>

@section('title', __('profile.content_dashboard'))

<div class="py-4">
    <div class="max-w-7xl mx-auto space-y-6">

        {{-- Mode-based rendering: newcomer gets its own template --}}
        @if($dashboardMode === 'newcomer')
            @include('livewire.dashboard-newcomer', [
                'newcomerWelcome' => $newcomerWelcome,
                'preferenceMatches' => $preferenceMatches,
                'progressTracker' => $progressTracker,
                'nearbyPeople' => $nearbyPeople,
                'quickActions' => $quickActions,
                'unreadNotificationsCount' => $unreadNotificationsCount,
                'smartPrompt' => $smartPrompt,
            ])
        @else
            @include('livewire.dashboard-established', [
                'actionCenterItems' => $actionCenterItems,
                'clearSummary' => $clearSummary,
                'scheduleGroups' => $scheduleGroups,
                'hostAgainBridge' => $hostAgainBridge,
                'nearbyNoteworthy' => $nearbyNoteworthy,
                'milestoneCards' => $milestoneCards,
                'quickActions' => $establishedQuickActions,
                'communityFeed' => $communityFeed,
                'shouldShowCommunityPulse' => $shouldShowCommunityPulse,
                'smartPrompt' => $smartPrompt,
                'unreadNotificationsCount' => $unreadNotificationsCount,
            ])
        @endif

    </div>
</div>

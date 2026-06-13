@props([
    'actionCenterItems' => [],
    'clearSummary' => null,
    'scheduleGroups' => ['today' => [], 'this_week' => [], 'coming_up' => []],
    'hostAgainBridge' => null,
    'nearbyNoteworthy' => [],
    'milestoneCards' => [],
    'quickActions' => [],
    'communityFeed' => [],
    'shouldShowCommunityPulse' => false,
    'smartPrompt' => [],
    'unreadNotificationsCount' => 0,
])

{{-- ═══ Smart Prompt ═══ --}}
@if(($smartPrompt['message'] ?? null))
<div class="bg-surface-container-lowest rounded-xl shadow-ambient p-4 sm:p-5" role="status" aria-live="polite">
    <div class="flex items-start justify-between gap-4">
        <div class="flex items-start gap-3 flex-1 min-w-0">
            @php
                $promptIcon = match($smartPrompt['type']) {
                    'pending_invitations' => 'mail',
                    'upcoming_session' => 'schedule',
                    'just_completed' => 'auto_stories',
                    'empty_week' => 'event_available',
                    'new_follower' => 'person_add',
                    default => 'auto_awesome',
                };
            @endphp
            <span aria-hidden="true" class="material-symbols-outlined text-primary text-xl mt-0.5 shrink-0"
                  style="font-variation-settings: 'FILL' 0">{{ $promptIcon }}</span>
            <div class="min-w-0">
                <p class="text-on-surface text-sm leading-relaxed">{{ $smartPrompt['message'] }}</p>
                @if($smartPrompt['action_url'] ?? null)
                    <a href="{{ $smartPrompt['action_url'] }}" wire:navigate
                       class="inline-flex items-center gap-1.5 mt-2 text-sm font-semibold text-primary hover:underline">
                        {{ $smartPrompt['action_label'] ?? __('profile.dashboard_prompt_view_details') }}
                        <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_forward</span>
                    </a>
                @endif
            </div>
        </div>
        @if($unreadNotificationsCount > 0)
            <a href="{{ route('notifications.index') }}" wire:navigate
               aria-label="{{ $unreadNotificationsCount }} {{ __('profile.dashboard_stats_unread_notifications') }}"
               class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-primary/10 text-primary text-xs font-medium hover:bg-primary/20 transition-colors shrink-0">
                <span aria-hidden="true" class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 1">notifications</span>
                {{ $unreadNotificationsCount }}
            </a>
        @endif
    </div>
</div>
@endif

{{-- ═══ Action Center ═══ --}}
@include('livewire.partials.action-center', [
    'items' => $actionCenterItems,
    'clearSummary' => $clearSummary,
])

{{-- ═══ Your Schedule ═══ --}}
@include('livewire.partials.schedule-timeline', [
    'scheduleGroups' => $scheduleGroups,
    'hostAgainBridge' => $hostAgainBridge,
])

{{-- ═══ Nearby & Noteworthy ═══ --}}
@include('livewire.partials.nearby-noteworthy', [
    'nearbyNoteworthy' => $nearbyNoteworthy,
])

{{-- ═══ Community Pulse (conditional) ═══ --}}
@if($shouldShowCommunityPulse)
    @include('livewire.partials.community-pulse', [
        'communityFeed' => $communityFeed,
    ])
@endif

{{-- ═══ Your Story (conditional on milestone cards) ═══ --}}
@if(count($milestoneCards) > 0)
    @include('livewire.partials.your-story', [
        'milestoneCards' => $milestoneCards,
    ])
@endif

{{-- ═══ Quick Actions ═══ --}}
@if(count($quickActions) > 0)
<nav class="flex flex-wrap gap-3" aria-label="{{ __('profile.dashboard_quick_actions_heading') }}">
    @foreach($quickActions as $action)
        <a href="{{ $action['url'] }}" wire:navigate
           class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-medium transition-colors {{ $action['style'] === 'primary' ? 'bg-primary text-on-primary hover:bg-primary/90' : 'border border-outline-variant text-on-surface hover:bg-surface-container-low' }}">
            <span aria-hidden="true" class="material-symbols-outlined text-lg" style="font-variation-settings: 'FILL' {{ $action['style'] === 'primary' ? '1' : '0' }}">{{ $action['icon'] }}</span>
            {{ __($action['label']) }}
        </a>
    @endforeach
</nav>
@endif

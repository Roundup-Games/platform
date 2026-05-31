@props([
    'scheduleGroups' => ['today' => [], 'this_week' => [], 'coming_up' => []],
    'hostAgainBridge' => null,
])

@php
    $hasToday = !empty($scheduleGroups['today']);
    $hasWeek = !empty($scheduleGroups['this_week']);
    $hasComing = !empty($scheduleGroups['coming_up']);
    $hasAnyGames = $hasToday || $hasWeek || $hasComing;
    $totalGames = count($scheduleGroups['today'] ?? []) + count($scheduleGroups['this_week'] ?? []) + count($scheduleGroups['coming_up'] ?? []);
@endphp

<div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
    {{-- Header --}}
    <div class="flex items-center justify-between mb-4">
        <h3 class="font-heading text-lg font-semibold text-on-surface flex items-center gap-2">
            <span aria-hidden="true" class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">date_range</span>
            {{ __('profile.dashboard_schedule_heading') }}
        </h3>
        @if($totalGames > 0)
            <span class="text-sm text-on-surface-variant">
                {{ $totalGames }} {{ trans_choice('games.content_games', $totalGames) }}
            </span>
        @endif
    </div>

    @if($hasAnyGames)
        {{-- Grouped timeline sections --}}
        <div class="space-y-5">
            {{-- Today --}}
            @if($hasToday)
                <div>
                    <h4 class="text-sm font-semibold text-primary flex items-center gap-1.5 mb-2">
                        <span class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 1" aria-hidden="true">today</span>
                        {{ __('profile.dashboard_schedule_today') }}
                    </h4>
                    <div class="space-y-2">
                        @foreach($scheduleGroups['today'] as $game)
                            @include('livewire.partials.schedule-timeline-game', ['game' => $game])
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- This Week --}}
            @if($hasWeek)
                <div>
                    <h4 class="text-sm font-semibold text-on-surface flex items-center gap-1.5 mb-2">
                        <span class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 0" aria-hidden="true">date_range</span>
                        {{ __('profile.dashboard_schedule_this_week') }}
                    </h4>
                    <div class="space-y-2">
                        @foreach($scheduleGroups['this_week'] as $game)
                            @include('livewire.partials.schedule-timeline-game', ['game' => $game])
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Coming Up --}}
            @if($hasComing)
                <div>
                    <h4 class="text-sm font-semibold text-on-surface-variant flex items-center gap-1.5 mb-2">
                        <span class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 0" aria-hidden="true">event_upcoming</span>
                        {{ __('profile.dashboard_schedule_coming_up') }}
                    </h4>
                    <div class="space-y-2">
                        @foreach($scheduleGroups['coming_up'] as $game)
                            @include('livewire.partials.schedule-timeline-game', ['game' => $game])
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @elseif($hostAgainBridge && isset($hostAgainBridge['game']))
        {{-- Host Again bridge card --}}
        <div class="bg-primary/5 rounded-xl border border-primary/20 p-5">
            <div class="flex items-start gap-4">
                <div class="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center flex-shrink-0">
                    <span class="material-symbols-outlined text-primary text-xl" style="font-variation-settings: 'FILL' 1" aria-hidden="true">replay</span>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-on-surface">
                        {{ __('profile.dashboard_schedule_host_again') }}
                    </p>
                    <p class="text-sm text-on-surface-variant mt-1">
                        {{ $hostAgainBridge['game']['name'] ?? '' }}
                        @if($hostAgainBridge['game']['system'] ?? null)
                            <span class="inline-block text-[10px] font-semibold px-2 py-0.5 rounded-full bg-primary/10 text-primary ml-1.5 align-middle">
                                {{ $hostAgainBridge['game']['system'] }}
                            </span>
                        @endif
                    </p>
                    @if($hostAgainBridge['game']['expected_duration'] ?? null)
                        <p class="text-xs text-on-surface-variant mt-0.5 flex items-center gap-1">
                            <span class="material-symbols-outlined text-xs" aria-hidden="true">schedule</span>
                            {{ $hostAgainBridge['game']['expected_duration'] }} {{ __('common.unit_min') }}
                        </p>
                    @endif
                    @if($hostAgainBridge['clone_url'] ?? null)
                        <a href="{{ $hostAgainBridge['clone_url'] }}" wire:navigate
                           class="inline-flex items-center gap-2 mt-3 px-4 py-2 rounded-xl bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 transition-colors">
                            <span class="material-symbols-outlined text-lg" style="font-variation-settings: 'FILL' 1" aria-hidden="true">content_copy</span>
                            {{ __('profile.dashboard_schedule_host_again') }}
                        </a>
                    @endif
                </div>
            </div>
        </div>
    @else
        {{-- Empty state — no games, no host-again --}}
        <div class="text-center py-8">
            <span aria-hidden="true" class="material-symbols-outlined text-on-surface-variant text-4xl mb-2 block" style="font-variation-settings: 'FILL' 0">event_available</span>
            <p class="text-on-surface-variant text-sm mb-3">{{ __('profile.dashboard_schedule_nothing_scheduled') }}</p>
            <a href="{{ route('discover') }}" wire:navigate
               class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 transition-colors">
                <span aria-hidden="true" class="material-symbols-outlined text-lg" style="font-variation-settings: 'FILL' 1">explore</span>
                {{ __('profile.dashboard_your_week_find_game') }}
            </a>
        </div>
    @endif
</div>

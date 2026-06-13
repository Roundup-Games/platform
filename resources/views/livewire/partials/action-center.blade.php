@props([
    'items' => [],
    'clearSummary' => null,
    'maxVisible' => 8,
])

{{-- Action Center: renders prioritized action items including attendance nudges, --}}
{{-- waitlist confirmations, invitations, recaps, and other pending actions. --}}

@php
    $totalItems = count($items);
    $visibleItems = array_slice($items, 0, $maxVisible);
    $hasOverflow = $totalItems > $maxVisible;
    $priorityColors = [
        'critical' => 'bg-error',
        'high' => 'bg-warning',
        'medium' => 'bg-primary',
        'low' => 'bg-on-surface-variant/40',
    ];
@endphp

<div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
    {{-- Header --}}
    <div class="flex items-center justify-between mb-4">
        <h3 class="font-heading text-lg font-semibold text-on-surface flex items-center gap-2">
            <span aria-hidden="true" class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">recommend</span>
            {{ __('profile.dashboard_action_center_heading') }}
        </h3>
        @if($totalItems > 0)
            <span class="inline-flex items-center justify-center min-w-[24px] h-6 px-2 rounded-full bg-primary text-on-primary text-xs font-semibold">
                {{ $totalItems }}
            </span>
        @endif
    </div>

    @if($totalItems > 0)
        {{-- Item List --}}
        <ul class="space-y-2" role="list">
            @foreach($visibleItems as $item)
                @php
                    $dotColor = $priorityColors[$item->priority] ?? 'bg-on-surface-variant/40';
                    $expiresAt = $item->metadata['expires_at'] ?? null;
                    $expiryText = null;
                    if ($expiresAt) {
                        $expiry = \Carbon\Carbon::parse($expiresAt);
                        $diffMinutes = now()->diffInMinutes($expiry, false);
                        if ($diffMinutes > 0) {
                            $hours = intdiv($diffMinutes, 60);
                            $mins = $diffMinutes % 60;
                            $expiryText = $hours > 0
                                ? __('profile.dashboard_action_expires_in_hm', ['hours' => $hours, 'minutes' => $mins])
                                : __('profile.dashboard_action_expires_in_m', ['minutes' => $mins]);
                        }
                    }
                @endphp

                <li>
                    <a href="{{ $item->actionUrl }}" wire:navigate
                       class="flex items-start gap-3 p-3 rounded-lg hover:bg-surface-container-low transition-colors group">

                        {{-- Priority dot --}}
                        <span class="w-2.5 h-2.5 rounded-full {{ $dotColor }} mt-2 shrink-0" aria-label="{{ $item->priority }} priority"></span>

                        {{-- Icon --}}
                        <span aria-hidden="true" class="material-symbols-outlined text-on-surface-variant text-xl mt-0.5 shrink-0"
                              style="font-variation-settings: 'FILL' 0">{{ $item->icon }}</span>

                        {{-- Content --}}
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-on-surface group-hover:text-primary transition-colors leading-snug">
                                {{ $item->title }}
                            </p>
                            <p class="text-xs text-on-surface-variant mt-0.5 line-clamp-2 leading-relaxed">
                                {{ $item->description }}
                            </p>
                            @if($expiryText)
                                <p class="text-xs text-error/80 mt-1 flex items-center gap-1">
                                    <span class="material-symbols-outlined text-xs" aria-hidden="true">schedule</span>
                                    {{ $expiryText }}
                                </p>
                            @endif
                        </div>

                        {{-- Action chevron --}}
                        <span aria-hidden="true" class="material-symbols-outlined text-on-surface-variant text-lg mt-1 shrink-0 group-hover:text-primary transition-colors"
                              style="font-variation-settings: 'FILL' 0">chevron_right</span>
                    </a>
                </li>
            @endforeach
        </ul>

        @if($hasOverflow)
            <div class="mt-3 pt-3 border-t border-outline-variant/30 text-center">
                <a href="{{ route('games.index') }}" wire:navigate
                   class="inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline">
                    {{ trans_choice('profile.dashboard_action_center_view_all', $totalItems - $maxVisible, ['count' => $totalItems - $maxVisible]) }}
                    <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_forward</span>
                </a>
            </div>
        @endif
    @else
        {{-- All-clear state --}}
        <div class="text-center py-8">
            <span aria-hidden="true" class="material-symbols-outlined text-4xl text-primary/60 mb-2 block"
                  style="font-variation-settings: 'FILL' 1">check_circle</span>
            <p class="text-on-surface font-medium">{{ $clearSummary['message'] ?? __('profile.dashboard_action_center_all_clear') }}</p>

            @if($clearSummary && !empty($clearSummary['next_game']))
                <p class="text-sm text-on-surface-variant mt-2 flex items-center justify-center gap-1.5">
                    <span class="material-symbols-outlined text-base" aria-hidden="true" style="font-variation-settings: 'FILL' 0">event_upcoming</span>
                    {{ __('profile.dashboard_action_center_next_session', [
                        'name' => $clearSummary['next_game']['name'],
                        'date' => format_date(\Carbon\Carbon::parse($clearSummary['next_game']['date_time']), 'datetime'),
                    ]) }}
                </p>
                <a href="{{ $clearSummary['next_game']['url'] }}" wire:navigate
                   class="inline-flex items-center gap-2 mt-3 px-4 py-2 rounded-xl bg-primary/10 text-primary text-sm font-medium hover:bg-primary/20 transition-colors">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true" style="font-variation-settings: 'FILL' 1">event_upcoming</span>
                    {{ __('profile.dashboard_action_center_view_session') }}
                </a>
            @else
                <p class="text-sm text-on-surface-variant mt-2">{{ __('profile.dashboard_action_center_no_session') }}</p>
                <a href="{{ route('discover') }}" wire:navigate
                   class="inline-flex items-center gap-2 mt-3 px-4 py-2 rounded-xl bg-primary/10 text-primary text-sm font-medium hover:bg-primary/20 transition-colors">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true" style="font-variation-settings: 'FILL' 1">explore</span>
                    {{ __('profile.dashboard_action_center_find_game') }}
                </a>
            @endif
        </div>
    @endif
</div>

@props([
    'tier' => 'newcomer',
    'score' => null,
    'gameCount' => 0,
    'showDetails' => false,
])

@php
    $tierConfig = [
        'reliable' => [
            'icon' => 'verified',
            'color' => 'text-primary',
            'bg' => 'bg-primary/10',
            'label' => __('profile.reliability_tier_reliable'),
        ],
        'active' => [
            'icon' => 'bolt',
            'color' => 'text-tertiary',
            'bg' => 'bg-tertiary/10',
            'label' => __('profile.reliability_tier_active'),
        ],
        'newcomer' => [
            'icon' => 'person',
            'color' => 'text-on-surface-variant',
            'bg' => 'bg-surface-container-high',
            'label' => __('profile.reliability_tier_newcomer'),
        ],
    ];

    $config = $tierConfig[$tier] ?? $tierConfig['newcomer'];
@endphp

<div class="inline-flex items-center gap-1.5">
    {{-- Tier Badge --}}
    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium {{ $config['bg'] }} {{ $config['color'] }}">
        <span class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 1" aria-hidden="true">{{ $config['icon'] }}</span>
        {{ $config['label'] }}
    </span>

    {{-- Detailed stats (only when showDetails and enough games) --}}
    @if($showDetails && $gameCount >= 5 && $score !== null)
        <span class="text-xs text-on-surface-variant">
            {{ $score }}% {{ __('profile.reliability_attendance_rate') }}
        </span>
        <span class="text-xs text-on-surface-variant/60">
            &middot; {{ $gameCount }} {{ __('profile.reliability_games_played') }}
        </span>
    @endif
</div>

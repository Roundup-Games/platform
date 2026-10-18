@php
    // Rarity colour ladder (spec §2.4): one visual step per rarity class,
    // built from the established pill tokens (reliability-badge tierConfig
    // pattern: bg tint + text colour per tier).
    $rarityStyles = [
        'common' => 'bg-surface-container-high text-on-surface-variant',
        'uncommon' => 'bg-tertiary/10 text-tertiary',
        'rare' => 'bg-primary/10 text-primary',
        'elite' => 'bg-secondary/10 text-secondary',
        'legendary' => 'bg-amber-500/10 text-amber-700',
    ];

    $isHeld = $state === 'held';
    $isGreyed = $state === 'lapsed' || $state === 'dormant';

    // Held: rarity-driven colour (primary tint when rarity is not yet
    // computed). Lapsed/dormant: neutral greyed pill. Unearned: muted
    // outline with no fill.
    $pillClasses = match (true) {
        $isHeld => ($rarity !== null && isset($rarityStyles[$rarity->value])
            ? $rarityStyles[$rarity->value]
            : 'bg-primary/10 text-primary'),
        $isGreyed => 'bg-surface-container-high text-on-surface-variant opacity-60',
        default => 'border border-outline-variant text-on-surface-variant',
    };

    $sizeClasses = $size === 'sm'
        ? 'px-2 py-0.5 text-[11px] gap-1'
        : 'px-2.5 py-1 text-xs gap-1.5';

    // Visible caption copy (no tooltip component exists — accessibility
    // relies on rendered text, never hover-only affordances).
    $caption = match ($state) {
        'lapsed' => __('badges.content_badge_state_re_earn'),
        'dormant' => __('badges.content_badge_state_coming_soon'),
        default => null,
    };
@endphp

<span class="inline-flex items-center {{ $sizeClasses }} {{ $pillClasses }} rounded-full font-medium">
    <span class="material-symbols-outlined text-sm{{ $isGreyed ? ' grayscale' : '' }}"{!! $isHeld ? " style=\"font-variation-settings: 'FILL' 1\"" : '' !!} aria-hidden="true">{{ $icon }}</span>
    <span>{{ $name }}@if($tierLabel)({{ $tierLabel }})@endif</span>
    @if($caption)
        <span class="opacity-80">&middot; {{ $caption }}</span>
    @endif
    @if($featured)
        <span class="material-symbols-outlined text-sm text-amber-500" style="font-variation-settings: 'FILL' 1" aria-hidden="true">star</span>
        <span class="sr-only">{{ __('badges.content_badge_label_featured') }}</span>
    @endif
    @if($isHeld && $rarity !== null)
        <span class="sr-only">{{ $rarity->label() }}</span>
    @endif
</span>

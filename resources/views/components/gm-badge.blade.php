@props([
    'size' => 'md',
])

@php
    $sizeClasses = match ($size) {
        'sm' => 'px-1.5 py-0.5 text-xs gap-0.5',
        default => 'px-2.5 py-1 text-xs gap-1',
    };

    $iconSize = match ($size) {
        'sm' => 'text-xs',
        default => 'text-sm',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center {$sizeClasses} rounded-full font-medium bg-primary/10 text-primary"]) }}>
    <span class="material-symbols-outlined {{ $iconSize }}" style="font-variation-settings: 'FILL' 1" aria-hidden="true">school</span>
    {{ __('profile.gm_badge_label') }}
</span>

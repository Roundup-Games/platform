@props([
    'type' => 'boardgame',
    'class' => 'w-8 h-8',
])

@php
    $file = $type === 'ttrpg' ? 'marker-ttrpg' : 'marker-boardgame';
    $alt = $type === 'ttrpg' ? 'TTRPG session' : 'Board game session';
@endphp

<picture>
    <source srcset="/images/{{ $file }}.webp" type="image/webp">
    <img
        src="/images/{{ $file }}.png"
        alt="{{ $alt }}"
        width="64"
        height="64"
        class="{{ $class }}"
        loading="lazy"
        decoding="async"
    >
</picture>

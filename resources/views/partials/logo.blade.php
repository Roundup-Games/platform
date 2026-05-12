@props(['class' => 'h-8 w-auto', 'nav' => true])

{{-- Use navbar-sized images (150×82, ~5KB each) when in nav context, full-size otherwise --}}
@php
    $suffix = $nav ? '-nav' : '';
    $w = $nav ? 150 : 339;
    $h = $nav ? 82 : 185;
@endphp

{{-- Light variant: visible by default, hidden in dark mode --}}
<picture class="block dark:hidden">
    <source srcset="/images/logo-light-background{{ $suffix }}.webp" type="image/webp">
    <img
        src="/images/logo-light-background{{ $suffix }}.png"
        alt="Roundup Games"
        width="{{ $w }}"
        height="{{ $h }}"
        class="{{ $class }}"
        loading="eager"
        decoding="async"
    >
</picture>

{{-- Dark variant: hidden by default, visible in dark mode --}}
{{-- Lazy-loaded since it's hidden in one color scheme --}}
<picture class="hidden dark:block">
    <source srcset="/images/logo-dark-background{{ $suffix }}.webp" type="image/webp">
    <img
        src="/images/logo-dark-background{{ $suffix }}.png"
        alt="Roundup Games"
        width="{{ $w }}"
        height="{{ $h }}"
        class="{{ $class }}"
        loading="lazy"
        decoding="async"
    >
</picture>

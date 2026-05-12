@props(['class' => 'h-8 w-auto'])

{{-- Light variant: visible by default, hidden in dark mode --}}
<picture class="block dark:hidden">
    <source srcset="/images/logo-light-background.webp" type="image/webp">
    <img
        src="/images/logo-light-background.png"
        alt="Roundup Games"
        width="339"
        height="185"
        class="{{ $class }}"
        loading="eager"
        decoding="async"
    >
</picture>

{{-- Dark variant: hidden by default, visible in dark mode --}}
<picture class="hidden dark:block">
    <source srcset="/images/logo-dark-background.webp" type="image/webp">
    <img
        src="/images/logo-dark-background.png"
        alt="Roundup Games"
        width="339"
        height="185"
        class="{{ $class }}"
        loading="eager"
        decoding="async"
    >
</picture>

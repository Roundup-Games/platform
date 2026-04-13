@props(['size' => 'default'])

@php
    $iconSize = $size === 'small' ? 'text-lg' : 'text-2xl';
    $buttonSize = $size === 'small' ? 'p-1.5' : 'p-2';
@endphp

<button
    x-data="{
        dark: localStorage.getItem('theme') === 'dark'
            || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)
    }"
    x-init="$watch('dark', (val) => {
        document.documentElement.classList.toggle('dark', val);
        localStorage.setItem('theme', val ? 'dark' : 'light');
    })"
    x-effect="document.documentElement.classList.toggle('dark', dark)"
    @click="dark = !dark"
    type="button"
    class="rounded-xl {{ $buttonSize }} text-on-surface-variant hover:text-primary hover:bg-surface-container-high focus:outline-none focus:ring-2 focus:ring-primary/50 transition-colors duration-200"
    aria-label="Toggle dark mode"
    {{ $attributes }}
>
    {{-- Sun icon (shown in dark mode) --}}
    <span x-show="dark" class="material-symbols-outlined {{ $iconSize }}" aria-hidden="true">light_mode</span>
    {{-- Moon icon (shown in light mode) --}}
    <span x-show="!dark" class="material-symbols-outlined {{ $iconSize }}" aria-hidden="true">dark_mode</span>
</button>

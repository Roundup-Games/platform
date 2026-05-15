@props(['size' => 'default'])

@php
    $iconSize = $size === 'small' ? 'text-lg' : 'text-2xl';
    $buttonSize = $size === 'small' ? 'p-1.5' : 'p-2';
@endphp

<div
    x-data="{
        theme: localStorage.getItem('theme') || 'system',
        resolved: window.matchMedia('(prefers-color-scheme: dark)').matches
            ? (localStorage.getItem('theme') === null || localStorage.getItem('theme') === 'system'
                ? 'dark' : (localStorage.getItem('theme') === 'dark' ? 'dark' : 'light'))
            : (localStorage.getItem('theme') === 'dark' ? 'dark' : 'light'),
        open: false,

        apply(theme) {
            this.theme = theme;
            localStorage.setItem('theme', theme);
            this.resolve();
        },

        resolve() {
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            this.resolved = (this.theme === 'system' ? prefersDark : this.theme === 'dark') ? 'dark' : 'light';
            document.documentElement.classList.toggle('dark', this.resolved === 'dark');
        },

        iconName() {
            if (this.theme === 'light') return 'light_mode';
            if (this.theme === 'dark') return 'dark_mode';
            return 'routine';
        },

        label() {
            if (this.theme === 'light') return '{{ __('common.content_light') }}';
            if (this.theme === 'dark') return '{{ __('common.content_dark') }}';
            return '{{ __('common.content_system') }}';
        }
    }"
    x-init="
        resolve();
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            if (theme === 'system') resolve();
        });
    "
    @keydown.escape.window="open = false"
    class="relative"
    wire:ignore
>
    <button
        @click="open = !open"
        type="button"
        class="flex items-center gap-2 rounded-xl {{ $buttonSize }} text-on-surface-variant hover:text-primary hover:bg-surface-container-high focus:outline-none focus:ring-2 focus:ring-primary/50 transition-colors duration-200"
        aria-label="Toggle theme"
        :aria-expanded="open.toString()"
    >
        <span class="material-symbols-outlined {{ $iconSize }}" x-text="iconName()" aria-hidden="true"></span>
        @if($size !== 'small')
            <span class="text-sm font-medium" x-text="label()"></span>
        @endif
    </button>

    {{-- Dropdown --}}
    <div
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        @click.away="open = false"
        class="absolute bottom-full left-0 mb-2 w-40 rounded-xl bg-surface-container-lowest shadow-ambient border border-outline-variant/15 py-1 z-50"
        role="menu"
    >
        <button
            @click="apply('light'); open = false"
            type="button"
            class="w-full flex items-center gap-3 px-4 py-2.5 text-sm font-medium transition-colors"
            :class="theme === 'light' ? 'text-primary bg-primary/10' : 'text-on-surface-variant hover:bg-surface-container-high hover:text-primary'"
            role="menuitem"
        >
            <span class="material-symbols-outlined text-lg" aria-hidden="true">light_mode</span>
            {{ __('common.content_light') }}
        </button>
        <button
            @click="apply('dark'); open = false"
            type="button"
            class="w-full flex items-center gap-3 px-4 py-2.5 text-sm font-medium transition-colors"
            :class="theme === 'dark' ? 'text-primary bg-primary/10' : 'text-on-surface-variant hover:bg-surface-container-high hover:text-primary'"
            role="menuitem"
        >
            <span class="material-symbols-outlined text-lg" aria-hidden="true">dark_mode</span>
            {{ __('common.content_dark') }}
        </button>
        <button
            @click="apply('system'); open = false"
            type="button"
            class="w-full flex items-center gap-3 px-4 py-2.5 text-sm font-medium transition-colors"
            :class="theme === 'system' ? 'text-primary bg-primary/10' : 'text-on-surface-variant hover:bg-surface-container-high hover:text-primary'"
            role="menuitem"
        >
            <span class="material-symbols-outlined text-lg" aria-hidden="true">routine</span>
            {{ __('common.content_system') }}
        </button>
    </div>
</div>

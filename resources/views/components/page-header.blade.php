@props([
    'title',                        // string — page title (rendered as the in-page h1)
    'subtitle' => null,             // string|null — secondary line under the title (e.g. entity name)
    'backUrl' => null,              // string|null — href for the back link row
    'backLabel' => null,            // string|null — label for the back link row
])

{{--
    In-page page header for authenticated (layouts.app) pages.
    Renders at every breakpoint — unlike the layout's `header` slot, which
    only exists in the desktop-only top bar. Pages set @section('title', ...)
    for the browser tab and place their actions in the `actions` slot, which
    wraps below the title on small screens instead of cramming into the bar.
--}}
<div class="mb-6 space-y-3">
    @if($backUrl && $backLabel)
        <a href="{{ $backUrl }}" wire:navigate
           class="inline-flex items-center gap-1.5 text-sm text-on-surface-variant hover:text-on-surface transition-colors">
            <span class="material-symbols-outlined text-lg" aria-hidden="true">arrow_back</span>
            {{ $backLabel }}
        </a>
    @endif

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div class="min-w-0">
            <h1 class="font-heading text-xl sm:text-2xl font-semibold text-on-surface tracking-tight leading-tight">
                {{ $title }}
            </h1>
            @if($subtitle)
                <p class="text-sm text-on-surface-variant mt-1 truncate">{{ $subtitle }}</p>
            @endif
        </div>

        @isset($actions)
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                {{ $actions }}
            </div>
        @endisset
    </div>
</div>

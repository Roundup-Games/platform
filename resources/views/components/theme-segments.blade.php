{{--
    Shared theme segmented control (Light / Dark / System).
    Used inside the account popover and the mobile drawer; every instance
    stays in sync because resources/js/site-header.js drives [data-theme-seg]
    via document-level delegation and re-syncs aria-pressed on load.
    Server-rendered aria-pressed is neutral ("false") — JS applies the
    stored preference on boot (progressive enhancement, matches how the
    pre-paint inline theme script already works).
--}}
<div class="inline-flex w-full items-center gap-0.5 rounded-full border border-outline-variant/35 bg-surface-container-high p-[3px]"
     role="group"
     aria-label="{{ __('common.content_appearance') }}">
    @foreach (['light' => 'light_mode', 'dark' => 'dark_mode', 'system' => 'routine'] as $mode => $icon)
        <button type="button"
                data-theme-seg="{{ $mode }}"
                aria-pressed="false"
                class="flex h-8 flex-1 items-center justify-center gap-1.5 rounded-full px-2.5 text-xs font-semibold text-on-surface-variant transition-colors hover:text-on-surface aria-pressed:bg-primary aria-pressed:text-on-primary">
            <span class="material-symbols-outlined text-[15px]" aria-hidden="true">{{ $icon }}</span>
            <span>{{ __('common.content_' . $mode) }}</span>
        </button>
    @endforeach
</div>

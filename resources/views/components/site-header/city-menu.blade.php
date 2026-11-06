{{--
    City hub popover — nav picker (no persisted preference; the pill labels
    the current city when the visitor is ON a hub page). Rows come from the
    site-header view composer (CityDirectoryService::qualifyingCities(),
    short-TTL cached — see AppServiceProvider).
--}}
{{-- $navCities arrives via the view composer registered for this view in
     AppServiceProvider (same pattern as layouts.app + $pwaEligible). --}}

@php
    $currentSlug = request()->routeIs('city-hubs.show') ? request()->route('slug') : null;
    $locale = app()->getLocale();
@endphp
<div id="city-menu"
     class="site-popover grid w-[min(300px,calc(100vw-2rem))] gap-0.5 overflow-y-auto p-2"
     popover="auto"
     aria-label="{{ __('city-hubs.label_city_hubs') }}">
    @if (isset($navCities) && count($navCities) > 0)
        <span class="px-3 pb-1 pt-2.5 text-[11px] font-bold uppercase tracking-[0.08em] text-primary">
            {{ __('city-hubs.label_city_hubs') }}
        </span>
        @foreach ($navCities as $city)
            @php($active = $currentSlug !== null && $currentSlug === $city['slug'])
            <a href="{{ route('city-hubs.show', ['locale' => $locale, 'slug' => $city['slug']]) }}"
               wire:navigate
               class="flex h-11 w-full items-center gap-2.5 rounded-xl px-3 text-start text-sm font-medium transition-colors hover:bg-surface-container-high hover:text-primary{{ $active ? ' text-primary' : ' text-on-surface' }}">
                <span class="flex-1 truncate">{{ $city['city'] }}</span>
                @if ($city['featured'])
                    <span class="rounded-full bg-secondary-container/60 px-2 py-0.5 text-[11px] font-semibold text-on-secondary-container">
                        {{ __('city-hubs.label_featured') }}
                    </span>
                @endif
                @if ($active)
                    <span class="material-symbols-outlined text-[18px] text-primary" aria-hidden="true">check</span>
                @endif
            </a>
        @endforeach
        <hr class="mx-1 my-1 border-t border-outline-variant/35">
    @endif
    <a href="{{ route('city-hubs.index', $locale) }}"
       wire:navigate
       class="flex h-11 w-full items-center gap-2.5 rounded-xl px-3 text-start text-sm font-medium text-on-surface-variant transition-colors hover:bg-surface-container-high hover:text-primary">
        <span class="material-symbols-outlined text-[20px] text-on-surface-variant" aria-hidden="true">location_city</span>
        {{ __('city-hubs.action_all_hubs') }}
        <span class="material-symbols-outlined ml-auto text-[18px]" aria-hidden="true">arrow_forward</span>
    </a>
</div>

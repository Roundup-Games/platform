<div x-data="{ showCityInput: false }">
    {{-- ── Header ────────────────────────────────────────────────── --}}
    <section class="bg-primary text-on-primary">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
            <h1 class="text-2xl sm:text-3xl font-heading font-bold tracking-tight">{{ __('venue.heading_directory') }}</h1>
            <p class="mt-1 text-sm text-on-primary/80">{{ __('venue.content_directory_subtitle') }}</p>

            {{-- Search --}}
            <div class="mt-4 relative max-w-xl">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-primary/60 text-lg" aria-hidden="true">search</span>
                <input type="text"
                       aria-label="{{ __('venue.placeholder_directory_search') }}"
                       wire:model.live.debounce.300ms="search"
                       placeholder="{{ __('venue.placeholder_directory_search') }}"
                       class="w-full pl-10 pr-4 py-2.5 bg-on-primary/10 border border-on-primary/20 rounded-full text-on-primary placeholder:text-on-primary/50 focus:bg-on-primary/20 focus:border-on-primary/40 focus:ring-2 focus:ring-on-primary/20" />
            </div>

            {{-- Location bar: the directory is proximity-aware, so location
                 controls live in the header next to search rather than in the
                 filter rail. Verified commercial venues disclose precise
                 distance safely (LocationDisclosureService), so "nearest" is a
                 first-class sort here. --}}
            <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                @if($hasLocation)
                    <span class="inline-flex items-center gap-1.5 text-on-primary/90">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">my_location</span>
                        {{ __('venue.content_directory_showing_near_you') }}
                    </span>
                    <button wire:click="locateMe" type="button"
                            class="text-on-primary/80 hover:text-on-primary underline-offset-2 hover:underline">
                        {{ __('location.action_change_location') }}
                    </button>
                    <button wire:click="clearLocation" type="button"
                            class="text-on-primary/80 hover:text-on-primary underline-offset-2 hover:underline">
                        {{ __('venue.action_directory_clear_location') }}
                    </button>
                @else
                    <button wire:click="locateMe" type="button"
                            class="inline-flex items-center gap-1.5 px-4 py-2 bg-on-primary/15 hover:bg-on-primary/25 border border-on-primary/20 rounded-full text-on-primary text-sm font-medium transition-colors">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">my_location</span>
                        {{ __('venue.action_directory_use_my_location') }}
                    </button>
                    <button x-on:click="showCityInput = !showCityInput" type="button"
                            class="text-on-primary/80 hover:text-on-primary underline-offset-2 hover:underline">
                        {{ __('location.field_or_enter_your_city') }}
                    </button>
                    {{-- Manual city search (geocoded → guest location) --}}
                    <div x-show="showCityInput" x-transition class="w-full max-w-sm mt-1">
                        <form wire:submit.prevent="searchCity" class="flex gap-2">
                            <input type="text"
                                   wire:model="cityQuery"
                                   placeholder="{{ __('location.field_enter_your_city') }}"
                                   aria-label="{{ __('location.field_city_name') }}"
                                   class="flex-1 px-4 py-2 rounded-full bg-on-primary/10 border border-on-primary/20 text-on-primary placeholder:text-on-primary/50 focus:bg-on-primary/20 focus:border-on-primary/40 text-sm" />
                            <button type="submit"
                                    class="inline-flex items-center justify-center px-4 py-2 bg-on-primary/20 hover:bg-on-primary/30 rounded-full text-on-primary transition-colors"
                                    aria-label="{{ __('discovery.action_search') }}">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">search</span>
                            </button>
                        </form>
                        @error('cityQuery')
                            <p class="text-xs text-error mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                @endif
            </div>
        </div>
    </section>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-6">
        {{-- ── Filter Section ─────────────────────────────────────── --}}
        <div class="space-y-4 mb-6">
            {{-- Venue type pills (commercial types only — they are all the
                 directory can ever list) --}}
            <div>
                <p class="text-xs font-medium text-on-surface-variant mb-2">{{ __('venue.field_directory_venue_type') }}</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach($venueTypes as $type)
                        <button wire:click="toggleVenueType('{{ $type->value }}')"
                                class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium transition-colors
                                    {{ $venue_type === $type->value ? 'bg-primary/15 text-primary ring-1 ring-primary/30' : 'bg-surface-container-high text-on-surface-variant hover:bg-surface-container' }}">
                            {{ $type->label() }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Sort + rating + toggles + clear --}}
            <div class="flex flex-wrap items-center gap-x-5 gap-y-3">
                <div class="flex items-center gap-2">
                    <label for="venue-sort" class="text-sm font-medium text-on-surface-variant whitespace-nowrap">{{ __('venue.field_directory_sort') }}</label>
                    <select id="venue-sort"
                            wire:model.live="sortBy"
                            class="bg-surface-container-high text-on-surface text-sm rounded-lg px-3 py-1.5 border border-outline-variant/30 focus:ring-2 focus:ring-primary/20 focus:border-primary">
                        <option value="nearest">{{ __('venue.sort_directory_nearest') }}</option>
                        <option value="active">{{ __('venue.sort_directory_active') }}</option>
                        <option value="rating">{{ __('venue.sort_directory_rating') }}</option>
                        <option value="newest">{{ __('venue.sort_directory_newest') }}</option>
                    </select>
                </div>

                <div class="flex items-center gap-2">
                    <label for="venue-min-rating" class="text-sm font-medium text-on-surface-variant whitespace-nowrap">{{ __('venue.field_directory_min_rating') }}</label>
                    <select id="venue-min-rating"
                            wire:model.live="min_rating"
                            class="bg-surface-container-high text-on-surface text-sm rounded-lg px-3 py-1.5 border border-outline-variant/30 focus:ring-2 focus:ring-primary/20 focus:border-primary">
                        <option value="">{{ __('venue.label_directory_any_rating') }}</option>
                        @for($i = 1; $i <= 5; $i++)
                            <option value="{{ $i }}">{{ $i }}+ ★</option>
                        @endfor
                    </select>
                </div>

                <label class="inline-flex items-center gap-1.5 text-sm text-on-surface-variant cursor-pointer select-none">
                    <input type="checkbox" wire:model.live="has_upcoming" class="rounded border-outline-variant text-primary focus:ring-primary/30" />
                    {{ __('venue.filter_directory_has_upcoming') }}
                </label>

                <label class="inline-flex items-center gap-1.5 text-sm text-on-surface-variant cursor-pointer select-none">
                    <input type="checkbox" wire:model.live="managed_only" class="rounded border-outline-variant text-primary focus:ring-primary/30" />
                    {{ __('venue.filter_directory_managed') }}
                </label>

                @if($this->hasActiveFilters())
                    <button wire:click="clearFilters" class="text-sm text-primary font-medium hover:underline whitespace-nowrap">
                        {{ __('venue.action_directory_clear_filters') }}
                    </button>
                @endif
            </div>

            {{-- Soft note when "nearest" was selected but no location is shared,
                 so the degraded sort is legible rather than surprising. --}}
            @if($sortBy === 'nearest' && ! $hasLocation)
                <p class="text-xs text-on-surface-variant/80">
                    <span class="material-symbols-outlined text-sm align-middle mr-1" aria-hidden="true">info</span>
                    {{ __('venue.hint_directory_nearest_needs_location') }}
                </p>
            @endif
        </div>

        {{-- ── Venue Cards Grid ──────────────────────────────────── --}}
        @if($results->count())
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                @foreach($results as $venue)
                    @php
                        // Type-tinted avatar so cards are visually distinguishable
                        // without per-type glyphs (some venue icons are absent from
                        // the self-hosted Material Symbols subset — see venue-detail).
                        $avatarTint = match ($venue->venue_type) {
                            App\Enums\VenueType::Cafe => 'bg-amber-100 text-amber-700',
                            App\Enums\VenueType::Flgs => 'bg-primary-container text-on-primary-container',
                            App\Enums\VenueType::Library => 'bg-tertiary-container text-on-tertiary-container',
                            App\Enums\VenueType::Bar => 'bg-rose-100 text-rose-700',
                            default => 'bg-secondary-container text-on-secondary-container',
                        };
                    @endphp
                    {{-- Non-anchor card container: detail navigation uses the stretched-link
                         pattern (the venue-name <a> carries an ::after overlay covering the
                         whole card) so the website affordance can be a real, independently
                         clickable anchor instead of an invalid nested <a> inside the card. --}}
                    <article class="relative isolate bg-surface-container rounded-2xl border border-outline-variant/15 hover:border-primary/40 hover:shadow-lg transition-all duration-200 overflow-hidden group">
                        <div class="p-5">
                            {{-- Avatar + name + verified --}}
                            <div class="flex items-center gap-3 mb-3">
                                <span class="inline-flex items-center justify-center w-11 h-11 rounded-full shrink-0 {{ $avatarTint }}">
                                    <span class="material-symbols-outlined text-xl" aria-hidden="true">store</span>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <h3 class="font-heading font-semibold text-on-surface group-hover:text-primary transition-colors truncate flex items-center gap-1">
                                        <a href="{{ route('venues.detail', ['locale' => app()->getLocale(), 'slug' => $venue->slug]) }}"
                                           wire:navigate
                                           class="truncate after:absolute after:inset-0 after:content-['']"
                                           aria-label="{{ __('venue.action_view_venue', ['name' => $venue->name]) }}">
                                            <span class="truncate">{{ $venue->name }}</span>
                                        </a>
                                        @if($venue->is_verified)
                                            <span class="material-symbols-outlined text-base text-primary shrink-0" aria-hidden="true" title="{{ __('venue.label_directory_verified') }}">verified</span>
                                        @endif
                                    </h3>
                                    @if($venue->venue_type)
                                        <span class="inline-flex items-center gap-1 text-xs text-on-surface-variant">
                                            <span class="material-symbols-outlined text-sm" aria-hidden="true">store</span>
                                            {{ $venue->venue_type->label() }}
                                        </span>
                                    @endif
                                </div>
                            </div>

                            {{-- Address — disclosure-routed via <x-location-display>
                                 (the sole address surface), exactly like the venue
                                 detail page. Verified commercial → exact; managed-
                                 unverified → area rung. No raw address read. --}}
                            <div class="text-sm text-on-surface-variant mb-2">
                                <x-location-display :location="$venue" :without-icon="true" icon-class="text-sm" />
                            </div>

                            {{-- Distance chip — only when a guest location is set.
                                 Renders through <x-distance-display> (disclosure
                                 authority): precise for verified commercial,
                                 grid-snapped otherwise. --}}
                            @if(isset($venue->distance_km))
                                <div class="mb-2">
                                    <x-distance-display
                                        :precise-km="(float) $venue->distance_km"
                                        :location="$venue"
                                        :precise="true"
                                        icon="straighten" />
                                </div>
                            @endif

                            {{-- Description excerpt --}}
                            @if($venue->description)
                                <p class="text-sm text-on-surface-variant line-clamp-2 mb-3">{{ Str::limit(strip_tags((string) $venue->description), 130) }}</p>
                            @endif

                            {{-- Meta row: activity signal + rating --}}
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                @if($venue->upcoming_sessions_count > 0)
                                    <span class="inline-flex items-center gap-1 text-tertiary font-medium">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">event_upcoming</span>
                                        {{ trans_choice('venue.content_directory_upcoming_sessions', (int) $venue->upcoming_sessions_count) }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 text-on-surface-variant/70">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">schedule</span>
                                        {{ __('venue.content_directory_no_upcoming') }}
                                    </span>
                                @endif

                                @if($venue->average_rating)
                                    <span class="inline-flex items-center gap-0.5">
                                        <span class="text-amber-500 font-semibold">{{ number_format((float) $venue->average_rating, 1) }}</span>
                                        <span class="text-amber-500">★</span>
                                        <span class="text-on-surface-variant">({{ $venue->review_count }})</span>
                                    </span>
                                @endif

                                @if($venue->managed_by)
                                    <span class="inline-flex items-center gap-0.5 text-primary">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">manage_accounts</span>
                                        {{ __('venue.label_directory_managed_badge') }}
                                    </span>
                                @endif

                                @if($safeUrl = safe_url($venue->website_url))
                                    <a href="{{ $safeUrl }}"
                                       target="_blank"
                                       rel="noopener"
                                       class="relative z-10 inline-flex items-center text-on-surface-variant hover:text-primary transition-colors"
                                       title="{{ __('venue.action_visit_website') }}"
                                       aria-label="{{ __('venue.action_visit_website') }}">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">open_in_new</span>
                                    </a>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>

            {{-- ── Load More ─────────────────────────────────────── --}}
            @if($results->hasMorePages())
                <div class="mt-8 text-center">
                    <button wire:click="loadMore"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 px-6 py-3 bg-surface-container-high text-on-surface text-sm font-medium rounded-xl shadow-ambient hover:bg-surface-container transition-colors">
                        <span wire:loading.remove wire:target="loadMore">
                            <span class="material-symbols-outlined text-base" aria-hidden="true">expand_more</span>
                        </span>
                        <span wire:loading wire:target="loadMore">
                            <span class="material-symbols-outlined text-base animate-spin" aria-hidden="true">progress_activity</span>
                        </span>
                        {{ __('venue.action_directory_load_more') }}
                    </button>
                    <p class="mt-2 text-xs text-on-surface-variant">
                        {{ __('venue.content_directory_showing_of', [
                            'shown' => $results->count(),
                            'total' => $results->total(),
                        ]) }}
                    </p>
                </div>
            @endif
        @else
            {{-- ── Empty / thin-catalog state ────────────────────── --}}
            {{-- A thin catalog is normal for a young platform; the empty state
                 turns absence into supply growth via the propose/claim loop. --}}
            <div class="text-center py-16">
                <span class="material-symbols-outlined text-5xl text-on-surface-variant/40 mb-3 block" aria-hidden="true">storefront</span>
                <h2 class="text-lg font-heading font-semibold text-on-surface-variant">{{ __('venue.empty_directory_title') }}</h2>
                <p class="text-sm text-on-surface-variant/70 mt-1 max-w-md mx-auto">{{ __('venue.empty_directory_body') }}</p>
                <div class="mt-5 flex flex-wrap justify-center gap-3">
                    @auth
                        <a href="{{ route('venues.propose', ['locale' => app()->getLocale()]) }}" wire:navigate
                           class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-primary text-on-primary rounded-xl text-sm font-medium hover:shadow-md transition-shadow">
                            <span class="material-symbols-outlined text-base" aria-hidden="true">add_location_alt</span>
                            {{ __('venue.action_directory_cta_propose') }}
                        </a>
                    @else
                        <a href="{{ route('register', ['locale' => app()->getLocale()]) }}" wire:navigate
                           class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-primary text-on-primary rounded-xl text-sm font-medium hover:shadow-md transition-shadow">
                            <span class="material-symbols-outlined text-base" aria-hidden="true">person_add</span>
                            {{ __('venue.action_directory_cta_sign_up_propose') }}
                        </a>
                    @endauth
                    @if($this->hasActiveFilters())
                        <button wire:click="clearFilters"
                                class="px-5 py-2.5 bg-surface-container-high text-on-surface rounded-xl text-sm font-medium hover:bg-surface-container transition-colors">
                            {{ __('venue.action_directory_clear_filters') }}
                        </button>
                    @endif
                </div>
            </div>
        @endif

        {{-- ── Page footer CTAs (stewardship loop) ───────────────── --}}
        @if($results->total() > 0)
            <div class="mt-12 rounded-2xl bg-surface-container-low border border-outline-variant/15 p-6 flex flex-col sm:flex-row items-center justify-between gap-4">
                <div>
                    <h3 class="font-heading font-semibold text-on-surface">{{ __('venue.heading_directory_footer_cta') }}</h3>
                    <p class="text-sm text-on-surface-variant">{{ __('venue.content_directory_footer_cta') }}</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    @auth
                        <a href="{{ route('venues.propose', ['locale' => app()->getLocale()]) }}" wire:navigate
                           class="inline-flex items-center gap-1.5 px-4 py-2 bg-primary text-on-primary rounded-xl text-sm font-medium hover:shadow-md transition-shadow">
                            <span class="material-symbols-outlined text-base" aria-hidden="true">add_location_alt</span>
                            {{ __('venue.action_directory_cta_propose') }}
                        </a>
                    @endauth
                    <a href="{{ route('discover', ['locale' => app()->getLocale()]) }}" wire:navigate
                       class="inline-flex items-center gap-1.5 px-4 py-2 bg-surface-container-high text-on-surface rounded-xl text-sm font-medium hover:bg-surface-container transition-colors">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">explore</span>
                        {{ __('discovery.action_discover') }}
                    </a>
                </div>
            </div>
        @endif
    </div>
</div>

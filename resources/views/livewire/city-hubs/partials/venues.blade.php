{{-- ── Verified venues section (M062 T03) ───────────────────────────────────
     Verified commercial (or admin-managed commercial) venues of the city
     cluster, sourced from Location::scopePublicVenuePage() + slug — the
     same eligibility as the guard count. Each card links to the venue's
     public page via <x-venue-link> (the single link affordance, which
     re-checks LocationDisclosureService::isPublicVenuePage()); addresses
     render through <x-location-display>, the sole address-disclosure
     surface, so no raw address attribute is ever read here. The footer
     links to the venue directory filtered by the city name (its q filter
     matches the city column) for the full list. --}}
<section class="bg-surface-container-low rounded-xl shadow-ambient p-6" aria-labelledby="city-hub-venues-heading">
    <div class="flex items-start justify-between gap-4 flex-wrap mb-4">
        <div>
            <h2 id="city-hub-venues-heading" class="text-xl font-heading font-bold tracking-tight text-on-surface flex items-center gap-2">
                <span class="material-symbols-outlined text-xl" aria-hidden="true">storefront</span>
                {{ __('city-hubs.section_venues') }}
            </h2>

            <p class="mt-1 text-sm text-on-surface-variant">
                {{ __('city-hubs.label_verified_venues_count', ['count' => $city->verifiedVenuesCount]) }}
            </p>
        </div>
    </div>

    @if($venues->isNotEmpty())
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($venues as $venue)
                @php
                    // Type-tinted avatar — same mapping as the venue
                    // directory so the two surfaces stay visually coherent.
                    $avatarTint = match ($venue->venue_type) {
                        App\Enums\VenueType::Cafe => 'bg-amber-100 text-amber-700',
                        App\Enums\VenueType::Flgs => 'bg-primary-container text-on-primary-container',
                        App\Enums\VenueType::Library => 'bg-tertiary-container text-on-tertiary-container',
                        App\Enums\VenueType::Bar => 'bg-rose-100 text-rose-700',
                        default => 'bg-secondary-container text-on-secondary-container',
                    };
                @endphp
                {{-- Non-anchor card container: the venue-name link from
                     <x-venue-link> carries the stretched-link overlay, the
                     same pattern as the venue directory card. --}}
                <article class="relative isolate bg-surface-container rounded-xl border border-outline-variant/15 hover:border-primary/40 hover:shadow-lg transition-all duration-200 p-4">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex items-center justify-center w-10 h-10 rounded-full shrink-0 {{ $avatarTint }}">
                            <span class="material-symbols-outlined text-lg" aria-hidden="true">store</span>
                        </span>
                        <div class="min-w-0 flex-1">
                            <h3 class="font-heading font-semibold text-on-surface truncate flex items-center gap-1">
                                <x-venue-link :location="$venue" class="truncate hover:text-primary transition-colors after:absolute after:inset-0 after:content-['']" />
                                @if($venue->is_verified)
                                    <span class="material-symbols-outlined text-base text-primary shrink-0" aria-hidden="true" title="{{ __('venues.label_directory_verified') }}">verified</span>
                                @endif
                            </h3>
                            @if($venue->venue_type)
                                <span class="text-xs text-on-surface-variant">{{ $venue->venue_type->label() }}</span>
                            @endif
                        </div>
                    </div>

                    {{-- Address — disclosure-routed via <x-location-display>
                         (verified commercial → exact; managed-unverified →
                         graduated). No raw address read. --}}
                    <div class="mt-3 text-sm text-on-surface-variant">
                        <x-location-display :location="$venue" :without-icon="true" icon-class="text-sm" />
                    </div>
                </article>
            @endforeach
        </div>
    @else
        {{-- Venue-qualified clusters always list venues, but a
             sessions-qualified city may have none: translated empty state
             with an onward link, never a dead panel. --}}
        <div class="rounded-xl border border-dashed border-outline-variant/50 p-8 text-center">
            <span class="material-symbols-outlined text-3xl text-on-surface-variant/70" aria-hidden="true">storefront</span>
            <p class="mt-2 text-on-surface-variant">
                {{ __('city-hubs.empty_venues', ['city' => $city->city]) }}
            </p>
            <a href="{{ route('venues.directory', app()->getLocale()) }}" wire:navigate
               class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline">
                {{ __('city-hubs.empty_venues_cta') }}
                <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_forward</span>
            </a>
        </div>
    @endif

    {{-- Full list: the venue directory's q filter matches the city column,
         so this is a genuinely city-filtered venue list. --}}
    <div class="mt-4 text-sm">
        <a href="{{ route('venues.directory', ['locale' => app()->getLocale(), 'q' => $city->city]) }}" wire:navigate
           class="inline-flex items-center gap-1 font-medium text-primary hover:underline">
            {{ __('city-hubs.action_view_all_venues', ['city' => $city->city]) }}
            <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_forward</span>
        </a>
    </div>
</section>

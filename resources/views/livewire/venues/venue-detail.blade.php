<div>
    {{-- Back link --}}
    <div class="bg-surface-container-low border-b border-outline-variant">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-3">
            <a href="{{ route('discover') }}" class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-on-surface transition-colors">
                <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span>
                {{ __('venue.action_back_to_discover') }}
            </a>
        </div>
    </div>

    {{-- ── Hero / Header ────────────────────────────────────── --}}
    {{-- M053/S02: this page only renders for verified commercial venues.
         VenueDetail::mount() 404s everything else via
         LocationDisclosureService::isPublicVenuePage(), the single authority. --}}
    <section class="bg-surface-container-low">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
            <h1 class="text-3xl sm:text-4xl lg:text-5xl font-heading font-bold tracking-tight leading-tight text-on-surface">
                {{ $location->name }}
            </h1>

            @if($location->venue_type)
                <p class="mt-2 text-sm text-on-surface-variant">
                    {{ __('venue.label_venue_type') }}: {{ $location->venue_type->label() }}
                </p>
            @endif

            {{-- Address — S01 integration contract: routed through <x-location-display>
                 (the sole address-rendering authority). The venue-direct path
                 resolves via strangerPreviewLevel() → Exact/fullAddress for the
                 verified commercial venues that reach this page; any anomaly
                 renders nothing (fail-closed). No raw fullAddress() call. --}}
            <p class="mt-3 text-on-surface-variant">
                <x-location-display :location="$location" />
            </p>

            <div class="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-sm text-on-surface-variant">
                @if($location->website_url)
                    <a href="{{ $location->website_url }}" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-1.5 text-primary hover:underline">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">link</span>
                        {{ __('venue.action_visit_website') }}
                    </a>
                @endif

                {{-- Managed-by link (only when a manager is set) --}}
                @if($location->managed_by && $location->managedBy)
                    <span class="inline-flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">manage_accounts</span>
                        <span>{{ __('venue.label_managed_by') }}:</span>
                        <a href="{{ route('profile.public', ['locale' => app()->getLocale(), 'user' => $location->managedBy]) }}"
                           wire:navigate
                           class="text-primary hover:underline">
                            {{ $location->managedBy->name }}
                        </a>
                    </span>
                @endif

                {{-- Claim-this-venue affordance (M053/S04/T04): only for
                     authenticated visitors when the venue has no manager yet.
                     Managed venues show the manager instead (block above). --}}
                @if(auth()->check() && $location->managed_by === null)
                    <a href="{{ route('venues.claim', ['locale' => app()->getLocale(), 'slug' => $location->slug]) }}"
                       wire:navigate
                       class="inline-flex items-center gap-1.5 text-primary hover:underline">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">verified_user</span>
                        {{ __('venue.action_claim_venue') }}
                    </a>
                @endif
            </div>
        </div>
    </section>

    {{-- ── Description ──────────────────────────────────────── --}}
    @if($location->description)
        <section class="bg-surface-container-low border-t border-outline-variant">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 py-6 sm:py-8">
                <div class="max-w-3xl text-base text-on-surface leading-relaxed">
                    {!! nl2br(e($location->description)) !!}
                </div>
            </div>
        </section>
    @endif

    {{-- ── Activity ─────────────────────────────────────────── --}}
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8 bg-surface space-y-6">

        {{-- Upcoming sessions --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-xl" aria-hidden="true">event_upcoming</span>
                {{ __('venue.heading_upcoming_sessions') }}
            </h2>
            @if($upcomingSessions->count())
                <div class="divide-y divide-outline-variant/30">
                    @foreach($upcomingSessions as $session)
                        <div class="flex items-center justify-between py-3 first:pt-0 last:pb-0">
                            <div>
                                <a href="{{ route('games.detail', ['locale' => app()->getLocale(), 'id' => $session->id]) }}"
                                   wire:navigate
                                   class="text-sm font-medium text-on-surface hover:text-primary transition-colors">
                                    {{ $session->name }}
                                </a>
                                <p class="text-xs text-on-surface-variant">{{ format_date($session->date_time, 'datetime') }}</p>
                            </div>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-tertiary/10 text-tertiary">
                                {{ $session->status->label() }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-on-surface-variant italic py-4 text-center">{{ __('venue.content_no_upcoming_sessions') }}</p>
            @endif
        </section>

        {{-- Past sessions --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-xl" aria-hidden="true">history</span>
                {{ __('venue.heading_past_sessions') }}
            </h2>
            @if($pastSessions->count())
                <div class="divide-y divide-outline-variant/30">
                    @foreach($pastSessions as $session)
                        <div class="flex items-center justify-between py-3 first:pt-0 last:pb-0">
                            <div>
                                <a href="{{ route('games.detail', ['locale' => app()->getLocale(), 'id' => $session->id]) }}"
                                   wire:navigate
                                   class="text-sm font-medium text-on-surface hover:text-primary transition-colors">
                                    {{ $session->name }}
                                </a>
                                <p class="text-xs text-on-surface-variant">{{ format_date($session->date_time, 'datetime') }}</p>
                            </div>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                                {{ $session->status->label() }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-on-surface-variant italic py-4 text-center">{{ __('venue.content_no_past_sessions') }}</p>
            @endif
        </section>

        {{-- Active campaigns --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-xl" aria-hidden="true">repeat</span>
                {{ __('venue.heading_active_campaigns') }}
            </h2>
            @if($activeCampaigns->count())
                <div class="divide-y divide-outline-variant/30">
                    @foreach($activeCampaigns as $campaign)
                        <div class="py-3 first:pt-0 last:pb-0">
                            <a href="{{ route('campaigns.detail', ['locale' => app()->getLocale(), 'id' => $campaign->id]) }}"
                               wire:navigate
                               class="text-sm font-medium text-on-surface hover:text-primary transition-colors">
                                {{ $campaign->name }}
                            </a>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-on-surface-variant italic py-4 text-center">{{ __('venue.content_no_active_campaigns') }}</p>
            @endif
        </section>

        {{-- Completed campaigns --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-xl" aria-hidden="true">trophy</span>
                {{ __('venue.heading_completed_campaigns') }}
            </h2>
            @if($completedCampaigns->count())
                <div class="divide-y divide-outline-variant/30">
                    @foreach($completedCampaigns as $campaign)
                        <div class="py-3 first:pt-0 last:pb-0">
                            <a href="{{ route('campaigns.detail', ['locale' => app()->getLocale(), 'id' => $campaign->id]) }}"
                               wire:navigate
                               class="text-sm font-medium text-on-surface hover:text-primary transition-colors">
                                {{ $campaign->name }}
                            </a>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-on-surface-variant italic py-4 text-center">{{ __('venue.content_no_completed_campaigns') }}</p>
            @endif
        </section>

        {{-- ── Reviews (S03: venue reviews mount here) ──── --}}
        {{-- M053/S03/T04: the stub is replaced with the real venue-reviews
             component — aggregate + list + attended-only write affordance.
             The section heading (venue.reviews_heading) is the page's own;
             everything inside the component is self-contained. --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-xl" aria-hidden="true">rate_review</span>
                {{ __('venue.reviews_heading') }}
            </h2>
            <livewire:reviews.venue-reviews :location="$location" />
        </section>
    </div>
</div>

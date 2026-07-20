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

    {{-- ── Hero / Identity card ─────────────────────────────────────────── --}}
    {{-- M053/S02: this page only renders for verified commercial venues.
         VenueDetail::mount() 404s everything else via
         LocationDisclosureService::isPublicVenuePage(), the single authority. --}}
    <section class="bg-surface-container-low">
        {{-- Brand accent strip (ties venue pages into the brand identity) --}}
        <div class="h-1.5 bg-gradient-to-r from-primary/80 via-tertiary to-primary/80"></div>

        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
            {{-- Venue-type chip — identity marker. A single in-subset icon (store)
                 keeps the glyph consistent; the label text carries the type
                 semantics (local_bar / local_library are not in the self-hosted
                 Material Symbols subset, so per-type icons would render blank). --}}
            @if($location->venue_type)
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-secondary-container/60 text-on-secondary-container">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">store</span>
                    {{ $location->venue_type->label() }}
                </span>
            @endif

            <h1 class="mt-3 text-3xl sm:text-4xl lg:text-5xl font-heading font-bold tracking-tight leading-tight text-on-surface">
                {{ $location->name }}
            </h1>

            {{-- Address — S01 integration contract: routed through
                 <x-location-display> (the sole address-rendering authority).
                 The venue-direct path resolves via strangerPreviewLevel() →
                 Exact/fullAddress for the verified commercial venues that reach
                 this page; any anomaly renders nothing (fail-closed). No raw
                 fullAddress() call. --}}
            <p class="mt-3 text-on-surface-variant">
                <x-location-display :location="$location" />
            </p>

            {{-- Primary CTA + attribution row --}}
            <div class="mt-5 flex flex-wrap items-center gap-x-5 gap-y-3">
                @if($safeUrl = safe_url($location->website_url))
                    <a href="{{ $safeUrl }}" target="_blank" rel="noopener"
                       class="btn-brand gap-1.5">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">open_in_new</span>
                        {{ __('venue.action_visit_website') }}
                    </a>
                @endif

                {{-- Managed-by (only when a manager is set) --}}
                @if($location->managed_by && $location->managedBy)
                    <span class="inline-flex items-center gap-1.5 text-sm text-on-surface-variant">
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
                       class="inline-flex items-center gap-1.5 text-sm text-primary hover:underline">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">verified_user</span>
                        {{ __('venue.action_claim_venue') }}
                    </a>
                @endif
            </div>

            {{-- Description — part of the identity block (pre-line preserves
                 the editor's paragraph breaks without raw HTML). --}}
            @if($location->description)
                <div class="mt-6 max-w-3xl text-base text-on-surface leading-relaxed">
                    <p class="whitespace-pre-line">{{ $location->description }}</p>
                </div>
            @endif
        </div>
    </section>

    {{-- ── Activity ─────────────────────────────────────────────────────── --}}
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8 bg-surface space-y-6">

        @php
            // Hide empty sections: a venue with no activity at all gets a single
            // graceful fallback instead of a wall of "No X" boxes.
            $hasAnyActivity = $upcomingSessions->isNotEmpty()
                || $pastSessions->isNotEmpty()
                || $activeCampaigns->isNotEmpty()
                || $completedCampaigns->isNotEmpty();
        @endphp

        {{-- ── Operational Parameters (M056/S05) ──────────────────────────────}}
        {{-- Curated by Platform Admin on the venue manager's behalf
             (LocationResource → venue_metadata). Shown only when at least one
             of the three whitelisted fields is non-empty, and renders ONLY
             those keys — never the rest of the venue_metadata envelope
             (proposed_by_user_id, geocoded_display_name, approved_from_ticket
             are internal). Mirrors the hasAnyActivity hide-when-empty
             convention so a venue with no curated params shows nothing. --}}
        @php
            $operationalParams = [
                'overlap_guidance' => __('venue.label_overlap_guidance'),
                'fee_display' => __('venue.label_fee_display'),
                'house_rules' => __('venue.label_house_rules'),
            ];
            $hasOperationalParams = collect($operationalParams)
                ->keys()
                ->contains(fn (string $key): bool => is_string($location->venue_metadata[$key] ?? null)
                    && trim($location->venue_metadata[$key]) !== '');
        @endphp

        @if($hasOperationalParams)
            <section class="bg-surface-container-low rounded-xl shadow-ambient p-6" aria-labelledby="operational-parameters-heading">
                <h2 id="operational-parameters-heading" class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">tune</span>
                    {{ __('venue.heading_operational_parameters') }}
                </h2>
                <dl class="space-y-4">
                    @foreach($operationalParams as $paramKey => $paramLabel)
                        @php
                            $paramValue = $location->venue_metadata[$paramKey] ?? null;
                            $hasValue = is_string($paramValue) && trim($paramValue) !== '';
                        @endphp
                        @if($hasValue)
                            <div class="space-y-1">
                                <dt class="text-sm font-semibold text-on-surface">{{ $paramLabel }}</dt>
                                <dd class="text-sm text-on-surface-variant whitespace-pre-line">{{ $paramValue }}</dd>
                            </div>
                        @endif
                    @endforeach
                </dl>
            </section>
        @endif

        @if(! $hasAnyActivity)
            <div class="text-center py-16">
                <span class="material-symbols-outlined text-5xl text-on-surface-variant/40" aria-hidden="true">event_busy</span>
                <p class="mt-4 text-on-surface-variant">{{ __('venue.content_no_activity_yet') }}</p>
            </div>
        @else
            {{-- Upcoming sessions --}}
            @if($upcomingSessions->count())
                <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                    <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined text-xl" aria-hidden="true">event_upcoming</span>
                        {{ __('venue.heading_upcoming_sessions') }}
                    </h2>
                    <div class="divide-y divide-outline-variant/30">
                        @foreach($upcomingSessions as $session)
                            <div class="flex items-center justify-between gap-4 py-3 first:pt-0 last:pb-0">
                                <div class="min-w-0">
                                    <a href="{{ route('games.detail', ['locale' => app()->getLocale(), 'id' => $session]) }}"
                                       wire:navigate
                                       class="text-sm font-medium text-on-surface hover:text-primary transition-colors">
                                        {{ $session->name }}
                                    </a>
                                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-on-surface-variant">
                                        @if($session->gameSystem?->name)
                                            <span class="inline-flex items-center gap-1">
                                                <span class="material-symbols-outlined text-sm" aria-hidden="true">menu_book</span>
                                                {{ $session->gameSystem->name }}
                                            </span>
                                        @endif
                                        <span class="inline-flex items-center gap-1">
                                            <span class="material-symbols-outlined text-sm" aria-hidden="true">event</span>
                                            {{ format_date($session->date_time, 'datetime') }}
                                        </span>
                                        <span class="text-on-surface-variant/60">{{ $session->date_time->diffForHumans() }}</span>
                                    </div>
                                </div>
                                <span class="inline-flex shrink-0 items-center px-2 py-0.5 rounded-full text-xs font-medium bg-tertiary/10 text-tertiary">
                                    {{ $session->status->label() }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- Past sessions --}}
            @if($pastSessions->count())
                <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                    <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined text-xl" aria-hidden="true">history</span>
                        {{ __('venue.heading_past_sessions') }}
                    </h2>
                    <div class="divide-y divide-outline-variant/30">
                        @foreach($pastSessions as $session)
                            <div class="flex items-center justify-between gap-4 py-3 first:pt-0 last:pb-0">
                                <div class="min-w-0">
                                    <a href="{{ route('games.detail', ['locale' => app()->getLocale(), 'id' => $session]) }}"
                                       wire:navigate
                                       class="text-sm font-medium text-on-surface hover:text-primary transition-colors">
                                        {{ $session->name }}
                                    </a>
                                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-on-surface-variant">
                                        @if($session->gameSystem?->name)
                                            <span class="inline-flex items-center gap-1">
                                                <span class="material-symbols-outlined text-sm" aria-hidden="true">menu_book</span>
                                                {{ $session->gameSystem->name }}
                                            </span>
                                        @endif
                                        <span class="inline-flex items-center gap-1">
                                            <span class="material-symbols-outlined text-sm" aria-hidden="true">event</span>
                                            {{ format_date($session->date_time, 'datetime') }}
                                        </span>
                                        <span class="text-on-surface-variant/60">{{ $session->date_time->diffForHumans() }}</span>
                                    </div>
                                </div>
                                <span class="inline-flex shrink-0 items-center px-2 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                                    {{ $session->status->label() }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- Active campaigns --}}
            @if($activeCampaigns->count())
                <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                    <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined text-xl" aria-hidden="true">repeat</span>
                        {{ __('venue.heading_active_campaigns') }}
                    </h2>
                    <div class="divide-y divide-outline-variant/30">
                        @foreach($activeCampaigns as $campaign)
                            <div class="py-3 first:pt-0 last:pb-0">
                                <a href="{{ route('campaigns.detail', ['locale' => app()->getLocale(), 'id' => $campaign]) }}"
                                   wire:navigate
                                   class="text-sm font-medium text-on-surface hover:text-primary transition-colors">
                                    {{ $campaign->name }}
                                </a>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- Completed campaigns --}}
            @if($completedCampaigns->count())
                <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                    <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined text-xl" aria-hidden="true">trophy</span>
                        {{ __('venue.heading_completed_campaigns') }}
                    </h2>
                    <div class="divide-y divide-outline-variant/30">
                        @foreach($completedCampaigns as $campaign)
                            <div class="py-3 first:pt-0 last:pb-0">
                                <a href="{{ route('campaigns.detail', ['locale' => app()->getLocale(), 'id' => $campaign]) }}"
                                   wire:navigate
                                   class="text-sm font-medium text-on-surface hover:text-primary transition-colors">
                                    {{ $campaign->name }}
                                </a>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
        @endif

        {{-- ── Reviews (S03: venue reviews mount here) ─────────────────────── --}}
        {{-- Always rendered: the section carries its own write affordance + empty
             state, and hiding it would remove the (attended-only) ability to leave
             a review. Everything inside is self-contained. --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-xl" aria-hidden="true">rate_review</span>
                {{ __('venue.heading_reviews') }}
            </h2>
            <livewire:reviews.venue-reviews :location="$location" />
        </section>
    </div>
</div>

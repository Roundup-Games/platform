{{-- Hero section: cover image, badges, title, metadata --}}
@php
    // Cover image via the deterministic fallback chain (S07): host-uploaded
    // cover -> representative GameSystem cover -> og-default.jpg asset.
    // Eager-loaded gameSystems keeps the representative rung N+1-safe.
    $coverUrl = $game->resolveCoverUrl();
@endphp
@if($coverUrl)
    @push('preload')
        <link rel="preload" as="image" href="{{ $coverUrl }}" fetchpriority="high">
    @endpush
@endif
<section class="relative bg-primary text-on-primary overflow-hidden min-h-[200px] sm:min-h-[260px]">
    @if($coverUrl)
        <div class="absolute inset-0">
            <img src="{{ $coverUrl }}" alt="" class="w-full h-full object-cover opacity-95 blur-xs scale-105" aria-hidden="true" fetchpriority="high">
        </div>
        <div class="absolute inset-0 bg-linear-to-b from-primary/85 via-primary/95 to-primary"></div>
    @endif

    <div class="relative max-w-5xl mx-auto px-4 sm:px-6 py-10 sm:py-14 lg:py-16">
        {{-- Badges --}}
        <div class="flex flex-wrap items-center gap-2 mb-4">
            @if($isOwner)
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-on-primary/20 text-on-primary">{{ __('common.content_owner') }}</span>
            @endif
            @if($game->game_type)
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-on-primary/20 text-on-primary">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">{{ $game->game_type->value === 'board_game' ? 'casino' : 'auto_stories' }}</span>
                    {{ __('games.type_' . $game->game_type->value) }}
                </span>
            @endif
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                {{ $game->visibility->value === 'public' ? 'bg-on-primary/20 text-on-primary' : ($game->visibility->value === 'protected' ? 'bg-on-primary/30 text-on-primary' : 'bg-on-primary/10 text-on-primary') }}">
                {{ $game->visibility->label() }}
            </span>
        </div>

        {{-- Campaign link --}}
        @if($game->campaign)
            <a href="{{ route('campaigns.detail', ['locale' => app()->getLocale(), 'id' => $game->campaign->id]) }}" wire:navigate class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-on-primary/20 text-on-primary hover:bg-on-primary/30 transition-colors mb-3">
                <span class="material-symbols-outlined text-sm" aria-hidden="true">campaign</span>
                {{ __('games.content_part_of_campaign_name', ['name' => $game->campaign?->name]) }}
            </a>
        @endif

        <h1 class="text-3xl sm:text-4xl lg:text-5xl font-heading font-bold tracking-tight leading-tight">{{ $game->name }}</h1>

        {{-- Date / time / price / location --}}
        <div class="mt-6 flex flex-wrap gap-x-6 gap-y-2 text-sm text-on-primary/80">
            <span class="flex items-center gap-2">
                <span class="material-symbols-outlined text-lg" aria-hidden="true">calendar_today</span>
                {{ format_date($game->date_time, 'datetime') }}
            </span>
            @if($game->expected_duration)
                <span class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">schedule</span>
                    {{ $game->expected_duration }}h
                </span>
            @endif
            @if($game->price > 0)
                <span class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">payments</span>
                    {{ format_currency($game->price, false) }}
                </span>
            @else
                <span class="flex items-center gap-2 text-secondary">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">check_circle</span>
                    {{ __('billing.content_free') }}
                </span>
            @endif
            @if($game->relationLoaded('linkedLocation') && $game->linkedLocation)
                {{-- Venue entry: the venue name (→ venue page) grouped with the
                     disclosure-governed address under one icon, so they read as
                     one place instead of two disjoint trailing items in the row.
                     venue-link renders nothing for private/unverified/`other`
                     locations (no name leak); the address still graduates alone.
                     Hero is bg-primary text-on-primary. --}}
                <span class="flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">location_on</span>
                    <x-venue-link :location="$game->linkedLocation" class="font-medium hover:underline text-on-primary" />
                    <x-location-display :entity="$game" :location="$game->linkedLocation" without-icon class="text-on-primary/70" />
                </span>
            @endif
            {{-- M053/S1/T02: address granularity now flows through the single
                 <x-location-display> component (LocationDisclosureService), the
                 only address-rendering surface. The binary approved-participant
                 gate and the legacy `location` JSON fallback are both gone;
                 the games `location` column stays render-dead (HIGH-2). --}}
        </div>

        {{-- Language / players / experience chips --}}
        <div class="mt-4 flex flex-wrap gap-2">
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-on-primary/15 text-on-primary">
                <span class="material-symbols-outlined text-sm" aria-hidden="true">translate</span>
                {{ App\Enums\ContentLanguage::from($game->language)->label() }}
            </span>
            @if($game->min_players || $game->max_players)
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-on-primary/15 text-on-primary">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">groups</span>
                    @if($game->min_players && $game->max_players)
                        {{ $game->min_players }}–{{ $game->max_players }} {{ __('common.content_players') }}
                    @elseif($game->min_players)
                        {{ trans_choice('common.field_min_count_players', $game->min_players) }}
                    @else
                        {{ trans_choice('common.content_up_to_count_players', $game->max_players) }}
                    @endif
                </span>
            @endif
            @if($game->experience_level)
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-on-primary/15 text-on-primary">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">school</span>
                    {{ App\Enums\ExperienceLevel::from($game->experience_level)->label() }}
                </span>
            @endif
        </div>
    </div>
</section>

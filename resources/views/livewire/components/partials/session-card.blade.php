@props([
    'entity',
    'gameSystem' => null,
    'distanceKm' => 0,
    'participantCount' => 0,
    'type' => 'session',
])

@php
    $isCampaign = $type === 'campaign';
    $maxPlayers = $entity->max_players ?? 0;
    $minPlayers = $entity->min_players ?? 0;
    $experienceLevel = $entity->experience_level;
    $vibeFlags = $entity->vibe_flags ?? [];
    $bggRank = $gameSystem?->bgg_rank;
    $isTopHundred = $bggRank !== null && $bggRank <= 100;
    $systemName = $gameSystem?->name ?? $entity->name;

    // Date/time display
    $dateTime = $isCampaign ? null : $entity->date_time;
    $formattedDate = $dateTime?->isToday()
        ? __('common.content_today') . ', ' . $dateTime->format('H:i')
        : $dateTime?->format('D, M j, H:i');

    // Join URL
    $joinRoute = $isCampaign
        ? route('campaigns.detail', ['locale' => app()->getLocale(), 'id' => $entity])
        : route('games.detail', ['locale' => app()->getLocale(), 'id' => $entity]);
@endphp

<article class="relative bg-surface-container-low rounded-2xl border border-outline-variant overflow-hidden hover:shadow-md transition-shadow"
         aria-label="{{ $entity->name }}">

    {{-- Distance badge (D060 disclosure-governed; CRITICAL-1 surface) --}}
    <div class="absolute top-3 right-3 z-10">
            <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-surface-container text-on-surface-variant text-xs font-medium">
            <x-distance-display :precise-km="$distanceKm" :location="$entity->linkedLocation" :entity="$entity" icon="straighten" />
        </span>
    </div>

    {{-- Card body --}}
    <div class="p-4 sm:p-5">
        {{-- Game system with BGG rank --}}
        <div class="flex items-start gap-2 mb-2 pr-16">
            <h4 class="text-base font-heading font-semibold text-on-surface leading-snug">
                {{ $entity->name }}
            </h4>
        </div>

        <div class="flex flex-wrap items-center gap-2 mb-3">
            {{-- Game system name --}}
            @if($systemName)
                <span class="inline-flex items-center text-sm text-on-surface-variant">
                    {{ $systemName }}
                </span>
            @endif

            {{-- BGG top-100 badge --}}
            @if($isTopHundred)
                <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-tertiary-container text-on-tertiary-container text-xs font-bold"
                      title="{{ __('games.content_boardgamegeek_rank_rank', ['rank' => $bggRank]) }}">
                    <span class="material-symbols-outlined text-xs mr-0.5" aria-hidden="true">emoji_events</span>
                    #{{ $bggRank }}
                </span>
            @endif
        </div>

        {{-- Date/time --}}
        @if($formattedDate)
            <div class="flex items-center gap-1.5 text-sm text-on-surface-variant mb-2">
                <span class="material-symbols-outlined text-sm" aria-hidden="true">schedule</span>
                {{ $formattedDate }}
            </div>
        @elseif($isCampaign)
            <div class="flex items-center gap-1.5 text-sm text-on-surface-variant mb-2">
                <span class="material-symbols-outlined text-sm" aria-hidden="true">autorenew</span>
                {{ __('campaigns.content_ongoing_campaign') }}
            </div>
        @endif

        {{-- Player slots --}}
        <div class="flex items-center gap-1.5 text-sm text-on-surface-variant mb-2">
            <span class="material-symbols-outlined text-sm" aria-hidden="true">group</span>
            <span>{{ $participantCount }}/{{ $maxPlayers ?: '∞' }}</span>
            @if($maxPlayers > 0 && $participantCount >= $maxPlayers)
                <span class="text-xs text-error font-medium ml-1">{{ __('common.content_full') }}</span>
            @elseif($participantCount >= ($minPlayers ?: 1))
                <span class="text-xs text-primary font-medium ml-1">{{ __('common.content_ready_to_play') }}</span>
            @endif
        </div>

        {{-- Experience level --}}
        @if($experienceLevel && $experienceLevel !== 'all')
            <div class="flex items-center gap-1.5 text-sm text-on-surface-variant mb-2">
                <span class="material-symbols-outlined text-sm" aria-hidden="true">signal_cellular_alt</span>
                {{ ucfirst($experienceLevel) }}
            </div>
        @endif

        {{-- Reliability preference --}}
        @if($entity->min_reliability_preference)
            <div class="flex items-center gap-1.5 text-sm text-on-surface-variant mb-2">
                <span class="material-symbols-outlined text-sm" aria-hidden="true">verified</span>
                {{ __('games.content_host_prefers_attendance', ['percent' => round($entity->min_reliability_preference)]) }}
            </div>
        @endif

        {{-- Vibe flags --}}
        @if(!empty($vibeFlags))
            <div class="flex flex-wrap gap-1.5 mb-3">
                @foreach(array_slice($vibeFlags, 0, 3) as $flag)
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-secondary-container text-on-secondary-container text-xs">
                        {{ \Illuminate\Support\Str::title(str_replace('-', ' ', $flag)) }}
                    </span>
                @endforeach
                @if(count($vibeFlags) > 3)
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-surface-container text-on-surface-variant text-xs">
                        +{{ count($vibeFlags) - 3 }}
                    </span>
                @endif
            </div>
        @endif

        {{-- CTA --}}
        <div class="mt-auto pt-2">
            @auth
                <a href="{{ $joinRoute }}" wire:navigate
                   class="inline-flex items-center px-4 py-2 bg-primary text-on-primary rounded-lg font-semibold text-sm hover:bg-primary-container hover:text-on-primary-container transition-colors w-full justify-center">
                    <span class="material-symbols-outlined mr-1.5 text-lg" aria-hidden="true">login</span>
                    {{ $isCampaign ? __('campaigns.action_view_campaign') : __('campaigns.action_join_this_session') }}
                </a>
            @else
                <a href="{{ route('register') }}" wire:navigate
                   class="inline-flex items-center px-4 py-2 bg-primary text-on-primary rounded-lg font-semibold text-sm hover:bg-primary-container hover:text-on-primary-container transition-colors w-full justify-center">
                    <span class="material-symbols-outlined mr-1.5 text-lg" aria-hidden="true">person_add</span>
                    {{ __('campaigns.action_join_this_session') }}
                </a>
            @endauth
        </div>
    </div>
</article>

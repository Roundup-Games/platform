@props(['game'])

<a href="{{ route('games.detail', ['locale' => app()->getLocale(), 'id' => $game]) }}" wire:navigate class="block bg-surface rounded-xl shadow-ambient hover:shadow-md transition-shadow overflow-hidden group min-h-[220px]">
    <div class="h-1.5 {{ $game->campaign_id ? 'bg-secondary/60' : 'bg-primary/60' }}"></div>

    <div class="p-5">
        <div class="flex items-start justify-between mb-2">
            <h3 class="font-heading font-semibold text-lg text-on-surface tracking-tight group-hover:text-primary transition-colors line-clamp-1">
                {{ $game->name }}
            </h3>
            @if($game->price > 0)
                <span class="shrink-0 ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                    {{ format_currency($game->price, false) }}
                </span>
            @else
                <span class="shrink-0 ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                    {{ __('billing.content_free') }}
                </span>
            @endif
        </div>

        {{-- Game System + Type Badge --}}
        <div class="flex items-center gap-2 mb-3 flex-wrap">
            @if($game->game_type === App\Enums\GameType::Gathering)
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                    <span class="material-symbols-outlined text-xs mr-0.5" aria-hidden="true">groups</span>
                    {{ __('games.type_gathering') }}
                </span>
            @else
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                    <span class="material-symbols-outlined text-xs mr-0.5" aria-hidden="true">casino</span>
                    {{ __('games.content_game') }}
                </span>
            @endif
            @if($game->campaign_id && $game->campaign)
                <span
                    role="link"
                    tabindex="0"
                    onclick="event.stopPropagation(); window.location.href = '{{ route('campaigns.detail', ['locale' => app()->getLocale(), 'id' => $game->campaign]) }}'; return false;"
                    onkeydown="if(event.key==='Enter'){event.stopPropagation(); window.location.href = '{{ route('campaigns.detail', ['locale' => app()->getLocale(), 'id' => $game->campaign]) }}'; return false;}"
                    class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container hover:bg-secondary-container/80 transition-colors cursor-pointer"
                    aria-label="{{ __('campaigns.content_campaign') }}">
                    <span class="material-symbols-outlined text-xs mr-0.5" aria-hidden="true">campaign</span>
                    {{ __('campaigns.content_campaign') }}
                </span>
            @endif
            @if($game->gameSystems->isNotEmpty())
                @foreach($game->gameSystems as $system)
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface-variant">
                        {{ $system->name }}
                    </span>
                @endforeach
            @endif
            @if(isset($game->distance_km) && $game->distance_km !== null)
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-tertiary-container text-on-tertiary-container">
                    <x-distance-display :precise-km="$game->distance_km" :location="$game->linkedLocation" :entity="$game" />
                </span>
            @endif
            @if($game->visibility->value === 'protected')
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-tertiary/10 text-on-tertiary-container">
                    <span class="material-symbols-outlined text-xs" aria-hidden="true">group</span>
                    {{ $game->visibility->label() }}
                </span>
            @endif
            @if($game->experience_level)
                @php($levelEnum = App\Enums\ExperienceLevel::tryFrom($game->experience_level))
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface-variant">
                    {{ $levelEnum?->label() ?? $game->experience_level }}
                </span>
            @endif
            <x-language-chip :language="$game->language" />
        </div>

        {{-- Date & Duration --}}
        <p class="text-sm text-on-surface-variant flex items-center gap-1">
            <span class="material-symbols-outlined text-base" aria-hidden="true">calendar_today</span>
            {{ format_date($game->date_time, 'datetime') }}
        </p>
        @if($game->expected_duration)
            <p class="mt-1 text-sm text-on-surface-variant flex items-center gap-1">
                <span class="material-symbols-outlined text-base" aria-hidden="true">schedule</span>
                {{ $game->expected_duration }}h
            </p>
        @endif

        {{-- Description --}}
        @if($game->description)
            <p class="mt-2 text-sm text-on-surface-variant line-clamp-2">{{ Str::limit($game->description, 120) }}</p>
        @endif

        {{-- Vibe flags --}}
        @if($game->vibe_flags && count($game->vibe_flags))
            <div class="mt-3 flex flex-wrap gap-1">
                @foreach(array_slice($game->vibe_flags, 0, 4) as $flag)
                    @php($flagEnum = App\Enums\VibeFlag::tryFrom($flag))
                    @if($flagEnum)
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-primary/10 text-primary">
                            {{ $flagEnum->label() }}
                        </span>
                    @endif
                @endforeach
                @if(count($game->vibe_flags) > 4)
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-surface-container text-on-surface-variant">
                        +{{ count($game->vibe_flags) - 4 }}
                    </span>
                @endif
            </div>
        @endif

        {{-- Player count / Participants --}}
        @php($approvedCount = $game->participants_count ?? 0)
        @php($spotsLeft = $game->max_players ? max(0, $game->max_players - $approvedCount) : null)
        <div class="mt-3 flex items-center gap-3 text-xs text-on-surface-variant">
            @if($game->max_players)
                @if($spotsLeft === 0)
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-error/10 text-error">
                        <span class="material-symbols-outlined text-xs" aria-hidden="true">lock</span>
                        {{ __('games.content_full') }}
                    </span>
                @elseif($spotsLeft !== null && $spotsLeft <= 2)
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-primary/15 text-primary">
                        <span class="material-symbols-outlined text-xs" aria-hidden="true">group</span>
                        {{ trans_choice('games.content_spots_left_short', $spotsLeft) }}
                    </span>
                @else
                    <span class="flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">group</span>
                        {{ $approvedCount }}/{{ $game->max_players }}
                    </span>
                @endif
            @elseif($game->min_players)
                <span class="flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">groups</span>
                    {{ $game->min_players }}+
                </span>
            @endif
            @if(($game->waitlisted_count ?? 0) > 0 || ($game->benched_count ?? 0) > 0)
                @php($isBench = $game->isBenchMode())
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-tertiary/10 text-tertiary">
                    {{ trans_choice($isBench ? 'common.content_n_on_bench' : 'common.content_n_waitlisted', $isBench ? ($game->benched_count ?? 0) : ($game->waitlisted_count ?? 0)) }}
                </span>
            @endif
        </div>
    </div>
</a>

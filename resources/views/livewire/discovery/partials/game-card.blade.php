@props(['game'])

<a href="{{ route('games.detail', $game->id) }}" wire:navigate class="block bg-surface rounded-xl shadow-ambient hover:shadow-md transition-shadow overflow-hidden group">
    <div class="h-1.5 bg-primary/60"></div>

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
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                <span class="material-symbols-outlined text-xs mr-0.5" aria-hidden="true">casino</span>
                {{ __('games.content_game') }}
            </span>
            @if($game->gameSystem)
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface-variant">
                    {{ $game->gameSystem?->name }}
                </span>
            @endif
            @if(isset($game->distance_km) && $game->distance_km !== null)
                <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-xs font-medium bg-tertiary-container text-on-tertiary-container">
                    <span class="material-symbols-outlined text-xs" aria-hidden="true">location_on</span>
                    {{ $game->distance_km < 1 ? round($game->distance_km * 1000) . ' m' : number_format($game->distance_km, 1) . ' km' }}
                </span>
            @endif
            @if($game->visibility === 'protected')
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-tertiary/10 text-on-tertiary-container">
                    <span class="material-symbols-outlined text-xs" aria-hidden="true">lock</span>
                    {{ __('teams.content_members_only') }}
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
        <div class="mt-3 flex items-center gap-3 text-xs text-on-surface-variant">
            @if($game->min_players || $game->max_players)
                <span class="flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">groups</span>
                    @if($game->min_players && $game->max_players)
                        {{ $game->min_players }}–{{ $game->max_players }}
                    @elseif($game->min_players)
                        {{ $game->min_players }}+
                    @else
                        ≤{{ $game->max_players }}
                    @endif
                </span>
            @endif
            @if(isset($game->participants_count))
                <span class="flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">person</span>
                    {{ trans_choice('common.content_joined', $game->participants_count) }}
                </span>
            @endif
        </div>
    </div>
</a>

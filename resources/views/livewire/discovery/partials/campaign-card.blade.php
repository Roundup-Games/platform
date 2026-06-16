@props(['campaign'])

<a href="{{ route('campaigns.detail', ['locale' => app()->getLocale(), 'id' => $campaign->id]) }}" wire:navigate class="block bg-surface rounded-xl shadow-ambient hover:shadow-md transition-shadow overflow-hidden group min-h-[220px]">
    <div class="h-1.5 bg-secondary/60"></div>

    <div class="p-5">
        <div class="flex items-start justify-between mb-2">
            <h3 class="font-heading font-semibold text-lg text-on-surface tracking-tight group-hover:text-primary transition-colors line-clamp-1">
                {{ $campaign->name }}
            </h3>
            @if($campaign->price_per_session > 0)
                <span class="shrink-0 ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                    {{ format_currency($campaign->price_per_session, false) }}/session
                </span>
            @else
                <span class="shrink-0 ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                    {{ __('billing.content_free') }}
                </span>
            @endif
        </div>

        {{-- Campaign badge + Game System --}}
        <div class="flex items-center gap-2 mb-3 flex-wrap">
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                <span class="material-symbols-outlined text-xs mr-0.5" aria-hidden="true">campaign</span>
                {{ __('campaigns.content_campaign') }}
            </span>
            @if($campaign->gameSystem)
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface-variant">
                    {{ $campaign->gameSystem?->name }}
                </span>
            @endif
            @if(isset($campaign->distance_km) && $campaign->distance_km !== null)
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-tertiary-container text-on-tertiary-container">
                    <x-distance-display :precise-km="$campaign->distance_km" :location="$campaign->linkedLocation" :entity="$campaign" />
                </span>
            @endif
            @if($campaign->visibility->value === 'protected')
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-tertiary/10 text-on-tertiary-container">
                    <span class="material-symbols-outlined text-xs" aria-hidden="true">group</span>
                    {{ $campaign->visibility->label() }}
                </span>
            @endif
            @if($campaign->experience_level)
                @php($levelEnum = App\Enums\ExperienceLevel::tryFrom($campaign->experience_level))
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface-variant">
                    {{ $levelEnum?->label() ?? $campaign->experience_level }}
                </span>
            @endif
            <x-language-chip :language="$campaign->language" />
        </div>

        {{-- Next Session / Recurrence & Duration --}}
        @if($campaign->relationLoaded('sessions') && $campaign->sessions->isNotEmpty())
            @php($nextSession = $campaign->sessions->first())
            <p class="text-sm text-on-surface-variant flex items-center gap-1">
                <span class="material-symbols-outlined text-base" aria-hidden="true">calendar_today</span>
                {{ format_date($nextSession->date_time, 'datetime') }}
            </p>
        @elseif($campaign->recurrence)
            <p class="text-sm text-on-surface-variant flex items-center gap-1">
                <span class="material-symbols-outlined text-base" aria-hidden="true">repeat</span>
                {{ match($campaign->recurrence) {
                    'weekly' => __('campaigns.content_weekly'),
                    'bi-weekly' => __('campaigns.content_bi-weekly'),
                    'monthly' => __('campaigns.content_monthly'),
                    default => __(ucfirst(str_replace('-', ' ', $campaign->recurrence))),
                } }}
            </p>
        @endif
        @if(!$campaign->relationLoaded('sessions') || $campaign->sessions->isEmpty())
            @if($campaign->session_duration)
                <p class="mt-1 text-sm text-on-surface-variant flex items-center gap-1">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">schedule</span>
                    {{ $campaign->session_duration }}h {{ __('campaigns.content_per_session') }}
                </p>
            @endif
        @endif

        {{-- Description --}}
        @if($campaign->description)
            <p class="mt-2 text-sm text-on-surface-variant line-clamp-2">{{ Str::limit($campaign->description, 120) }}</p>
        @endif

        {{-- Vibe flags --}}
        @if($campaign->vibe_flags && count($campaign->vibe_flags))
            <div class="mt-3 flex flex-wrap gap-1">
                @foreach(array_slice($campaign->vibe_flags, 0, 4) as $flag)
                    @php($flagEnum = App\Enums\VibeFlag::tryFrom($flag))
                    @if($flagEnum)
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-primary/10 text-primary">
                            {{ $flagEnum->label() }}
                        </span>
                    @endif
                @endforeach
                @if(count($campaign->vibe_flags) > 4)
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-surface-container text-on-surface-variant">
                        +{{ count($campaign->vibe_flags) - 4 }}
                    </span>
                @endif
            </div>
        @endif

        {{-- Player count / Sessions --}}
        <div class="mt-3 flex items-center gap-3 text-xs text-on-surface-variant">
            @if($campaign->min_players || $campaign->max_players)
                <span class="flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">groups</span>
                    @if($campaign->min_players && $campaign->max_players)
                        {{ $campaign->min_players }}–{{ $campaign->max_players }}
                    @elseif($campaign->min_players)
                        {{ $campaign->min_players }}+
                    @else
                        ≤{{ $campaign->max_players }}
                    @endif
                </span>
            @endif
            @if(isset($campaign->sessions_count))
                <span class="flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">event_note</span>
                    {{ trans_choice('campaigns.content_count_sessions', $campaign->sessions_count) }}
                </span>
            @endif
            @if(($campaign->waitlisted_count ?? 0) > 0 || ($campaign->benched_count ?? 0) > 0)
                @php($isBench = $campaign->isBenchMode())
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-tertiary/10 text-tertiary">
                    {{ trans_choice($isBench ? 'common.content_n_on_bench' : 'common.content_n_waitlisted', $isBench ? ($campaign->benched_count ?? 0) : ($campaign->waitlisted_count ?? 0)) }}
                </span>
            @endif
        </div>
    </div>
</a>

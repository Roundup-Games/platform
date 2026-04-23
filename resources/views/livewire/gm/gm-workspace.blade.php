<div>
    @section('title', __('gws.title_gm_workspace'))

    {{-- ── Welcome Card ──────────────────────────────────────────── --}}
    <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-8">
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 bg-primary/10 rounded-2xl flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-primary text-2xl" style="font-variation-settings: 'FILL' 1">person_play</span>
            </div>
            <div>
                <h2 class="font-heading text-2xl font-bold text-on-surface tracking-tight">
                    {{ __('gws.title_gm_workspace') }}
                </h2>
                <p class="mt-1 text-on-surface-variant text-sm">
                    {{ __('gws.description_your_gm_command_center') }}
                </p>
            </div>
        </div>
    </div>

    {{-- ── 4-Section Dashboard Grid ──────────────────────────────── --}}
    <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-5">

        {{-- ═══ (1) Upcoming Sessions ═══════════════════════════════ --}}
        <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">calendar_upcoming</span>
                    <h3 class="font-heading font-semibold text-on-surface">{{ __('gws.heading_upcoming_sessions') }}</h3>
                </div>
                @if($upcomingSessions->count())
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-primary/10 text-primary text-xs font-semibold">
                        {{ $upcomingSessions->count() }}
                    </span>
                @endif
            </div>

            @if($upcomingSessions->count())
                <div class="space-y-3">
                    @foreach($upcomingSessions as $session)
                        <a href="{{ route('games.detail', $session->id) }}" wire:navigate
                           class="block p-3 rounded-lg bg-surface-container-high/50 hover:bg-surface-container-high transition-colors group">
                            <div class="flex items-center justify-between">
                                <div class="min-w-0">
                                    <h4 class="text-sm font-medium text-on-surface group-hover:text-primary truncate">{{ $session->name }}</h4>
                                    <div class="flex items-center gap-2 mt-1 text-xs text-on-surface-variant">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">schedule</span>
                                        {{ $session->date_time->format('M j, g:i A') }}
                                        @if($session->gameSystem)
                                            <span class="text-on-surface-variant/50">·</span>
                                            <span>{{ $session->gameSystem->name }}</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="flex items-center gap-1 text-xs text-on-surface-variant ml-2 shrink-0">
                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">group</span>
                                    {{ $session->participants->count() }}/{{ $session->max_players }}
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            @else
                <div class="text-center py-8">
                    <span class="material-symbols-outlined text-3xl text-on-surface-variant/30 mb-2 block">event_available</span>
                    <p class="text-sm text-on-surface-variant">{{ __('gws.content_no_upcoming_sessions') }}</p>
                </div>
            @endif
        </div>

        {{-- ═══ (2) Review Summary ══════════════════════════════════ --}}
        <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">star_rate</span>
                    <h3 class="font-heading font-semibold text-on-surface">{{ __('gws.heading_review_summary') }}</h3>
                </div>
            </div>

            {{-- Aggregate Stats --}}
            <div class="flex items-center gap-4 mb-5 p-4 bg-surface-container-high/50 rounded-lg">
                <div class="text-center">
                    <div class="text-3xl font-heading font-bold text-amber-500">
                        {{ $gmProfile->average_rating ? number_format($gmProfile->average_rating, 1) : '—' }}
                    </div>
                    <div class="text-xs text-on-surface-variant mt-0.5">{{ __('gws.label_average') }}</div>
                </div>
                <div class="w-px h-10 bg-outline-variant/20"></div>
                <div class="text-center">
                    <div class="text-3xl font-heading font-bold text-on-surface">{{ $gmProfile->review_count }}</div>
                    <div class="text-xs text-on-surface-variant mt-0.5">{{ trans_choice('gws.label_reviews', $gmProfile->review_count) }}</div>
                </div>
            </div>

            {{-- Recent Reviews --}}
            @if($recentReviews->count())
                <div class="space-y-3">
                    @foreach($recentReviews as $review)
                        <div class="p-3 rounded-lg bg-surface-container-high/50">
                            <div class="flex items-center justify-between mb-1">
                                <div class="flex items-center gap-2">
                                    @if($review->reviewer)
                                        <span class="text-sm font-medium text-on-surface">{{ $review->reviewer->name }}</span>
                                    @endif
                                    <div class="flex items-center">
                                        @for($i = 1; $i <= 5; $i++)
                                            <span class="text-xs {{ $i <= $review->rating ? 'text-amber-500' : 'text-on-surface-variant/30' }}">★</span>
                                        @endfor
                                    </div>
                                </div>
                                <span class="text-xs text-on-surface-variant">{{ $review->created_at->diffForHumans() }}</span>
                            </div>
                            @if($review->body)
                                <p class="text-sm text-on-surface-variant line-clamp-2">{{ Str::limit($review->body, 140) }}</p>
                            @endif
                            @if($review->proficiency_tags && count($review->proficiency_tags))
                                <div class="flex flex-wrap gap-1 mt-2">
                                    @foreach(array_slice($review->proficiency_tags, 0, 3) as $tag)
                                        @php
                                            try {
                                                $tagLabel = \App\Enums\GmProficiency::from($tag)->label();
                                            } catch (\ValueError $e) {
                                                $tagLabel = $tag;
                                            }
                                        @endphp
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-primary-container text-on-primary-container">
                                            {{ $tagLabel }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-6">
                    <span class="material-symbols-outlined text-3xl text-on-surface-variant/30 mb-2 block">rate_review</span>
                    <p class="text-sm text-on-surface-variant">{{ __('gws.content_no_reviews_yet') }}</p>
                </div>
            @endif
        </div>

        {{-- ═══ (3) Participant Stats ═══════════════════════════════ --}}
        <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <div class="flex items-center gap-2 mb-4">
                <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">group</span>
                <h3 class="font-heading font-semibold text-on-surface">{{ __('gws.heading_participant_stats') }}</h3>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="p-4 bg-surface-container-high/50 rounded-lg text-center">
                    <div class="text-3xl font-heading font-bold text-primary">{{ $totalUniquePlayers }}</div>
                    <div class="text-xs text-on-surface-variant mt-1">{{ __('gws.label_unique_players') }}</div>
                </div>
                <div class="p-4 bg-surface-container-high/50 rounded-lg text-center">
                    <div class="text-3xl font-heading font-bold text-primary">{{ $repeatPlayers }}</div>
                    <div class="text-xs text-on-surface-variant mt-1">{{ __('gws.label_repeat_players') }}</div>
                </div>
                <div class="p-4 bg-surface-container-high/50 rounded-lg text-center">
                    <div class="text-3xl font-heading font-bold text-on-surface">{{ $totalGames }}</div>
                    <div class="text-xs text-on-surface-variant mt-1">{{ __('gws.label_total_games') }}</div>
                </div>
                <div class="p-4 bg-surface-container-high/50 rounded-lg text-center">
                    <div class="text-3xl font-heading font-bold text-on-surface">{{ $activeCampaigns }}</div>
                    <div class="text-xs text-on-surface-variant mt-1">{{ __('gws.label_active_campaigns') }}</div>
                </div>
            </div>
        </div>

        {{-- ═══ (4) Quick Actions ═══════════════════════════════════ --}}
        <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <div class="flex items-center gap-2 mb-4">
                <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">bolt</span>
                <h3 class="font-heading font-semibold text-on-surface">{{ __('gws.heading_quick_actions') }}</h3>
            </div>

            <div class="space-y-3">
                <a href="{{ route('games.create') }}" wire:navigate
                   class="flex items-center gap-3 p-3 rounded-lg bg-surface-container-high/50 hover:bg-surface-container-high transition-colors group">
                    <div class="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-primary">add_circle</span>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-on-surface group-hover:text-primary">{{ __('gws.action_create_game') }}</span>
                        <p class="text-xs text-on-surface-variant">{{ __('gws.action_create_game_desc') }}</p>
                    </div>
                </a>

                <a href="{{ route('campaigns.create') }}" wire:navigate
                   class="flex items-center gap-3 p-3 rounded-lg bg-surface-container-high/50 hover:bg-surface-container-high transition-colors group">
                    <div class="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-primary">add_business</span>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-on-surface group-hover:text-primary">{{ __('gws.action_create_campaign') }}</span>
                        <p class="text-xs text-on-surface-variant">{{ __('gws.action_create_campaign_desc') }}</p>
                    </div>
                </a>

                <a href="{{ route('gm.directory') }}" wire:navigate
                   class="flex items-center gap-3 p-3 rounded-lg bg-surface-container-high/50 hover:bg-surface-container-high transition-colors group">
                    <div class="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-primary">manage_accounts</span>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-on-surface group-hover:text-primary">{{ __('gws.action_manage_profile') }}</span>
                        <p class="text-xs text-on-surface-variant">{{ __('gws.action_manage_profile_desc') }}</p>
                    </div>
                </a>

                <a href="{{ route('gm.session-zero.create') }}" wire:navigate
                   class="flex items-center gap-3 p-3 rounded-lg bg-surface-container-high/50 hover:bg-surface-container-high transition-colors group">
                    <div class="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-primary">assignment</span>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-on-surface group-hover:text-primary">{{ __('gws.action_create_session_zero') }}</span>
                        <p class="text-xs text-on-surface-variant">{{ __('gws.action_create_session_zero_desc') }}</p>
                    </div>
                </a>
            </div>
        </div>
    </div>

    {{-- ── Session Zero Surveys ─────────────────────────────────── --}}
    <div class="mt-6 bg-surface-container-lowest rounded-xl shadow-ambient p-6">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">assignment</span>
                <h3 class="font-heading font-semibold text-on-surface">{{ __('gws.heading_session_zero_surveys') }}</h3>
            </div>
            @if($sessionZeroSurveys->count())
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-primary/10 text-primary text-xs font-semibold">
                    {{ $sessionZeroSurveys->count() }}
                </span>
            @endif
        </div>

        @if($sessionZeroSurveys->count())
            <div class="space-y-3">
                @foreach($sessionZeroSurveys as $survey)
                    <div class="flex items-center gap-4 p-3 rounded-lg bg-surface-container-high/50 group">
                        <div class="flex-1 min-w-0">
                            <h4 class="text-sm font-medium text-on-surface truncate">{{ $survey->title }}</h4>
                            <div class="flex items-center gap-3 mt-1 text-xs text-on-surface-variant">
                                @if($survey->game)
                                    <span class="flex items-center gap-1">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">casino</span>
                                        {{ $survey->game->name }}
                                    </span>
                                @else
                                    <span class="flex items-center gap-1">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">link_off</span>
                                        {{ __('gws.content_no_linked_game') }}
                                    </span>
                                @endif
                                <span class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">group</span>
                                    {{ $survey->confirmation_count }}
                                </span>
                                <span>{{ $survey->created_at->format('M j, Y') }}</span>
                            </div>
                        </div>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium shrink-0
                            {{ $survey->isActive() ? 'bg-secondary-container text-on-secondary-container' : 'bg-surface-container-highest text-on-surface-variant' }}">
                            {{ $survey->isActive() ? __('gws.label_active') : __('gws.label_archived') }}
                        </span>
                        <div class="flex items-center gap-2 shrink-0">
                            <a href="{{ route('session-zero.view', ['locale' => app()->getLocale(), 'uuid' => $survey->uuid]) }}"
                               class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg bg-primary text-on-primary hover:opacity-90 transition-opacity"
                               wire:navigate>
                                <span class="material-symbols-outlined text-sm" aria-hidden="true">visibility</span>
                                {{ __('gws.action_view') }}
                            </a>
                            <button type="button"
                                x-data="{ copied: false }"
                                @click="navigator.clipboard.writeText('{{ route('session-zero.view', ['locale' => app()->getLocale(), 'uuid' => $survey->uuid]) }}'); copied = true; setTimeout(() => copied = false, 2000)"
                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest transition-colors">
                                <span class="material-symbols-outlined text-sm" aria-hidden="true" x-show="!copied">content_copy</span>
                                <span class="material-symbols-outlined text-sm text-secondary" aria-hidden="true" x-show="copied">check</span>
                                <span x-text="copied ? '{{ __('session_zero.action_copied') }}' : '{{ __('session_zero.action_copy_link') }}'"></span>
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="text-center py-8">
                <span class="material-symbols-outlined text-3xl text-on-surface-variant/30 mb-2 block">assignment</span>
                <p class="text-sm text-on-surface-variant">{{ __('gws.content_no_session_zero_surveys') }}</p>
            </div>
        @endif
    </div>
</div>

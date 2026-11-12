
            {{-- Metadata sidebar (1/3 width) --}}
            <aside>
                <div class="bg-surface-container rounded-xl shadow-ambient p-5 space-y-4 sticky top-24">
                    <h2 class="text-sm font-heading font-bold text-primary uppercase tracking-wide">{{ __('games.heading_game_details') }}</h2>

                    {{--
                        Definition-list semantics (a11y + agentic tree, 2026-11): the
                        HTML spec allows exactly ONE <div> wrapper per dt/dd group
                        inside a <dl>. The previous markup nested a second <div>
                        around dt/dd, which malformed the accessibility tree
                        (Lighthouse definition-list/dlitem/agent-accessibility-tree).
                        Each group is now a single div using a two-column grid
                        (icon | stacked dt+dd) — visually identical, spec-valid.
                    --}}
                    <dl class="space-y-3 text-sm">
                        {{-- TTRPG: player_range takes precedence --}}
                        @if($system->isTtrpg() && $system->player_range)
                            <div class="grid grid-cols-[auto_1fr] items-start gap-x-3">
                                <span class="material-symbols-outlined text-lg text-on-surface-variant mt-0.5 row-span-2" aria-hidden="true">group</span>
                                <dt class="text-on-surface-variant">{{ __('games.field_player_count') }}</dt>
                                <dd class="font-medium text-on-surface">{{ $system->player_range }}</dd>
                            </div>
                        @elseif($system->min_players || $system->max_players)
                            <div class="grid grid-cols-[auto_1fr] items-start gap-x-3">
                                <span class="material-symbols-outlined text-lg text-on-surface-variant mt-0.5 row-span-2" aria-hidden="true">group</span>
                                <dt class="text-on-surface-variant">{{ __('games.field_player_count') }}</dt>
                                <dd class="font-medium text-on-surface">{{ $system->min_players ?? '?' }}–{{ $system->max_players ?? '?' }}</dd>
                            </div>
                        @endif
                        @if($system->average_play_time)
                            <div class="grid grid-cols-[auto_1fr] items-start gap-x-3">
                                <span class="material-symbols-outlined text-lg text-on-surface-variant mt-0.5 row-span-2" aria-hidden="true">schedule</span>
                                <dt class="text-on-surface-variant">{{ __('games.content_play_time') }}</dt>
                                <dd class="font-medium text-on-surface">{{ $system->average_play_time }} {{ strtolower(__('common.label_unit_min')) }}</dd>
                            </div>
                        @endif
                        @if($system->age_rating)
                            <div class="grid grid-cols-[auto_1fr] items-start gap-x-3">
                                <span class="material-symbols-outlined text-lg text-on-surface-variant mt-0.5 row-span-2" aria-hidden="true">person</span>
                                <dt class="text-on-surface-variant">{{ __('games.content_age') }}</dt>
                                <dd class="font-medium text-on-surface">{{ $system->age_rating }}+</dd>
                            </div>
                        @endif
                        @if($system->year_released)
                            <div class="grid grid-cols-[auto_1fr] items-start gap-x-3">
                                <span class="material-symbols-outlined text-lg text-on-surface-variant mt-0.5 row-span-2" aria-hidden="true">calendar_today</span>
                                <dt class="text-on-surface-variant">{{ __('games.field_year') }}</dt>
                                <dd class="font-medium text-on-surface">{{ $system->year_released }}</dd>
                            </div>
                        @endif
                        {{-- TTRPG: Creator --}}
                        @if($system->creator)
                            <div class="grid grid-cols-[auto_1fr] items-start gap-x-3">
                                <span class="material-symbols-outlined text-lg text-on-surface-variant mt-0.5 row-span-2" aria-hidden="true">person_edit</span>
                                <dt class="text-on-surface-variant">{{ __('games.field_creator') }}</dt>
                                <dd class="font-medium text-on-surface">{{ $system->creator }}</dd>
                            </div>
                        @endif
                        {{-- TTRPG: Publishers --}}
                        @if($system->publishers->count())
                            <div class="grid grid-cols-[auto_1fr] items-start gap-x-3">
                                <span class="material-symbols-outlined text-lg text-on-surface-variant mt-0.5 row-span-2" aria-hidden="true">business</span>
                                <dt class="text-on-surface-variant">{{ trans_choice('games.field_publisher', $system->publishers->count()) }}</dt>
                                <dd class="font-medium text-on-surface">{{ $system->publishers->pluck('name')->join(', ') }}</dd>
                            </div>
                        @endif
                        {{-- TTRPG: Designers --}}
                        @if($system->designers->count())
                            <div class="grid grid-cols-[auto_1fr] items-start gap-x-3">
                                <span class="material-symbols-outlined text-lg text-on-surface-variant mt-0.5 row-span-2" aria-hidden="true">draw</span>
                                <dt class="text-on-surface-variant">{{ trans_choice('games.field_designer', $system->designers->count()) }}</dt>
                                <dd class="font-medium text-on-surface">{{ $system->designers->pluck('name')->join(', ') }}</dd>
                            </div>
                        @endif
                        {{-- TTRPG: SP Rating --}}
                        @if($system->sp_rating && $system->sp_rating > 0)
                            <div class="grid grid-cols-[auto_1fr] items-start gap-x-3">
                                <span class="material-symbols-outlined text-lg text-amber-500 mt-0.5 row-span-2" aria-hidden="true">star</span>
                                <dt class="text-on-surface-variant">{{ __('games.content_sp_rating') }}</dt>
                                <dd class="font-medium text-on-surface">{{ number_format((float) $system->sp_rating, 1) }} / 5
                                    @if($system->sp_review_count)
                                        <span class="text-on-surface-variant text-xs">({{ $system->sp_review_count }})</span>
                                    @endif
                                </dd>
                            </div>
                        @endif
                        {{-- BGG Rating (board games) --}}
                        @if($system->bgg_average_rating)
                            <div class="grid grid-cols-[auto_1fr] items-start gap-x-3">
                                <span class="material-symbols-outlined text-lg text-amber-500 mt-0.5 row-span-2" aria-hidden="true">star</span>
                                <dt class="text-on-surface-variant">{{ __('games.content_bgg_rating') }}</dt>
                                <dd class="font-medium text-on-surface">{{ number_format($system->bgg_average_rating, 1) }} / 10
                                    @if($system->bgg_users_rated)
                                        <span class="text-on-surface-variant text-xs">({{ number_format($system->bgg_users_rated) }})</span>
                                    @endif
                                </dd>
                            </div>
                        @endif
                        @if($system->bgg_average_weight && $system->bgg_average_weight > 0)
                            <div class="grid grid-cols-[auto_1fr] items-start gap-x-3">
                                <span class="material-symbols-outlined text-lg text-on-surface-variant mt-0.5 row-span-2" aria-hidden="true">fitness_center</span>
                                <dt class="text-on-surface-variant">{{ __('games.content_complexity') }}</dt>
                                <dd class="font-medium text-on-surface">{{ number_format($system->bgg_average_weight, 1) }} / 5
                                    <div class="mt-1 h-1.5 bg-surface-container-highest rounded-full overflow-hidden max-w-[120px]">
                                        <div class="h-full rounded-full bg-linear-to-r from-green-400 via-amber-400 to-red-400" style="width: {{ min(100, ($system->bgg_average_weight / 5) * 100) }}%"></div>
                                    </div>
                                </dd>
                            </div>
                        @endif
                        @if($system->bgg_rank)
                            <div class="grid grid-cols-[auto_1fr] items-start gap-x-3">
                                <span class="material-symbols-outlined text-lg text-on-surface-variant mt-0.5 row-span-2" aria-hidden="true">emoji_events</span>
                                <dt class="text-on-surface-variant">{{ __('games.content_boardgamegeek_rank_rank', ['rank' => '']) }}</dt>
                                <dd class="font-medium text-on-surface">#{{ number_format($system->bgg_rank) }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>
            </aside>

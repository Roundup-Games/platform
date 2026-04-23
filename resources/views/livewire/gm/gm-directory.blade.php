<div>
    {{-- ── Header ────────────────────────────────────────────────── --}}
    <section class="bg-primary text-on-primary">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
            <h1 class="text-2xl sm:text-3xl font-heading font-bold tracking-tight">{{ __('gms.title_game_master_directory') }}</h1>
            <p class="mt-1 text-sm text-on-primary/80">{{ __('gms.description_find_your_perfect_gm') }}</p>

            {{-- ── Search ─────────────────────────────────────── --}}
            <div class="mt-4 relative max-w-xl">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-primary/60 text-lg" aria-hidden="true">search</span>
                <input type="text"
                       aria-label="{{ __('gms.action_search_gms') }}"
                       wire:model.live.debounce.300ms="search"
                       placeholder="{{ __('gms.action_search_by_name') }}"
                       class="w-full pl-10 pr-4 py-2.5 bg-on-primary/10 border border-on-primary/20 rounded-full text-on-primary placeholder:text-on-primary/50 focus:bg-on-primary/20 focus:border-on-primary/40 focus:ring-2 focus:ring-on-primary/20" />
            </div>
        </div>
    </section>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-6">
        {{-- ── Filter Bar ────────────────────────────────────────── --}}
        <div class="flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-4 mb-6">

            {{-- Sort --}}
            <div class="flex items-center gap-2">
                <label for="gm-sort" class="text-sm font-medium text-on-surface-variant whitespace-nowrap">{{ __('gms.field_sort_by') }}</label>
                <select id="gm-sort"
                        wire:model.live="sortBy"
                        class="bg-surface-container-high text-on-surface text-sm rounded-lg px-3 py-1.5 border border-outline-variant/30 focus:ring-2 focus:ring-primary/20 focus:border-primary">
                    <option value="highest_rated">{{ __('gms.sort_highest_rated') }}</option>
                    <option value="most_reviewed">{{ __('gms.sort_most_reviewed') }}</option>
                    <option value="newest">{{ __('gms.sort_newest') }}</option>
                </select>
            </div>

            {{-- Specialization filter --}}
            <div class="flex items-center gap-2">
                <label for="gm-specialization" class="text-sm font-medium text-on-surface-variant whitespace-nowrap">{{ __('gms.field_specialization') }}</label>
                <select id="gm-specialization"
                        wire:model.live="specialization"
                        class="bg-surface-container-high text-on-surface text-sm rounded-lg px-3 py-1.5 border border-outline-variant/30 focus:ring-2 focus:ring-primary/20 focus:border-primary">
                    <option value="">{{ __('gms.field_all_specializations') }}</option>
                    @foreach($proficiencies as $proficiency)
                        <option value="{{ $proficiency->value }}">{{ $proficiency->label() }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Game system filter --}}
            <div class="flex items-center gap-2">
                <label for="gm-game-system" class="text-sm font-medium text-on-surface-variant whitespace-nowrap">{{ __('gms.field_game_system') }}</label>
                <select id="gm-game-system"
                        wire:model.live="game_system_id"
                        class="bg-surface-container-high text-on-surface text-sm rounded-lg px-3 py-1.5 border border-outline-variant/30 focus:ring-2 focus:ring-primary/20 focus:border-primary">
                    <option value="">{{ __('gms.field_all_systems') }}</option>
                    @foreach($gameSystems as $system)
                        <option value="{{ $system->id }}">{{ $system->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Min rating filter --}}
            <div class="flex items-center gap-2">
                <label for="gm-min-rating" class="text-sm font-medium text-on-surface-variant whitespace-nowrap">{{ __('gms.field_min_rating') }}</label>
                <select id="gm-min-rating"
                        wire:model.live="min_rating"
                        class="bg-surface-container-high text-on-surface text-sm rounded-lg px-3 py-1.5 border border-outline-variant/30 focus:ring-2 focus:ring-primary/20 focus:border-primary">
                    <option value="">{{ __('gms.field_any_rating') }}</option>
                    @for($i = 1; $i <= 5; $i++)
                        <option value="{{ $i }}">{{ $i }}+ ★</option>
                    @endfor
                </select>
            </div>

            {{-- Clear filters --}}
            @if($this->hasActiveFilters())
                <button wire:click="clearFilters"
                        class="text-sm text-primary font-medium hover:underline whitespace-nowrap">
                    {{ __('gms.action_clear_filters') }}
                </button>
            @endif
        </div>

        {{-- ── GM Cards Grid ─────────────────────────────────────── --}}
        @if($results->count())
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                @foreach($results as $gmProfile)
                    <a href="{{ route('profile.public', $gmProfile->user) }}"
                       wire:navigate
                       class="block bg-surface-container rounded-2xl border border-outline-variant/15 hover:border-primary/40 hover:shadow-lg transition-all duration-200 overflow-hidden group">
                        <div class="p-5">
                            {{-- Avatar + Name --}}
                            <div class="flex items-center gap-3 mb-3">
                                <div class="w-12 h-12 rounded-full bg-primary-container flex items-center justify-center text-on-primary-container font-heading font-bold text-lg shrink-0 overflow-hidden">
                                    @if($gmProfile->user->avatar_url)
                                        <img src="{{ $gmProfile->user->avatar_url }}" alt="{{ $gmProfile->user->name }}" class="w-full h-full object-cover" />
                                    @else
                                        {{ Str::substr($gmProfile->user->name, 0, 1) }}
                                    @endif
                                </div>
                                <div class="min-w-0">
                                    <h3 class="font-heading font-semibold text-on-surface group-hover:text-primary transition-colors truncate">{{ $gmProfile->user->name }}</h3>
                                    @if($gmProfile->average_rating)
                                        <div class="flex items-center gap-1 text-sm">
                                            <span class="text-amber-500 font-semibold">{{ number_format($gmProfile->average_rating, 1) }}</span>
                                            <span class="text-amber-500">★</span>
                                            <span class="text-on-surface-variant">·</span>
                                            <span class="text-on-surface-variant">{{ $gmProfile->review_count }} {{ trans_choice('profile.gm_profile_reviews', $gmProfile->review_count) }}</span>
                                        </div>
                                    @else
                                        <span class="text-sm text-on-surface-variant">{{ __('profile.gm_profile_no_reviews') }}</span>
                                    @endif
                                </div>
                            </div>

                            {{-- Bio excerpt --}}
                            @if($gmProfile->bio)
                                <p class="text-sm text-on-surface-variant line-clamp-2 mb-3">{{ Str::limit($gmProfile->bio, 120) }}</p>
                            @endif

                            {{-- Specializations as tags --}}
                            @if($gmProfile->specializations && count($gmProfile->specializations))
                                <div class="flex flex-wrap gap-1.5 mb-3">
                                    @foreach(array_slice($gmProfile->specializations, 0, 4) as $spec)
                                        @php
                                            try {
                                                $label = \App\Enums\GmProficiency::from($spec)->label();
                                            } catch (\ValueError $e) {
                                                $label = $spec;
                                            }
                                        @endphp
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                                            {{ $label }}
                                        </span>
                                    @endforeach
                                    @if(count($gmProfile->specializations) > 4)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container-high text-on-surface-variant">
                                            +{{ count($gmProfile->specializations) - 4 }}
                                        </span>
                                    @endif
                                </div>
                            @endif

                            {{-- Top proficiency badges (from reviews) --}}
                            @if($gmProfile->top_proficiencies && count($gmProfile->top_proficiencies))
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach($gmProfile->top_proficiencies as $prof)
                                        @php
                                            try {
                                                $badgeLabel = \App\Enums\GmProficiency::from($prof['name'])->label();
                                            } catch (\ValueError $e) {
                                                $badgeLabel = $prof['name'];
                                            }
                                        @endphp
                                        <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-xs font-medium bg-primary-container text-on-primary-container">
                                            <span class="material-symbols-outlined text-xs" aria-hidden="true">verified</span>
                                            {{ $badgeLabel }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>

            {{-- ── Pagination ─────────────────────────────────────── --}}
            <div class="mt-8">
                {{ $results->links() }}
            </div>
        @else
            {{-- ── Empty State ────────────────────────────────────── --}}
            <div class="text-center py-16">
                <span class="material-symbols-outlined text-5xl text-on-surface-variant/40 mb-3 block">person_search</span>
                <h2 class="text-lg font-heading font-semibold text-on-surface-variant">{{ __('gms.content_no_gms_found') }}</h2>
                <p class="text-sm text-on-surface-variant/70 mt-1">{{ __('gms.content_try_adjusting_filters') }}</p>
                @if($this->hasActiveFilters())
                    <button wire:click="clearFilters"
                            class="mt-4 px-5 py-2 bg-primary text-on-primary rounded-xl text-sm font-medium hover:shadow-md active:scale-95 transition-all">
                        {{ __('gms.action_clear_filters') }}
                    </button>
                @endif
            </div>
        @endif
    </div>
</div>

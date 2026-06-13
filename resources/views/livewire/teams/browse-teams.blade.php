<div class="py-8">
    <div class="max-w-6xl mx-auto space-y-6">
        {{-- Page Header --}}
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('teams.action_browse_teams') }}</h1>
                <p class="text-sm text-on-surface-variant mt-1">{{ __('teams.action_discover_and_join_teams_in_your_area') }}</p>
            </div>
            @auth
                <a href="{{ route('teams.create') }}" wire:navigate
                   class="inline-flex items-center gap-1.5 px-4 py-2 bg-primary text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-95 transition-all text-sm font-medium">
                    <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">add</span>
                    {{ __('teams.action_create_team') }}
                </a>
            @endauth
        </div>

        {{-- Search & Sort --}}
        <div class="flex flex-col sm:flex-row gap-3">
            <div class="flex-1 relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-lg text-on-surface-variant">search</span>
                <input type="text" aria-label="Search teams" wire:model.live.debounce.300ms="search" placeholder="{{ __('discovery.action_search_by_name_city_or_country') }}"
                       class="w-full pl-10 rounded-md bg-surface-container-high border border-transparent shadow-xs focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant" />
            </div>
            <select wire:model.live="sort" aria-label="Sort teams"
                    class="rounded-md bg-surface-container-high border border-transparent shadow-xs focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface">
                <option value="newest">{{ __('common.content_newest') }}</option>
                <option value="name">{{ __('common.field_name_a_z') }}</option>
                <option value="members">{{ __('teams.content_most_members') }}</option>
            </select>
        </div>

        {{-- Team Grid --}}
        @if($teams->count())
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($teams as $team)
                    <a href="{{ route('teams.detail', $team->slug) }}" wire:navigate class="block bg-surface-container-lowest rounded-xl shadow-ambient hover:shadow-elevated transition-shadow overflow-hidden group">
                        {{-- Color banner --}}
                        <div class="h-2" style="background-color: {{ $team->primary_color ?: '#B8860B' }}"></div>

                        <div class="p-5">
                            <div class="flex items-start justify-between mb-2">
                                <h3 class="font-heading font-semibold text-lg text-on-surface tracking-tight group-hover:text-primary transition-colors">
                                    {{ $team->name }}
                                </h3>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container-high text-on-surface-variant">
                                    {{ trans_choice('teams.content_count_members', $team->active_members_count ?? 0) }}
                                </span>
                            </div>

                            @if($team->city || $team->country)
                                <p class="text-sm text-on-surface-variant flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm">location_on</span>
                                    {{ collect([$team->city, $team->country])->filter()->join(', ') }}
                                </p>
                            @endif

                            @if($team->description)
                                <p class="mt-2 text-sm text-on-surface-variant line-clamp-2">{{ Str::limit($team->description, 120) }}</p>
                            @endif

                            @if($team->founded_year)
                                <p class="mt-2 text-xs text-on-surface-variant/70">{{ __('common.content_est') }} {{ $team->founded_year }}</p>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $teams->links() }}
            </div>
        @else
            <div class="text-center py-16 bg-surface-container-lowest rounded-xl">
                <span class="material-symbols-outlined text-4xl text-on-surface-variant/50">groups</span>
                <h3 class="mt-2 text-sm font-medium text-on-surface">{{ __('teams.content_no_teams_found') }}</h3>
                <p class="mt-1 text-sm text-on-surface-variant">
                    @if($search)
                        {{ __('discovery.action_try_adjusting_your_search_terms') }}
                    @else
                        {{ __('teams.content_be_the_first_to_create_a_team') }}
                    @endif
                </p>
            </div>
        @endif
    </div>
</div>

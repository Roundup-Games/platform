<div>
    <x-hero title="{{ __('Discover Games & Campaigns') }}" :subtitle="__('Find games and campaigns that match your vibe.')" />

    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 space-y-6">

        {{-- ── Recommended for You (logged-in users only) ────────── --}}
        @auth
            @if($recommendations)
                <section class="space-y-3">
                    <h2 class="text-lg font-heading font-semibold text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary" aria-hidden="true">auto_awesome</span>
                        {{ __('Recommended for You') }}
                    </h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        @foreach($recommendations as $item)
                            @if($item->discoverable_type === 'game')
                                @include('livewire.discovery.partials.game-card', ['game' => $item])
                            @else
                                @include('livewire.discovery.partials.campaign-card', ['campaign' => $item])
                            @endif
                        @endforeach
                    </div>
                    <hr class="border-outline-variant/30 mt-4" />
                </section>
            @endif
        @endauth

        {{-- ── Mode Tabs ─────────────────────────────────────────── --}}
        <div class="flex items-center gap-1 bg-surface-container-high rounded-full p-1 w-fit">
            <button wire:click="setMode('all')"
                    class="px-4 py-1.5 rounded-full text-sm font-medium transition-colors {{ $mode === 'all' ? 'bg-primary text-on-primary shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' }}">
                {{ __('All') }}
            </button>
            <button wire:click="setMode('games')"
                    class="px-4 py-1.5 rounded-full text-sm font-medium transition-colors {{ $mode === 'games' ? 'bg-primary text-on-primary shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' }}">
                {{ __('Games') }}
            </button>
            <button wire:click="setMode('campaigns')"
                    class="px-4 py-1.5 rounded-full text-sm font-medium transition-colors {{ $mode === 'campaigns' ? 'bg-primary text-on-primary shadow-sm' : 'text-on-surface-variant hover:bg-surface-container' }}">
                {{ __('Campaigns') }}
            </button>
        </div>

        {{-- ── Search & Primary Filters ──────────────────────────── --}}
        <div class="flex flex-col sm:flex-row gap-3">
            <div class="flex-1 relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-lg" aria-hidden="true">search</span>
                <input type="text" aria-label="{{ __('Search') }}" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search games and campaigns...') }}"
                       class="w-full pl-10 bg-surface-container-high border border-transparent rounded-full text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
            </div>
            <select wire:model.live="game_system_id" aria-label="{{ __('Filter by game system') }}"
                    class="bg-surface-container-high border border-transparent rounded-lg text-on-surface shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                <option value="">{{ __('All Systems') }}</option>
                @foreach($gameSystems as $system)
                    <option value="{{ $system->id }}">{{ $system->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="price" aria-label="{{ __('Filter by price') }}"
                    class="bg-surface-container-high border border-transparent rounded-lg text-on-surface shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                <option value="">{{ __('Any Price') }}</option>
                <option value="free">{{ __('Free') }}</option>
                <option value="paid">{{ __('Paid') }}</option>
            </select>
        </div>

        {{-- ── Type-Specific Filter Row ───────────────────────────── --}}
        <div class="flex flex-wrap gap-3">
            @if($mode === 'all' || $mode === 'games')
                <select wire:model.live="date" aria-label="{{ __('Filter by date') }}"
                        class="bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                    <option value="">{{ __('Any Date') }}</option>
                    <option value="upcoming">{{ __('Upcoming') }}</option>
                    <option value="this_week">{{ __('This Week') }}</option>
                    <option value="this_month">{{ __('This Month') }}</option>
                </select>
            @endif

            @if($mode === 'all' || $mode === 'campaigns')
                <select wire:model.live="recurrence" aria-label="{{ __('Filter by recurrence') }}"
                        class="bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                    <option value="">{{ __('Any Recurrence') }}</option>
                    @foreach($recurrenceOptions as $option)
                        <option value="{{ $option }}">{{ __(ucfirst(str_replace('-', ' ', $option))) }}</option>
                    @endforeach
                </select>
            @endif

            <select wire:model.live="experience_level" aria-label="{{ __('Filter by experience level') }}"
                    class="bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                <option value="">{{ __('All Levels') }}</option>
                @foreach($experienceLevels as $level)
                    <option value="{{ $level->value }}">{{ $level->label() }}</option>
                @endforeach
            </select>

            <select wire:model.live="language" aria-label="{{ __('Filter by language') }}"
                    class="bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                <option value="">{{ __('All Languages') }}</option>
                @foreach($languages as $lang)
                    <option value="{{ $lang->value }}">{{ $lang->label() }}</option>
                @endforeach
            </select>
        </div>

        {{-- ── Vibe Flag Filters (grouped) ────────────────────────── --}}
        @if($vibeFlagGroups)
            <div class="space-y-3">
                @foreach($vibeFlagGroups as $groupKey => $group)
                    <div>
                        <p class="text-xs font-medium text-on-surface-variant mb-1.5">{{ $group['label'] }}</p>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($group['options'] as $flagValue => $flagLabel)
                                <button
                                    wire:click="toggleVibeFlag('{{ $flagValue }}')"
                                    class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium transition-colors
                                        {{ in_array($flagValue, $vibe_flags) ? 'bg-primary/15 text-primary ring-1 ring-primary/30' : 'bg-surface-container-high text-on-surface-variant hover:bg-surface-container' }}"
                                >
                                    {{ $flagLabel }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- ── Complexity Range ────────────────────────────────────── --}}
        <div class="flex items-center gap-3">
            <span class="text-sm text-on-surface-variant">{{ __('Complexity:') }}</span>
            <input type="number" min="1" max="5" step="0.5" wire:model.live="complexity_min" placeholder="{{ __('Min') }}" aria-label="{{ __('Minimum complexity') }}"
                   class="w-20 bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm text-center shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20" />
            <span class="text-on-surface-variant">–</span>
            <input type="number" min="1" max="5" step="0.5" wire:model.live="complexity_max" placeholder="{{ __('Max') }}" aria-label="{{ __('Maximum complexity') }}"
                   class="w-20 bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm text-center shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20" />
        </div>

        {{-- ── Active Filters ──────────────────────────────────────── --}}
        @if($search || $game_system_id || $experience_level || !empty($vibe_flags) || $language || $date || $recurrence || $price || $complexity_min || $complexity_max)
            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-sm text-on-surface-variant">{{ __('Filters:') }}</span>
                @if($search)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface">
                        "{{ $search }}"
                    </span>
                @endif
                @if($game_system_id)
                    @php($systemName = $gameSystems->firstWhere('id', $game_system_id)?->name)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                        {{ $systemName }}
                    </span>
                @endif
                @if($experience_level)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                        {{ App\Enums\ExperienceLevel::tryFrom($experience_level)?->label() ?? $experience_level }}
                    </span>
                @endif
                @foreach($vibe_flags as $flag)
                    @php($flagEnum = App\Enums\VibeFlag::tryFrom($flag))
                    @if($flagEnum)
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-tertiary/10 text-on-tertiary-container">
                            {{ $flagEnum->label() }}
                        </span>
                    @endif
                @endforeach
                @if($language)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                        {{ App\Enums\ContentLanguage::tryFrom($language)?->label() ?? $language }}
                    </span>
                @endif
                @if($date)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface-variant">
                        {{ __(ucfirst(str_replace('_', ' ', $date))) }}
                    </span>
                @endif
                @if($recurrence)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface-variant">
                        {{ __(ucfirst(str_replace('-', ' ', $recurrence))) }}
                    </span>
                @endif
                @if($price)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                        {{ __(ucfirst($price)) }}
                    </span>
                @endif
                @if($complexity_min || $complexity_max)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface-variant">
                        {{ $complexity_min ?? '1' }}–{{ $complexity_max ?? '5' }}
                    </span>
                @endif
                <button wire:click="clearFilters" class="text-xs text-primary hover:underline">{{ __('Clear all') }}</button>
            </div>
        @endif

        {{-- ── Results Grid ────────────────────────────────────────── --}}
        @if($results->count())
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($results as $item)
                    @if($item->discoverable_type === 'game')
                        @include('livewire.discovery.partials.game-card', ['game' => $item])
                    @else
                        @include('livewire.discovery.partials.campaign-card', ['campaign' => $item])
                    @endif
                @endforeach
            </div>

            <div class="mt-6">
                {{ $results->links() }}
            </div>
        @else
            <div class="text-center py-16 bg-surface rounded-xl shadow-ambient">
                <span class="material-symbols-outlined text-5xl text-on-surface-variant/40" aria-hidden="true">explore</span>
                <h3 class="mt-2 text-sm font-medium text-on-surface">{{ __('No results found') }}</h3>
                <p class="mt-1 text-sm text-on-surface-variant">
                    @if($search || $game_system_id || $experience_level || !empty($vibe_flags) || $language || $date || $recurrence || $price || $complexity_min || $complexity_max)
                        {{ __('Try adjusting your filters.') }}
                    @else
                        {{ __('Check back soon for new games and campaigns!') }}
                    @endif
                </p>
            </div>
        @endif
    </div>
</div>

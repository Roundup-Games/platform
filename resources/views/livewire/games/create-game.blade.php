<div class="py-6 sm:py-8">
    <div class="max-w-2xl mx-auto">
        {{-- Page Header --}}
        @php($hostingEvent = $this->hostingEvent)
        <div class="mb-6 sm:mb-8">
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ $hostingEvent ? route('events.manage', ['slug' => $hostingEvent->slug]) : route('dashboard') }}" wire:navigate class="text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">arrow_back</span>
                </a>
                <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">
                    {{ $hostingEvent ? __('events.action_host_a_table_at_event', ['event' => $hostingEvent->name]) : __('games.action_create_game_session') }}
                </h1>
            </div>
            <p class="ml-8 sm:ml-9 text-sm text-on-surface-variant">
                {{ $hostingEvent ? __('events.content_host_a_table_at_event_help') : __('games.content_schedule_a_new_game_session_for_players_to_join') }}
            </p>
        </div>

        {{-- Linked recreate (D318/D319): why this form is pre-filled. Rendered only while ?recreate=
             still names the cancelled game being republished, so it is gone once the replacement is saved. --}}
        @if($recreatingGame !== null)
            <div class="mb-6 rounded-xl bg-surface-container-high border border-outline-variant/40 p-4 flex items-start gap-3" role="status">
                <span class="material-symbols-outlined text-on-surface-variant shrink-0" aria-hidden="true">replay</span>
                <p class="text-sm text-on-surface">{{ __('games.content_recreating_from', ['name' => $recreatingGame->name]) }}</p>
            </div>
        @endif

        {{-- Step 1: Type Selector --}}
        @if($step === 'type')
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                <div class="flex items-center gap-2.5 mb-5">
                    <span class="material-symbols-outlined text-lg text-on-surface-variant" aria-hidden="true">category</span>
                    <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface">{{ __('games.content_what_are_you_playing') }}</h2>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    {{-- Board Game Card --}}
                    <button type="button" wire:click="selectType('board_game')"
                            class="group flex flex-col items-center gap-3 p-6 rounded-xl border-2 border-outline-variant/30 bg-surface-container-lowest hover:border-secondary/50 hover:bg-surface-container-high transition-all active:scale-[0.98] cursor-pointer text-center">
                        <span class="material-symbols-outlined text-4xl text-primary group-hover:scale-110 transition-transform" aria-hidden="true">casino</span>
                        <span class="text-base font-heading font-semibold text-on-surface">{{ __('games.type_board_game') }}</span>
                        <span class="text-xs text-on-surface-variant">{{ __('games.content_type_board_game_examples') }}</span>
                    </button>

                    {{-- TTRPG Card --}}
                    <button type="button" wire:click="selectType('ttrpg')"
                            class="group flex flex-col items-center gap-3 p-6 rounded-xl border-2 border-outline-variant/30 bg-surface-container-lowest hover:border-secondary/50 hover:bg-surface-container-high transition-all active:scale-[0.98] cursor-pointer text-center">
                        <span class="material-symbols-outlined text-4xl text-primary group-hover:scale-110 transition-transform" aria-hidden="true">auto_stories</span>
                        <span class="text-base font-heading font-semibold text-on-surface">{{ __('games.type_ttrpg') }}</span>
                        <span class="text-xs text-on-surface-variant">{{ __('games.content_type_ttrpg_examples') }}</span>
                    </button>

                    {{-- Gathering Card --}}
                    <button type="button" wire:click="selectType('gathering')"
                            class="group flex flex-col items-center gap-3 p-6 rounded-xl border-2 border-outline-variant/30 bg-surface-container-lowest hover:border-secondary/50 hover:bg-surface-container-high transition-all active:scale-[0.98] cursor-pointer text-center">
                        <span class="material-symbols-outlined text-4xl text-primary group-hover:scale-110 transition-transform" aria-hidden="true">groups</span>
                        <span class="text-base font-heading font-semibold text-on-surface">{{ __('games.type_gathering') }}</span>
                        <span class="text-xs text-on-surface-variant">{{ __('games.content_type_gathering_examples') }}</span>
                    </button>
                </div>
            </section>
        @endif

        {{-- Step 2: Game Form --}}
        @if($step === 'form')
            {{-- Type Switcher --}}
            <div class="flex items-center gap-2 mb-6">
                @php($typeIcons = ['board_game' => 'casino', 'ttrpg' => 'auto_stories', 'gathering' => 'groups'])
                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-surface-container-high text-sm">
                    <span class="material-symbols-outlined text-base text-primary" aria-hidden="true">{{ $typeIcons[$game_type ?? 'board_game'] ?? 'casino' }}</span>
                    <span class="font-medium text-on-surface">{{ $this->gameTypeOptions[$game_type ?? 'board_game'] ?? '' }}</span>
                </div>
                @if($hostingEvent)
                    {{-- Host-a-table context chip: keeps the event visible mid-form --}}
                    <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-secondary-container text-on-secondary-container text-sm">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">festival</span>
                        <span class="font-medium truncate max-w-[12rem]">{{ $hostingEvent->name }}</span>
                    </div>
                @endif
                <div class="flex items-center gap-1">
                    @foreach(['board_game', 'ttrpg', 'gathering'] as $switchType)
                        <button type="button"
                                @if($game_type === $switchType) disabled aria-current="true" @else wire:click="changeType('{{ $switchType }}')" @endif
                                @class([
                                    'px-2 py-1 rounded-md text-xs font-medium transition-colors',
                                    'text-primary bg-primary-container/40' => $game_type === $switchType,
                                    'text-on-surface-variant hover:text-primary' => $game_type !== $switchType,
                                ])>
                            {{ $this->gameTypeOptions[$switchType] }}
                        </button>
                    @endforeach
                </div>
                @error('game_type') <p class="ml-2 text-sm text-error">{{ $message }}</p> @enderror
            </div>

            <form wire:submit="save" class="space-y-6" id="game-form" tabindex="-1">

                {{-- Every field below lives in partials/form/ and is included with NO argument array, so each
                     partial reads this view's scope ($game_type, the BuildsSessionForm properties, $errors,
                     $isGM). The detail-page edit mode renders the same partials (D315): add a field there,
                     never here. Only the section chrome and the create-only controls stay in this view. --}}

                {{-- Section 1: Game Details --}}
                <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                    <x-form-section-header :number="1" :icon="'edit_note'" :title="__('games.heading_game_details')" />

                    <div class="space-y-4">
                        @include('livewire.games.partials.form._identity')

                        @include('livewire.games.partials.form._basics')
                    </div>
                </section>

                {{-- Section 2: Players & Duration --}}
                <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                    <x-form-section-header :number="2" :icon="'group'" :title="__('location.field_players_capacity')" />

                    <div class="space-y-4">
                        @include('livewire.games.partials.form._table')
                    </div>
                </section>

                {{-- Section 3: Location --}}
                <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                    <x-form-section-header :number="3" :icon="'location_on'" :title="__('common.field_location')" />

                    <div>
                        @include('livewire.games.partials.form._venue')
                    </div>
                </section>

                {{-- Section 4: Session Meta (TYPE-CONDITIONAL) --}}
                <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                    <x-form-section-header :number="4" :icon="'tune'" :title="__('campaigns.content_discovery_session_meta')" />

                    <div class="space-y-5">
                        @include('livewire.games.partials.form._hosting')
                    </div>
                </section>

                {{-- Section 5: Visibility --}}
                <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                    <x-form-section-header :number="5" :icon="'visibility'" :title="__('common.content_visibility')" />

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        @include('livewire.games.partials.form._settings')
                    </div>
                </section>

                {{-- Actions --}}
                <div class="flex items-center gap-4 pt-2">
                    <button type="submit" wire:loading.attr="disabled" wire:loading.attr="aria-busy=true"
                            class="inline-flex items-center gap-2 px-6 py-2.5 bg-primary text-on-primary rounded-lg hover:brightness-110 active:scale-[0.96] transition-all text-sm font-medium shadow-ambient whitespace-nowrap">
                        {{-- Stable label + icon swap: the label element is never removed by wire:loading,
                            so the submit trigger stays in the DOM across the request and Livewire's
                            navigate redirect fires reliably (see apply-to-game fix, M054). --}}
                        <span class="inline-flex items-center gap-2">
                            <span class="material-symbols-outlined text-base" wire:loading.remove wire:target="save" aria-hidden="true">add_circle</span>
                            <span class="material-symbols-outlined text-base animate-spin" wire:loading wire:target="save" aria-hidden="true" role="status" aria-label="{{ __('common.content_creating') }}">progress_activity</span>
                            {{ __('games.action_create_game') }}
                        </span>
                    </button>
                    <a href="{{ route('dashboard') }}" wire:navigate
                       class="px-4 py-2.5 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                        {{ __('common.action_cancel') }}
                    </a>
                </div>
            </form>
        @endif
    </div>
</div>

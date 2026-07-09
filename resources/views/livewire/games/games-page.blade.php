<div class="py-8">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 space-y-10">

        {{-- Page Header --}}
        <div>
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('dashboard') }}" wire:navigate class="text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">arrow_back</span>
                </a>
                <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('games.content_games') }}</h1>
            </div>
        </div>

        {{-- Flash Messages --}}
        @if(session('success'))
            <div class="rounded-lg bg-secondary-container text-on-secondary-container px-4 py-3 text-sm flex items-center gap-2">
                <span class="material-symbols-outlined text-base" aria-hidden="true">check_circle</span>
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="rounded-lg bg-error-container text-on-error-container px-4 py-3 text-sm flex items-center gap-2">
                <span class="material-symbols-outlined text-base" aria-hidden="true">error</span>
                {{ session('error') }}
            </div>
        @endif

        @php
            $priorityColors = [
                'critical' => 'bg-error',
                'high' => 'bg-warning',
                'medium' => 'bg-primary',
                'low' => 'bg-on-surface-variant/40',
            ];
        @endphp

        {{-- ═══ Empty state (no games at all) ═══ --}}
        @if(! $hasAnyGames)
            <section class="bg-surface-container-low rounded-xl p-8 sm:p-12 text-center">
                <span class="material-symbols-outlined text-5xl text-on-surface-variant mb-3 block" aria-hidden="true">stadium</span>
                <h2 class="text-lg font-heading font-semibold text-on-surface">{{ __('games.content_empty_no_games_title') }}</h2>
                <p class="text-on-surface-variant text-sm mt-1 max-w-md mx-auto">{{ __('games.content_empty_no_games_body') }}</p>
                <div class="mt-5 flex flex-wrap justify-center gap-3">
                    <a href="{{ route('discover') }}" wire:navigate
                       class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-sm font-semibold bg-primary text-on-primary shadow-xs hover:opacity-90 active:scale-[0.98] transition ease-in-out duration-150 whitespace-nowrap">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">explore</span>
                        {{ __('games.action_empty_discover') }}
                    </a>
                    <a href="{{ route('games.create') }}" wire:navigate
                       class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-sm font-semibold border border-outline text-on-surface hover:bg-surface-container-lowest transition-colors whitespace-nowrap">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">add</span>
                        {{ __('games.action_empty_plan') }}
                    </a>
                </div>
            </section>
        @endif

        {{-- ═══ Needs your attention ═══ --}}
        @if(count($needsAttention) > 0)
            <section>
                <div class="flex items-center justify-between mb-1">
                    <h2 class="text-xl font-heading font-semibold text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary" aria-hidden="true" style="font-variation-settings: 'FILL' 1">recommend</span>
                        {{ __('games.heading_needs_your_attention') }}
                    </h2>
                    <span class="inline-flex items-center justify-center min-w-[24px] h-6 px-2 rounded-full bg-primary text-on-primary text-xs font-semibold">
                        {{ count($needsAttention) }}
                    </span>
                </div>
                <p class="text-xs text-on-surface-variant mb-3 ml-8">{{ __('games.content_needs_attention_hint') }}</p>
                <ul class="space-y-2" role="list">
                    @foreach($needsAttention as $item)
                        @php
                            $dotColor = $priorityColors[$item->priority] ?? 'bg-on-surface-variant/40';
                        @endphp
                        <li>
                            <a href="{{ $item->actionUrl }}" wire:navigate
                               class="flex items-start gap-3 p-3 rounded-lg bg-surface-container-lowest hover:bg-surface-container-low transition-colors group">
                                <span class="w-2.5 h-2.5 rounded-full {{ $dotColor }} mt-2 shrink-0" aria-label="{{ $item->priority }} priority"></span>
                                <span aria-hidden="true" class="material-symbols-outlined text-on-surface-variant text-xl mt-0.5 shrink-0" style="font-variation-settings: 'FILL' 0">{{ $item->icon }}</span>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-on-surface group-hover:text-primary transition-colors leading-snug">{{ $item->title }}</p>
                                    <p class="text-xs text-on-surface-variant mt-0.5 line-clamp-2 leading-relaxed">{{ $item->description }}</p>
                                </div>
                                <span aria-hidden="true" class="material-symbols-outlined text-on-surface-variant text-lg mt-1 shrink-0 group-hover:text-primary transition-colors" style="font-variation-settings: 'FILL' 0">chevron_right</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- ═══ Upcoming — hosting ═══ --}}
        @if($upcomingHosting->isNotEmpty())
            <section>
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-heading font-semibold text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">castle</span>
                        {{ __('games.heading_upcoming_hosting') }}
                    </h2>
                    <a href="{{ route('games.create') }}" wire:navigate
                       class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary text-on-primary text-sm font-semibold shadow-xs hover:opacity-90 active:scale-[0.98] transition ease-in-out duration-150 whitespace-nowrap">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">add</span>
                        {{ __('common.action_create') }}
                    </a>
                </div>
                <div class="space-y-3">
                    @foreach($upcomingHosting as $game)
                        @include('livewire.games._game-card', ['game' => $game, 'asHost' => true])
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ═══ Upcoming — playing ═══ --}}
        @if($upcomingPlaying->isNotEmpty())
            <section>
                <h2 class="text-xl font-heading font-semibold text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">groups</span>
                    {{ __('games.heading_upcoming_playing') }}
                </h2>
                <div class="space-y-3">
                    @foreach($upcomingPlaying as $game)
                        @include('livewire.games._game-card', ['game' => $game, 'asHost' => false])
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ═══ Open invitations ═══ --}}
        @if($pendingInvitations->isNotEmpty())
            <section>
                <h2 class="text-xl font-heading font-semibold text-on-surface mb-4">{{ __('games.heading_open_invitations') }}</h2>
                <div class="space-y-3">
                    @foreach($pendingInvitations as $invitation)
                        @php $game = $invitation->game; @endphp
                        @continue(!$game)
                        <div class="bg-surface-container-low rounded-xl shadow-ambient overflow-hidden border-l-4 border-primary">
                            <a href="{{ route('games.detail', ['locale' => app()->getLocale(), 'id' => $game]) }}" wire:navigate class="block p-4 sm:p-5 hover:bg-surface-container/50 transition-colors">
                                <div class="flex flex-wrap items-center gap-2 mb-2">
                                    <h3 class="text-base font-medium text-on-surface">{{ $game->name }}</h3>
                                    @foreach($game->gameSystems as $system)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container-high text-on-surface-variant">{{ $system->name }}</span>
                                    @endforeach
                                </div>
                                <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-on-surface-variant">
                                    @if($game->date_time)
                                        <span class="flex items-center gap-1">
                                            <span class="material-symbols-outlined text-sm" aria-hidden="true">calendar_today</span>
                                            {{ format_date($game->date_time, 'datetime') }}
                                        </span>
                                    @endif
                                    @if($game->owner)
                                        <span class="flex items-center gap-1">
                                            <span class="material-symbols-outlined text-sm" aria-hidden="true">person</span>
                                            {{ $game->owner->name }}
                                        </span>
                                    @endif
                                </div>
                            </a>
                            <div class="border-t border-outline-variant/20 px-4 sm:px-5 py-2.5 flex flex-wrap gap-1">
                                <button wire:click="acceptInvitation('{{ $invitation->id }}')"
                                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium text-secondary hover:bg-secondary/10 transition-colors"
                                        aria-label="{{ __('games.action_accept_invitation') }}">
                                    <span class="material-symbols-outlined text-base" aria-hidden="true">check</span>
                                    <span class="hidden sm:inline">{{ __('games.action_accept_invitation') }}</span>
                                </button>
                                <button wire:click="declineInvitation('{{ $invitation->id }}')"
                                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium text-error hover:bg-error/10 transition-colors"
                                        aria-label="{{ __('games.action_decline_invitation') }}">
                                    <span class="material-symbols-outlined text-base" aria-hidden="true">close</span>
                                    <span class="hidden sm:inline">{{ __('games.action_decline_invitation') }}</span>
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ═══ Recently completed ═══ --}}
        @if($recentCompleted->isNotEmpty())
            <section>
                <h2 class="text-xl font-heading font-semibold text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">history</span>
                    {{ __('games.heading_recently_completed') }}
                </h2>
                <div class="space-y-3">
                    @foreach($recentCompleted as $game)
                        @php
                            // In "recently completed", host context applies when the viewer owns it.
                            $cardAsHost = (string) $game->owner_id === (string) auth()->id();
                        @endphp
                        @include('livewire.games._game-card', ['game' => $game, 'asHost' => $cardAsHost])
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ═══ Archive (collapsible) ═══ --}}
        @if($archiveGames->isNotEmpty())
            <section x-data="{ open: false }">
                <button type="button" x-on:click="open = !open"
                        class="flex items-center gap-2 text-on-surface-variant hover:text-on-surface transition-colors mb-3"
                        :aria-expanded="open">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true" x-bind:style="open ? 'transform: rotate(90deg)' : ''">chevron_right</span>
                    <h2 class="text-base font-heading font-semibold">{{ __('games.heading_archive') }}</h2>
                    <span class="text-xs text-on-surface-variant">({{ $archiveGames->count() }})</span>
                </button>
                <div x-show="open"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 -translate-y-1"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 translate-y-0"
                     x-transition:leave-end="opacity-0 -translate-y-1"
                     class="space-y-3">
                    @foreach($archiveGames as $game)
                        @php
                            $cardAsHost = (string) $game->owner_id === (string) auth()->id();
                        @endphp
                        @include('livewire.games._game-card', ['game' => $game, 'asHost' => $cardAsHost])
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ═══ Community Activity Feed ═══ --}}
        @include('livewire.partials.activity-feed', ['activityFeed' => $activityFeed, 'entityType' => 'game'])

        {{-- Edit Game Modal --}}
        @if($editingGameId)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" wire:click="cancelEdit">
                <div class="bg-surface-container-low rounded-2xl shadow-xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto" wire:click.stop>
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-lg font-heading font-semibold text-on-surface">{{ __('games.heading_edit_game') }}</h2>
                            <button wire:click="cancelEdit" class="text-on-surface-variant hover:text-on-surface transition-colors">
                                <span class="material-symbols-outlined" aria-hidden="true">close</span>
                            </button>
                        </div>

                        <form wire:submit="saveGameEdit" class="space-y-4">
                            <div>
                                <label for="edit-game-name" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_name') }}</label>
                                <input type="text" id="edit-game-name" wire:model="edit_name"
                                       class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                                @error('edit_name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="edit-game-description" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_description') }}</label>
                                <textarea id="edit-game-description" wire:model="edit_description" rows="3"
                                          class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors"></textarea>
                                @error('edit_description') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label for="edit-game-duration" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_duration') }}</label>
                                    <input type="number" id="edit-game-duration" wire:model="edit_expected_duration" step="0.5" min="0.5" max="24"
                                           class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                                    @error('edit_expected_duration') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="edit-game-visibility" class="block text-sm font-medium text-on-surface mb-1">{{ __('campaigns.field_visibility') }}</label>
                                    <select id="edit-game-visibility" wire:model="edit_visibility"
                                            class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors">
                                        <option value="public">{{ __('common.content_public') }}</option>
                                        <option value="protected">{{ __('common.content_protected') }}</option>
                                        <option value="private">{{ __('common.content_private') }}</option>
                                    </select>
                                    @error('edit_visibility') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_location') }}</label>

                                {{-- Compact venue picker for edit modal --}}
                                @if($edit_location_id)
                                    <div class="flex items-center gap-2 p-2.5 rounded-lg bg-surface-container-high">
                                        <span class="material-symbols-outlined text-lg text-primary" style="font-variation-settings: 'FILL' 1" aria-hidden="true">pin_drop</span>
                                        <div class="flex-1 min-w-0">
                                            <div class="text-sm font-medium text-on-surface truncate">{{ $edit_location_name }}</div>
                                            @if($edit_location_city)
                                                <div class="text-xs text-on-surface-variant truncate">{{ $edit_location_city }}{{ $edit_location_address ? ', ' . $edit_location_address : '' }}</div>
                                                @endif
                                        </div>
                                        <button type="button" wire:click="editClearLocation"
                                                class="p-1 rounded-sm hover:bg-surface-container-high/80 text-on-surface-variant hover:text-error transition-colors"
                                                aria-label="{{ __('common.action_remove') }}">
                                            <span class="material-symbols-outlined text-base" aria-hidden="true">close</span>
                                        </button>
                                    </div>
                                @else
                                    {{-- Mode tabs --}}
                                    <div class="flex gap-1 mb-2">
                                        <button type="button" wire:click="editSetAddressMode('venue')"
                                                class="px-3 py-1 text-xs font-medium rounded-lg transition-colors {{ $edit_address_mode === 'venue' ? 'bg-secondary/20 text-secondary' : 'text-on-surface-variant hover:bg-surface-container-high' }}">
                                            <span class="material-symbols-outlined text-xs align-middle mr-0.5" aria-hidden="true">store</span>
                                            {{ __('venues.label_venue') }}
                                        </button>
                                        <button type="button" wire:click="editSetAddressMode('address')"
                                                class="px-3 py-1 text-xs font-medium rounded-lg transition-colors {{ $edit_address_mode === 'address' ? 'bg-secondary/20 text-secondary' : 'text-on-surface-variant hover:bg-surface-container-high' }}">
                                            <span class="material-symbols-outlined text-xs align-middle mr-0.5" aria-hidden="true">edit_location</span>
                                            {{ __('venues.label_address') }}
                                        </button>
                                    </div>

                                    @if($edit_address_mode === 'venue')
                                        {{-- Venue search --}}
                                        <div class="flex gap-2">
                                            <input type="text" wire:model="edit_venue_query" placeholder="{{ __('venues.placeholder_search_venues') }}"
                                                   class="flex-1 rounded-lg bg-surface-container-high border border-transparent text-on-surface text-sm placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors"
                                                   wire:keydown.enter="editSearchVenues" />
                                            <button type="button" wire:click="editSearchVenues"
                                                    class="px-3 py-1.5 bg-secondary/10 text-secondary rounded-lg text-sm font-medium hover:bg-secondary/20 transition-colors">
                                                <span class="material-symbols-outlined text-sm align-middle" aria-hidden="true">search</span>
                                            </button>
                                        </div>
                                        @if($edit_venue_searched && count($edit_venue_results) > 0)
                                            <div class="mt-2 max-h-36 overflow-y-auto rounded-lg border border-outline-variant/30 divide-y divide-outline-variant/20">
                                                @foreach($edit_venue_results as $v)
                                                    <button type="button" wire:click="editSelectVenue('{{ $v['id'] }}')"
                                                            class="w-full text-left px-3 py-2 hover:bg-surface-container-high transition-colors">
                                                        <div class="text-sm font-medium text-on-surface truncate">{{ $v['name'] }}</div>
                                                        <div class="text-xs text-on-surface-variant truncate">{{ $v['city'] ?? '' }}{{ ($v['address'] ?? '') ? ', ' . $v['address'] : '' }}</div>
                                                    </button>
                                                @endforeach
                                            </div>
                                        @elseif($edit_venue_searched)
                                            <p class="mt-1 text-xs text-on-surface-variant">{{ __('venues.content_no_venues_found_edit') }}</p>
                                        @endif
                                    @else
                                        {{-- Address input --}}
                                        <div class="space-y-2">
                                            <input type="text" wire:model="edit_address_city" placeholder="{{ __('location.placeholder_city') }} *"
                                                   class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface text-sm placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                                            @error('edit_address_city') <p class="text-xs text-error">{{ $message }}</p> @enderror
                                            <input type="text" wire:model="edit_address_street" placeholder="{{ __('location.placeholder_street_address_neighborhood') }}"
                                                   class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface text-sm placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                                            <button type="button" wire:click="editSaveAddress"
                                                    class="px-3 py-1.5 bg-primary text-on-primary rounded-lg text-sm font-medium hover:brightness-110 active:scale-95 transition-all">
                                                {{ __('venues.action_save_address') }}
                                            </button>
                                        </div>
                                    @endif
                                @endif

                                {{-- Instructions --}}
                                @if($edit_location_id)
                                    <input type="text" wire:model="edit_location_instructions" placeholder="{{ __('venues.placeholder_instructions') }}"
                                           class="mt-2 w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface text-sm placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                                @endif
                            </div>

                            <div class="flex justify-end gap-3 pt-2">
                                <button type="button" wire:click="cancelEdit"
                                        class="px-4 py-2 rounded-lg text-sm font-medium text-on-surface-variant hover:bg-surface-container-high transition-colors">
                                    {{ __('common.action_cancel') }}
                                </button>
                                <button type="submit"
                                        class="px-4 py-2 rounded-lg text-sm font-medium bg-secondary text-on-secondary hover:bg-secondary/90 transition-colors">
                                    {{ __('common.action_save_changes') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif

    </div>
</div>

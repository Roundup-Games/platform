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

        {{-- My Games Section --}}
        <section>
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-heading font-semibold text-on-surface">{{ __('games.heading_my_games') }}</h2>
                <a href="{{ route('games.create') }}" wire:navigate
                   class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-secondary text-on-secondary text-sm font-medium hover:bg-secondary/90 transition-colors">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">add</span>
                    {{ __('games.action_create_game') }}
                </a>
            </div>

            @if($ownedGames->isEmpty())
                <div class="bg-surface-container-low rounded-xl p-8 text-center">
                    <span class="material-symbols-outlined text-4xl text-on-surface-variant mb-2 block" aria-hidden="true">sports_esports</span>
                    <p class="text-on-surface-variant text-sm">{{ __('games.content_no_owned_games') }}</p>
                    <a href="{{ route('games.create') }}" wire:navigate
                       class="mt-3 inline-flex items-center gap-1 text-sm text-secondary hover:text-secondary/80 transition-colors">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">add</span>
                        {{ __('games.action_create_game') }}
                    </a>
                </div>
            @else
                <div class="space-y-3">
                    @foreach($ownedGames as $game)
                        <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center gap-4">
                            {{-- Game Info --}}
                            <div class="flex-1 min-w-0">
                                <div class="flex flex-wrap items-center gap-2 mb-1">
                                    <h3 class="text-base font-medium text-on-surface truncate">
                                        <a href="{{ route('games.detail', $game->id) }}" wire:navigate class="hover:text-secondary transition-colors">
                                            {{ $game->name }}
                                        </a>
                                    </h3>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                        {{ $game->status === 'scheduled' ? 'bg-primary-container text-on-primary-container' : ($game->status === 'completed' ? 'bg-secondary-container text-on-secondary-container' : 'bg-error-container text-on-error-container') }}">
                                        {{ __('games.status_' . $game->status) }}
                                    </span>
                                    @if($game->gameSystem)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container-high text-on-surface-variant">
                                            {{ $game->gameSystem->name }}
                                        </span>
                                    @endif
                                </div>
                                <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-on-surface-variant">
                                    @if($game->date_time)
                                        <span class="flex items-center gap-1">
                                            <span class="material-symbols-outlined text-sm" aria-hidden="true">calendar_today</span>
                                            {{ format_date($game->date_time, 'datetime') }}
                                        </span>
                                    @endif
                                    <span class="flex items-center gap-1">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">group</span>
                                        {{ $game->participants->count() }}/{{ $game->max_players ?? '∞' }}
                                    </span>
                                </div>
                            </div>

                            {{-- Actions --}}
                            <div class="flex items-center gap-2 shrink-0">
                                @if($game->status === 'scheduled')
                                    <button wire:click="cancelGame('{{ $game->id }}')"
                                            wire:confirm="{{ __('games.confirm_cancel_game') }}"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium text-error hover:bg-error/10 transition-colors">
                                        <span class="material-symbols-outlined text-base" aria-hidden="true">cancel</span>
                                        {{ __('games.action_cancel_game') }}
                                    </button>
                                    <button wire:click="completeGame('{{ $game->id }}')"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium text-secondary hover:bg-secondary/10 transition-colors">
                                        <span class="material-symbols-outlined text-base" aria-hidden="true">check_circle</span>
                                        {{ __('games.action_complete_game') }}
                                    </button>
                                @endif
                                <a href="{{ route('games.detail', $game->id) }}" wire:navigate
                                   class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium text-on-surface-variant hover:bg-surface-container-high transition-colors">
                                    <span class="material-symbols-outlined text-base" aria-hidden="true">visibility</span>
                                    {{ __('games.action_view_game') }}
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Games I'm In Section --}}
        <section>
            <h2 class="text-xl font-heading font-semibold text-on-surface mb-4">{{ __('games.heading_games_im_in') }}</h2>

            @if($participatingGames->isEmpty())
                <div class="bg-surface-container-low rounded-xl p-8 text-center">
                    <span class="material-symbols-outlined text-4xl text-on-surface-variant mb-2 block" aria-hidden="true">group</span>
                    <p class="text-on-surface-variant text-sm">{{ __('games.content_no_games_joined') }}</p>
                </div>
            @else
                <div class="space-y-3">
                    @foreach($participatingGames as $game)
                        <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center gap-4">
                            {{-- Game Info --}}
                            <div class="flex-1 min-w-0">
                                <div class="flex flex-wrap items-center gap-2 mb-1">
                                    <h3 class="text-base font-medium text-on-surface truncate">
                                        <a href="{{ route('games.detail', $game->id) }}" wire:navigate class="hover:text-secondary transition-colors">
                                            {{ $game->name }}
                                        </a>
                                    </h3>
                                    @if($game->gameSystem)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container-high text-on-surface-variant">
                                            {{ $game->gameSystem->name }}
                                        </span>
                                    @endif
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
                                    <span class="flex items-center gap-1">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">group</span>
                                        {{ $game->participants->count() }}/{{ $game->max_players ?? '∞' }}
                                    </span>
                                </div>
                            </div>

                            {{-- Actions --}}
                            <div class="flex items-center gap-2 shrink-0">
                                <a href="{{ route('games.detail', $game->id) }}" wire:navigate
                                   class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium text-on-surface-variant hover:bg-surface-container-high transition-colors">
                                    <span class="material-symbols-outlined text-base" aria-hidden="true">visibility</span>
                                    {{ __('games.action_view_game') }}
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Open Invitations Section --}}
        @if($pendingInvitations->isNotEmpty())
        <section>
            <h2 class="text-xl font-heading font-semibold text-on-surface mb-4">{{ __('games.heading_open_invitations') }}</h2>

            <div class="space-y-3">
                @foreach($pendingInvitations as $invitation)
                    @php $game = $invitation->game; @endphp
                    <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center gap-4 border-l-4 border-primary">
                        {{-- Game Info --}}
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <h3 class="text-base font-medium text-on-surface truncate">
                                    {{ $game->name }}
                                </h3>
                                @if($game->gameSystem)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container-high text-on-surface-variant">
                                        {{ $game->gameSystem->name }}
                                    </span>
                                @endif
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
                        </div>

                        {{-- Actions --}}
                        <div class="flex items-center gap-2 shrink-0">
                            <button wire:click="acceptInvitation('{{ $invitation->id }}')"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium text-secondary hover:bg-secondary/10 transition-colors">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">check</span>
                                {{ __('games.action_accept_invitation') }}
                            </button>
                            <button wire:click="declineInvitation('{{ $invitation->id }}')"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium text-error hover:bg-error/10 transition-colors">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">close</span>
                                {{ __('games.action_decline_invitation') }}
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
        @endif


        {{-- Community Activity Feed --}}
        @include('livewire.partials.activity-feed', ['activityFeed' => $activityFeed, 'entityType' => 'game'])

        </section>

    </div>
</div>

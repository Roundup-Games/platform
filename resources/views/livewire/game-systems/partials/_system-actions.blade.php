        {{-- Flash message --}}
        @if(session()->has('status'))
            <div role="status" aria-live="polite" class="px-4 py-3 rounded-lg bg-primary/10 text-primary text-sm font-medium">
                {{ session('status') }}
            </div>
        @endif

        {{-- ── Preference & Discovery Bar ──────────────────────── --}}
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 p-4 bg-surface-container rounded-xl shadow-ambient">
            {{-- User preference toggle --}}
            <div class="flex items-center gap-3">
                @auth
                    <button wire:click="toggleFavorite"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors duration-150 {{ $this->userPreference === 'favorite' ? 'bg-primary text-on-primary' : 'bg-surface-container-high text-on-surface-variant hover:bg-primary/10 hover:text-primary' }}">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">{{ $this->userPreference === 'favorite' ? 'favorite' : 'favorite_border' }}</span>
                        {{ $this->userPreference === 'favorite' ? __('games.action_remove_from_favorites') : __('games.action_add_to_favorites') }}
                    </button>
                    <button wire:click="toggleAvoid"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors duration-150 {{ $this->userPreference === 'avoid' ? 'bg-error text-on-error' : 'bg-surface-container-high text-on-surface-variant hover:bg-error/10 hover:text-error' }}">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">{{ $this->userPreference === 'avoid' ? 'block' : 'block' }}</span>
                        {{ $this->userPreference === 'avoid' ? __('games.action_remove_from_avoid_list') : __('games.action_add_to_avoid_list') }}
                    </button>
                @else
                    <a href="{{ route('register') }}" wire:navigate class="inline-flex items-center gap-1.5 px-4 py-2 bg-primary text-on-primary rounded-lg text-sm font-semibold shadow-xs hover:shadow-md transition-shadow">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">person_add</span>
                        {{ __('games.guest_nudge_game_systems') }}
                    </a>
                @endauth
            </div>

            {{-- Community stats --}}
            <div class="flex items-center gap-4 text-sm text-on-surface-variant">
                @if($this->favoritedCount > 0)
                    <span class="flex items-center gap-1">
                        <span class="material-symbols-outlined text-base text-primary" aria-hidden="true">favorite</span>
                        {{ __('games.content_users_favorite_this', ['count' => $this->favoritedCount]) }}
                    </span>
                @endif
                @if($this->avoidedCount > 0)
                    <span class="flex items-center gap-1">
                        <span class="material-symbols-outlined text-base text-error" aria-hidden="true">block</span>
                        {{ __('games.content_users_avoid_this', ['count' => $this->avoidedCount]) }}
                    </span>
                @endif
            </div>
        </div>

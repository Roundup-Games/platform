{{-- Mobile sticky CTA --}}
@auth
    @if($canJoinViaShareLink)
        <div class="lg:hidden sticky bottom-0 z-30 bg-surface/95 backdrop-blur-md border-t border-outline-variant px-4 py-3">
            <button wire:click="joinViaShareLink"
               class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 bg-primary text-on-primary text-sm font-semibold rounded-xl shadow-lg hover:brightness-110 transition-all">
                <span class="material-symbols-outlined text-base" aria-hidden="true">login</span>
                @if($isGameFull)
                    {{ __('games.action_join_waitlist') }}
                @else
                    {{ __('games.action_join_game') }}
                @endif
            </button>
        </div>
    @elseif($canApply)
        <div class="lg:hidden sticky bottom-0 z-30 bg-surface/95 backdrop-blur-md border-t border-outline-variant px-4 py-3">
            <a href="{{ route('games.apply', ['locale' => app()->getLocale(), 'id' => $game->id]) }}" wire:navigate
               class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 bg-primary text-on-primary text-sm font-semibold rounded-xl shadow-lg hover:brightness-110 transition-all">
                <span class="material-symbols-outlined text-base" aria-hidden="true">
                    @if($game->visibility->value === 'public') login @else send @endif
                </span>
                @if($game->visibility->value === 'public')
                    {{ __('games.action_join_game') }}
                @else
                    {{ __('games.action_apply_to_join') }}
                @endif
            </a>
        </div>
    @elseif($canJoinWaitlist)
        <div class="lg:hidden sticky bottom-0 z-30 bg-surface/95 backdrop-blur-md border-t border-outline-variant px-4 py-3">
            <button wire:click="joinWaitlist"
               class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 bg-primary text-on-primary text-sm font-semibold rounded-xl shadow-lg hover:brightness-110 transition-all">
                <span class="material-symbols-outlined text-base" aria-hidden="true">playlist_add</span>
                {{ __('games.action_join_waitlist') }}
            </button>
        </div>
    @endif
@endauth

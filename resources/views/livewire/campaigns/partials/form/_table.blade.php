{{-- Player counts and the bench-mode switch (focused types only, GM-gated).
     Shared by the create form and the edit mode (D317): included with no argument array, so it
     reads the including view's scope — $game_type, $isGM, $bench_mode, $errors.
     The edit mode passes $lockedFields (field => true) in the include's data array; the create view
     passes nothing. max_players is never in the map: its tier depends on the direction of the
     change, so an increase stays available on a locked campaign (D313) and the service refuses a
     decrease. --}}
    @php($lockedFields ??= [])
    <div class="grid grid-cols-2 gap-4">
        <div>
            <label for="campaign-min-players" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_min_players') }}</label>
            <input type="number" id="campaign-min-players" wire:model.live.debounce.400ms="min_players" min="1" max="99" placeholder="2"
                   @disabled(isset($lockedFields['min_players']))
                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors{{ isset($lockedFields['min_players']) ? ' opacity-60 cursor-not-allowed' : '' }}" />
            @error('min_players') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="campaign-max-players" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_max_players') }}</label>
            <input type="number" id="campaign-max-players" wire:model.live.debounce.400ms="max_players" min="1" max="99" placeholder="{{ $game_type === 'gathering' ? '12' : '6' }}"
                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
            @if($game_type === 'gathering')
                <p class="mt-1 text-xs text-on-surface-variant/60">{{ __('games.content_max_players_venue_hint') }}</p>
            @endif
            @error('max_players') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
        </div>
    </div>

    {{-- Bench Mode Toggle (hidden for gatherings — not GM-gated matchmaking) --}}
    @if($game_type !== 'gathering')
        <div class="mt-2">
            <div class="flex items-center gap-3">
                <button type="button"
                        @if(!$isGM || isset($lockedFields['bench_mode'])) disabled @else wire:click="$toggle('bench_mode')" @endif
                        @class([
                            'relative inline-flex h-6 w-11 items-center rounded-full transition-colors shrink-0',
                            'bg-primary' => $bench_mode,
                            'bg-surface-container-highest' => !$bench_mode,
                            'opacity-50 cursor-not-allowed' => !$isGM || isset($lockedFields['bench_mode']),
                        ])
                        role="switch"
                        aria-label="{{ __('games.label_bench_mode') }}"
                        aria-checked="{{ $bench_mode ? 'true' : 'false' }}">
                    <span @class([
                        'inline-block h-4 w-4 transform rounded-full bg-white transition-transform shadow-xs',
                        'translate-x-6' => $bench_mode,
                        'translate-x-1' => !$bench_mode,
                    ])></span>
                </button>
                <div>
                    <span class="text-sm font-medium text-on-surface">{{ __('games.label_bench_mode') }}</span>
                    @if(!$isGM)
                        <p class="text-xs text-on-surface-variant">{{ __('common.content_bench_mode_requires_gm') }}</p>
                    @else
                        <p class="text-xs text-on-surface-variant">{{ __('games.hint_bench_mode') }}</p>
                    @endif
                </div>
            </div>
        </div>
    @endif

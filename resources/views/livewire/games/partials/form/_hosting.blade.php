{{-- Session meta: experience level and complexity for EVERY game type (D325), then the
     type-conditional blocks — vibe flags and safety tools (TTRPG), host note and vibe flags
     (gathering), vibe flags and comfort notes (board game) — plus the attendance tolerance for
     focused types.
     Shared by the create form and the edit mode (D315): included with no argument array, so it
     reads the including view's scope — $game_type, $vibePreferences, $safety_rules, $errors.
     The two descriptors are what the system picker autofills from the BGG weight, so they are
     on screen whatever the type: nothing is written that the form does not show (D325). The
     complexity step is the autofill's own precision (two decimals) — a coarser grid would make
     the browser refuse an autofilled value. Nothing here is Tier 1: experience_level,
     complexity, vibe_flags, host_note and the attendance tolerance are Tier 3, safety_rules is
     Tier 2 — so the edit mode never disables this section. --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label for="game-experience" class="block text-sm font-medium text-on-surface mb-1">{{ __('discovery.content_experience_level') }}</label>
            <select id="game-experience" wire:model="experience_level"
                    class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors">
                @foreach($this->experienceLevelOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            @error('experience_level') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="game-complexity" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.content_complexity_1_5') }}</label>
            <input type="number" id="game-complexity" wire:model="complexity" step="0.01" min="0" max="5" placeholder="2.0"
                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
            <p class="mt-1 text-xs text-on-surface-variant/60">{{ __('common.content_1_light_3_medium_5_heavy') }}</p>
            @error('complexity') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
        </div>
    </div>

    @if($game_type === 'ttrpg')
        {{-- TTRPG: Full form --}}
        <div>
            <label class="block text-sm font-medium text-on-surface mb-2">{{ __('common.content_vibe_flags') }}</label>
            <livewire:components.vibe-preference-picker :mode="'selection'" :gameType="'ttrpg'" :preferences="$vibePreferences" wire:key="game-vibe-picker-ttrpg" />
        </div>

        <div>
            <label class="block text-sm font-medium text-on-surface mb-2">{{ __('safety.content_safety_tools') }}</label>
            <livewire:components.safety-tool-picker mode="selection" :selected="isset($safety_rules['tools']) ? $safety_rules['tools'] : []" :linesAndVeilsText="$safety_rules['lines_and_veils_text'] ?? ''" :customNote="$safety_rules['custom_note'] ?? ''" wire:key="game-safety-picker-ttrpg" />
        </div>
    @elseif($game_type === 'gathering')
        {{-- Gathering: Warm, minimal form (host note + vibes) --}}
        <div>
            <label for="game-host-note" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_host_note') }}</label>
            <textarea id="game-host-note" wire:model="host_note" rows="3" placeholder="{{ __('games.placeholder_host_note') }}"
                      class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors"></textarea>
            <p class="mt-1 text-xs text-on-surface-variant/60">{{ __('games.hint_host_note') }}</p>
            @error('host_note') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-on-surface mb-2">{{ __('common.content_vibe_flags') }}</label>
            <livewire:components.vibe-preference-picker :mode="'selection'" :gameType="'gathering'" :preferences="$vibePreferences" wire:key="game-vibe-picker-gathering" />
        </div>
    @else
        {{-- Board Game: Simplified form --}}
        <div>
            <label class="block text-sm font-medium text-on-surface mb-2">{{ __('common.content_vibe_flags') }}</label>
            <livewire:components.vibe-preference-picker :mode="'selection'" :gameType="'board_game'" :preferences="$vibePreferences" wire:key="game-vibe-picker-board-game" />
        </div>

        <div>
            <label for="game-comfort-notes" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_comfort_notes') }}</label>
            <textarea id="game-comfort-notes" wire:model="comfort_notes" rows="2" placeholder="{{ __('games.placeholder_comfort_notes') }}"
                      class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors"></textarea>
            @error('comfort_notes') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
        </div>
    @endif

    @if($game_type !== 'gathering')
        <div>
            <label for="game-attendance" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_attendance_tolerance') }}</label>
            <select id="game-attendance" wire:model="min_reliability_preference"
                    class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors">
                @foreach($this->attendanceToleranceOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-on-surface-variant/60">{{ __('games.content_reliability_definition') }}</p>
            <p class="mt-1 text-xs text-on-surface-variant/60">{{ __('games.hint_attendance_tolerance') }}</p>
            @error('min_reliability_preference') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
        </div>
    @endif

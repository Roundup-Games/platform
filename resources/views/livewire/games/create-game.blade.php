<div class="py-6 sm:py-8">
    <div class="max-w-2xl mx-auto">
        {{-- Page Header --}}
        <div class="mb-6 sm:mb-8">
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('dashboard') }}" wire:navigate class="text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">arrow_back</span>
                </a>
                <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('games.action_create_game_session') }}</h1>
            </div>
            <p class="ml-8 sm:ml-9 text-sm text-on-surface-variant">{{ __('games.content_schedule_a_new_game_session_for_players_to_join') }}</p>
        </div>

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

                {{-- Section 1: Game Details --}}
                <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                    <x-form-section-header :number="1" :icon="'edit_note'" :title="__('games.content_game_details')" />

                    <div class="space-y-4">
                        {{-- Translatable fields (name + description) rendered via locale-aware section --}}
                        @php($allLocales = $this->getAllLocales())
                        @php($baselineLocale = $this->getBaselineLocale())
                        <x-forms.translatable-section
                            :fields="[
                                ['name' => 'name', 'label' => __('campaigns.field_session_name'), 'placeholder' => __('games.placeholder_game_name')],
                                ['name' => 'description', 'label' => __('common.field_description'), 'type' => 'textarea', 'rows' => 3, 'placeholder' => __('games.placeholder_game_description')],
                            ]"
                            :active-locale="$activeLocale"
                            :baseline-locale="$baselineLocale"
                            :all-locales="$allLocales"
                            :required="['name']"
                            inputClass="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors"
                        />

                        <div>
                            <label for="game-date-time" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.field_date_time') }} <span class="text-error">*</span></label>
                            <input type="datetime-local" id="game-date-time" wire:model="date_time"
                                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                            @error('date_time') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>

                        @if($game_type !== 'gathering')
                            <div>
                                <livewire:components.game-system-picker
                                    :fieldId="'game-system'"
                                    :label="__('games.content_game_system')"
                                    :error="$errors->first('game_system_id')"
                                    :gameType="$game_type"
                                    :value="$game_system_id"
                                    wire:model.live="game_system_id"
                                    wire:key="game-system-picker-{{ $game_type ?? 'default' }}"
                                />
                            </div>
                        @else
                            <div>
                                <label class="block text-sm font-medium text-on-surface mb-1">{{ __('games.content_game_system') }}</label>
                                <livewire:components.game-system-preference-picker :mode="'creation'" :preferenceType="'favorite'" :selectedIds="$game_systems" :maxSystems="6" wire:key="game-system-picker-gathering" />
                                @error('game_systems') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    </div>
                </section>

                {{-- Section 2: Players & Duration --}}
                <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                    <x-form-section-header :number="2" :icon="'group'" :title="__('location.field_players_capacity')" />

                    <div class="space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label for="game-min-players" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_min_players') }}</label>
                                <input type="number" id="game-min-players" wire:model.live="min_players" min="1" max="99" placeholder="2"
                                       class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                                @error('min_players') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="game-max-players" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_max_players') }}</label>
                                <input type="number" id="game-max-players" wire:model.live="max_players" min="1" max="99" placeholder="6"
                                       class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                                @error('max_players') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="game-duration" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.content_duration_hours') }}</label>
                                <input type="number" id="game-duration" wire:model.live="expected_duration" step="0.5" min="0.5" max="24" placeholder="2"
                                       class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                                @error('expected_duration') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="game-price" class="block text-sm font-medium text-on-surface mb-1">{{ __('billing.content_price_eur') }}</label>
                                <input type="number" id="game-price" wire:model="price" step="0.01" min="0" placeholder="0.00"
                                       class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                                <p class="mt-1 text-xs text-on-surface-variant/60">{{ __('billing.content_amount_in_eur_leave_0_for_free') }}</p>
                                @error('price') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        {{-- Bench Mode Toggle (hidden for gatherings — not GM-gated matchmaking) --}}
                        @if($game_type !== 'gathering')
                            <div class="mt-2">
                                <div class="flex items-center gap-3">
                                    <button type="button"
                                            @if(!$isGM) disabled @else wire:click="$toggle('bench_mode')" @endif
                                            @class([
                                                'relative inline-flex h-6 w-11 items-center rounded-full transition-colors shrink-0',
                                                'bg-primary' => $bench_mode,
                                                'bg-surface-container-highest' => !$bench_mode,
                                                'opacity-50 cursor-not-allowed' => !$isGM,
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
                                            <p class="text-xs text-on-surface-variant">{{ __('games.content_bench_mode_requires_gm') }}</p>
                                        @else
                                            <p class="text-xs text-on-surface-variant">{{ __('games.content_bench_mode_description') }}</p>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                </section>

                {{-- Section 3: Location --}}
                <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                    <x-form-section-header :number="3" :icon="'location_on'" :title="__('location.content_location')" />

                    <div>
                        <livewire:components.venue-picker :location-id="$location_id" :location-instructions="$location_instructions" />
                    </div>
                </section>

                {{-- Section 4: Session Meta (TYPE-CONDITIONAL) --}}
                <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                    <x-form-section-header :number="4" :icon="'tune'" :title="__('campaigns.content_discovery_session_meta')" />

                    <div class="space-y-5">
                        @if($game_type === 'ttrpg')
                            {{-- TTRPG: Full form --}}
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
                                <p class="mt-1 text-xs text-on-surface-variant/60">{{ __('games.hint_attendance_tolerance') }}</p>
                                @error('min_reliability_preference') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    </div>
                </section>

                {{-- Section 5: Visibility --}}
                <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                    <x-form-section-header :number="5" :icon="'visibility'" :title="__('common.content_visibility')" />

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="game-language" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.content_language_required') }} <span class="text-error">*</span></label>
                            <select id="game-language" wire:model="language"
                                    class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors">
                                @foreach($this->languageOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('language') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="game-visibility" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.content_visibility') }} <span class="text-error">*</span></label>
                            <select id="game-visibility" wire:model="visibility"
                                    class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors">
                                @if($this->canCreatePublic)
                                    <option value="public">{{ __('common.content_public_anyone_can_find_and_join') }}</option>
                                @endif
                                <option value="protected">{{ __('common.field_protected_only_with_link') }}</option>
                                <option value="private">{{ __('common.content_private_invite_only') }}</option>
                            </select>
                            @if(!$this->canCreatePublic)
                                <p class="mt-1 text-xs text-on-surface-variant/60">{{ __('common.content_public_visibility_requires_admin_approval') }}</p>
                            @endif
                            @if($this->publicViaVenue)
                                <p class="mt-1 text-xs text-success">{{ __('venues.content_public_unlocked_by_verified_venue') }}</p>
                            @endif
                            @error('visibility') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
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

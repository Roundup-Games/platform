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

        <form wire:submit="save" class="space-y-6">

            {{-- Section 1: Game Details --}}
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                <x-form-section-header :number="1" :icon="'edit_note'" :title="__('games.content_game_details')" />

                <div class="space-y-4">
                    <div>
                        <label for="game-name" class="block text-sm font-medium text-on-surface mb-1">{{ __('campaigns.field_session_name') }} <span class="text-error">*</span></label>
                        <input type="text" id="game-name" wire:model="name" placeholder="e.g. Dungeon Crawl Night"
                               class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                        @error('name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="game-type" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_game_type') }}</label>
                            <select id="game-type" wire:model="game_type"
                                    class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors">
                                @foreach($this->gameTypeOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('game_type') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="game-date-time" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.field_date_time') }} <span class="text-error">*</span></label>
                            <input type="datetime-local" id="game-date-time" wire:model="date_time"
                                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                            @error('date_time') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <livewire:components.game-system-picker
                            :fieldId="'game-system'"
                            :label="__('games.content_game_system')"
                            :error="$errors->first('game_system_id')"
                            wire:model.live="game_system_id"
                        />
                    </div>

                    <div>
                        <label for="game-description" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.field_description') }}</label>
                        <textarea id="game-description" wire:model="description" rows="3" placeholder="Describe the session..."
                                  class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors"></textarea>
                        @error('description') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
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
                </div>
            </section>

            {{-- Section 3: Location --}}
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                <x-form-section-header :number="3" :icon="'location_on'" :title="__('location.content_location')" />

                <div>
                    <livewire:components.location-picker :location-id="$location_id" />
                </div>
            </section>

            {{-- Section 4: Session Meta --}}
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                <x-form-section-header :number="4" :icon="'tune'" :title="__('campaigns.content_discovery_session_meta')" />

                <div class="space-y-5">
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
                            <input type="number" id="game-complexity" wire:model="complexity" step="0.25" min="1" max="5" placeholder="3.0"
                                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                            <p class="mt-1 text-xs text-on-surface-variant/60">{{ __('common.content_1_light_3_medium_5_heavy') }}</p>
                            @error('complexity') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-on-surface mb-2">{{ __('common.content_vibe_flags') }}</label>
                        <livewire:components.vibe-preference-picker :mode="'selection'" wire:key="game-vibe-picker" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-on-surface mb-2">{{ __('safety.content_safety_tools') }}</label>
                        <livewire:components.safety-tool-picker mode="selection" wire:key="game-safety-picker" />
                    </div>
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
                        @error('visibility') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            {{-- Actions --}}
            <div class="flex items-center gap-4 pt-2">
                <button wire:click="save" wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 px-6 py-2.5 bg-primary text-on-primary rounded-lg hover:brightness-110 active:scale-[0.96] transition-all text-sm font-medium shadow-ambient">
                    <span class="material-symbols-outlined text-base" wire:loading.remove aria-hidden="true">add_circle</span>
                    <span wire:loading.remove>{{ __('games.action_create_game') }}</span>
                    <span wire:loading class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-base animate-spin" aria-hidden="true">progress_activity</span>
                        {{ __('common.content_creating') }}
                    </span>
                </button>
                <a href="{{ route('dashboard') }}" wire:navigate
                   class="px-4 py-2.5 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                    {{ __('common.action_cancel') }}
                </a>
            </div>
        </form>
    </div>
</div>

<div class="py-8">
    <div class="max-w-2xl mx-auto space-y-8">
        {{-- Page Header --}}
        <div>
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('dashboard') }}" wire:navigate class="text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">arrow_back</span>
                </a>
                <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('campaigns.action_create_campaign') }}</h1>
            </div>
            <p class="ml-8 text-sm text-on-surface-variant">{{ __('campaigns.content_start_a_recurring_campaign_sessions') }}</p>
        </div>

        {{-- Campaign Details --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-lg font-medium text-on-surface mb-4 font-heading">{{ __('campaigns.content_campaign_details') }}</h2>

            <div class="space-y-4">
                <div>
                    <label for="campaign-name" class="block text-sm font-medium text-on-surface mb-1">{{ __('campaigns.field_campaign_name') }}</label>
                    <input type="text" id="campaign-name" wire:model="name" placeholder="e.g. Shadows of Waterdeep"
                           class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                    @error('name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <livewire:components.game-system-picker
                        :fieldId="'campaign-system'"
                        :label="__('games.content_game_system')"
                        :error="$errors->first('game_system_id')"
                        wire:model.live="game_system_id"
                    />
                </div>

                <div>
                    <label for="campaign-description" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.field_description') }}</label>
                    <textarea id="campaign-description" wire:model="description" rows="4" placeholder="Describe the campaign setting, tone, and what to expect..."
                              class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors"></textarea>
                    @error('description') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        {{-- Schedule --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-lg font-medium text-on-surface mb-4 font-heading">{{ __('campaigns.content_schedule') }}</h2>

            <div class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="campaign-recurrence" class="block text-sm font-medium text-on-surface mb-1">{{ __('campaigns.content_recurrence') }}</label>
                        <select id="campaign-recurrence" wire:model="recurrence"
                                class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors">
                            <option value="weekly">{{ __('campaigns.content_weekly') }}</option>
                            <option value="bi-weekly">{{ __('common.content_every_2_weeks') }}</option>
                            <option value="monthly">{{ __('campaigns.content_monthly') }}</option>
                            <option value="custom">{{ __('common.content_custom') }}</option>
                        </select>
                        @error('recurrence') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="campaign-time" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.field_time_of_day') }}</label>
                        <input type="time" id="campaign-time" wire:model="time_of_day"
                               class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                        @error('time_of_day') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="campaign-duration" class="block text-sm font-medium text-on-surface mb-1">{{ __('campaigns.content_session_duration_hours') }}</label>
                        <input type="number" id="campaign-duration" wire:model="session_duration" step="0.5" min="0.5" max="24" placeholder="e.g. 3"
                               class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                        @error('session_duration') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="campaign-price" class="block text-sm font-medium text-on-surface mb-1">{{ __('campaigns.field_price_per_session_eur') }}</label>
                        <input type="number" id="campaign-price" wire:model="price_per_session" step="0.01" min="0" placeholder="0.00"
                               class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                        <p class="mt-1 text-xs text-on-surface-variant">{{ __('billing.content_amount_in_eur_leave_0_for_free') }}</p>
                        @error('price_per_session') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="campaign-language" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.content_language_required') }}</label>
                        <select id="campaign-language" wire:model="language"
                                class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors">
                            @foreach($this->languageOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('language') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="campaign-visibility" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.content_visibility') }}</label>
                        <select id="campaign-visibility" wire:model="visibility"
                                class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors">
                            @if($this->canCreatePublic)
                                <option value="public">{{ __('common.content_public_anyone_can_find_and_join') }}</option>
                            @endif
                            <option value="protected">{{ __('common.field_protected_only_with_link') }}</option>
                            <option value="private">{{ __('common.content_private_invite_only') }}</option>
                        </select>
                        @if(!$this->canCreatePublic)
                            <p class="mt-1 text-xs text-on-surface-variant">{{ __('common.content_public_visibility_requires_admin_approval') }}</p>
                        @endif
                        @error('visibility') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        </section>

        {{-- Players & Capacity --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-lg font-medium text-on-surface mb-4 font-heading">{{ __('location.field_players_capacity') }}</h2>

            <div class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="campaign-min-players" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_min_players') }}</label>
                        <input type="number" id="campaign-min-players" wire:model.live="min_players" min="1" max="99" placeholder="e.g. 2"
                               class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                        <p class="mt-1 text-xs text-on-surface-variant">{{ __('campaigns.content_session_needs_this_many_to_start') }}</p>
                        @error('min_players') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="campaign-max-players" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_max_players') }}</label>
                        <input type="number" id="campaign-max-players" wire:model.live="max_players" min="1" max="99" placeholder="e.g. 6"
                               class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                        <p class="mt-1 text-xs text-on-surface-variant">{{ __('common.content_maximum_seats_available') }}</p>
                        @error('max_players') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        </section>

        {{-- Discovery & Session Meta --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-lg font-medium text-on-surface mb-4 font-heading">{{ __('campaigns.content_discovery_session_meta') }}</h2>

            <div class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="campaign-experience" class="block text-sm font-medium text-on-surface mb-1">{{ __('discovery.content_experience_level') }}</label>
                        <select id="campaign-experience" wire:model="experience_level"
                                class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors">
                            @foreach($this->experienceLevelOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('experience_level') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="campaign-complexity" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.content_complexity_1_5') }}</label>
                        <input type="number" id="campaign-complexity" wire:model="complexity" step="0.25" min="1" max="5" placeholder="e.g. 3.0"
                               class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                        <p class="mt-1 text-xs text-on-surface-variant">{{ __('common.content_1_light_3_medium_5_heavy') }}</p>
                        @error('complexity') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- Vibe Flags --}}
                <div>
                    <label class="block text-sm font-medium text-on-surface mb-2">{{ __('common.content_vibe_flags') }}</label>
                    <p class="text-xs text-on-surface-variant mb-3">{{ __('campaigns.content_describe_campaign_vibe') }}</p>

                    <livewire:components.vibe-preference-picker
                        :mode="'selection'"
                        wire:key="campaign-vibe-picker"
                    />
                </div>

                {{-- Safety Tools --}}
                <div>
                    <label class="block text-sm font-medium text-on-surface mb-2">{{ __('safety.content_safety_tools') }}</label>
                    <p class="text-xs text-on-surface-variant mb-3">{{ __('safety.action_select_the_safety_tools_you') }}</p>

                    <livewire:components.safety-tool-picker
                        mode="selection"
                        wire:key="campaign-safety-picker"
                    />
                </div>
            </div>
        </section>

        {{-- Actions --}}
        <div class="flex items-center gap-4">
            <button wire:click="save" wire:loading.attr="disabled"
                    class="px-6 py-2.5 bg-primary text-on-primary rounded-lg hover:opacity-90 transition-opacity text-sm font-medium shadow-ambient">
                <span wire:loading.remove>{{ __('campaigns.action_create_campaign') }}</span>
                <span wire:loading>{{ __('common.content_creating') }}</span>
            </button>
            <a href="{{ route('dashboard') }}" wire:navigate
               class="px-4 py-2.5 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                {{ __('common.action_cancel') }}
            </a>
        </div>
    </div>
</div>

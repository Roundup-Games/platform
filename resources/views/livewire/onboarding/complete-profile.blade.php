<div>
    <!-- Progress indicator -->
    <div class="mb-6">
        <div class="flex items-center justify-between mb-2">
            @foreach([__('location.content_location'), __('common.content_identity'), __('pages.content_contact'), __('profile.content_preferences')] as $i => $label)
                <div class="flex items-center {{ $loop->last ? '' : 'flex-1' }}">
                    <div class="flex items-center justify-center w-8 h-8 rounded-full text-sm font-medium
                        {{ $step > $i + 1 ? 'bg-primary text-on-primary' : ($step === $i + 1 ? 'bg-primary text-on-primary' : 'bg-surface-container-highest text-on-surface-variant') }}">
                        @if($step > $i + 1)
                            <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check</span>
                        @else
                            {{ $i + 1 }}
                        @endif
                    </div>
                    <span class="ml-2 text-xs font-medium {{ $step === $i + 1 ? 'text-on-surface font-semibold' : 'text-on-surface-variant' }} hidden sm:inline">
                        {{ $label }}
                    </span>
                    @if(!$loop->last)
                        <div class="flex-1 mx-3 h-0.5 {{ $step > $i + 1 ? 'bg-primary' : 'bg-outline-variant/30' }}"></div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    <div class="bg-surface-container-lowest rounded-2xl shadow-ambient p-6 sm:p-8 border border-outline-variant/10">
        <!-- Step 1: Location -->
        @if($step === 1)
            <h2 class="text-xl font-heading font-semibold text-on-surface mb-1">
                {{ __('location.content_where_are_you_based') }}
            </h2>
            <p class="text-sm text-on-surface-variant mb-6">
                {{ __("profile.content_this_helps_us_find_games_and_events_near_you") }}
            </p>

            {{-- If location was detected from localStorage and not yet confirmed --}}
            @if($locationSource === 'localStorage' && $city && !$locationConfirmed)
                <div class="space-y-4">
                    <div class="flex items-center gap-3 p-4 rounded-xl bg-primary-container/20 border border-primary/20">
                        <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">location_on</span>
                        <div>
                            <p class="text-sm font-medium text-on-surface">
                                {{ __('location.content_we_think_you_re_in_city_is_that_right', ['city' => $city]) }}
                            </p>
                        </div>
                    </div>

                    <div class="flex gap-3">
                        <button wire:click="confirmLocation"
                                class="flex-1 px-4 py-2.5 bg-primary text-on-primary rounded-xl shadow-md hover:brightness-110 active:scale-95 transition-all duration-150 text-sm font-medium font-heading tracking-tight">
                            {{ __('common.content_yes_that_s_right') }}
                        </button>
                        <button wire:click="editLocation"
                                class="px-4 py-2.5 border border-outline-variant/30 text-on-surface-variant rounded-xl hover:bg-surface-container-low transition-colors text-sm font-medium">
                            {{ __('common.action_no_let_me_search') }}
                        </button>
                    </div>
                </div>

            {{-- Location confirmed --}}
            @elseif($locationConfirmed && $city)
                <div class="space-y-4">
                    <div class="flex items-center gap-3 p-4 rounded-xl bg-primary-container/20 border border-primary/20">
                        <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">check_circle</span>
                        <div>
                            <p class="text-sm font-medium text-on-surface">
                                {{ $city }}
                            </p>
                            @if($address)
                                <p class="text-xs text-on-surface-variant">{{ $address }}</p>
                            @endif
                        </div>
                    </div>
                    <button wire:click="editLocation"
                            class="text-sm text-primary hover:underline">
                        {{ __('location.action_change_location') }}
                    </button>
                </div>

            {{-- Manual entry --}}
            @else
                <div class="space-y-4">
                    <div>
                        <label for="city" class="block text-sm font-medium text-on-surface mb-1">
                            {{ __('location.field_city') }} <span class="text-error">*</span>
                        </label>
                        <input type="text" id="city" wire:model="city" placeholder="{{ __('location.field_enter_your_city') }}"
                               class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant" />
                        @error('city') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="address" class="block text-sm font-medium text-on-surface mb-1">
                            {{ __('location.field_address') }} <span class="text-on-surface-variant">{{ __('common.content_optional') }}</span>
                        </label>
                        <input type="text" id="address" wire:model="address" placeholder="{{ __('location.placeholder_street_address_neighborhood') }}"
                               class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant" />
                        @error('address') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <button wire:click="findMyLocation"
                            class="w-full px-4 py-2.5 bg-primary text-on-primary rounded-xl shadow-md hover:brightness-110 active:scale-95 transition-all duration-150 text-sm font-medium font-heading tracking-tight">
                        <span wire:loading.remove wire:target="findMyLocation">{{ __('location.action_find_my_location') }}</span>
                        <span wire:loading wire:target="findMyLocation">{{ __('common.content_searching') }}</span>
                    </button>
                </div>
            @endif
        @endif

        <!-- Step 2: Identity -->
        @if($step === 2)
            <h2 class="text-xl font-heading font-semibold text-on-surface mb-1">
                {{ __('common.content_tell_us_about_yourself') }}
            </h2>
            <p class="text-sm text-on-surface-variant mb-6">
                {{ __('common.content_this_helps_us_personalize_your_experience') }}
            </p>

            <div class="space-y-4">
                <div>
                    <label for="gender" class="block text-sm font-medium text-on-surface mb-1">
                        {{ __('common.field_gender') }} <span class="text-error">*</span>
                    </label>
                    <select id="gender" wire:model="gender"
                            class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant">
                        <option value="">{{ __('common.content_select') }}</option>
                        <option value="male">{{ __('common.content_male') }}</option>
                        <option value="female">{{ __('common.content_female') }}</option>
                        <option value="non-binary">{{ __('common.content_non_binary') }}</option>
                        <option value="prefer-not-to-say">{{ __('common.content_prefer_not_to_say') }}</option>
                        <option value="other">{{ __('common.content_other') }}</option>
                    </select>
                    @error('gender') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="pronouns" class="block text-sm font-medium text-on-surface mb-1">
                        {{ __('profile.content_pronouns') }} <span class="text-error">*</span>
                    </label>
                    <select id="pronouns" wire:model="pronouns"
                            class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant">
                        <option value="">{{ __('common.content_select') }}</option>
                        <option value="he/him">{{ __('common.content_he_him') }}</option>
                        <option value="she/her">{{ __('common.content_she_her') }}</option>
                        <option value="they/them">{{ __('common.content_they_them') }}</option>
                        <option value="prefer-not-to-say">{{ __('common.content_prefer_not_to_say') }}</option>
                        <option value="other">{{ __('common.content_other') }}</option>
                    </select>
                    @error('pronouns') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
            </div>
        @endif

        <!-- Step 3: Contact -->
        @if($step === 3)
            <h2 class="text-xl font-heading font-semibold text-on-surface mb-1">
                {{ __('common.content_contact_information') }}
            </h2>
            <p class="text-sm text-on-surface-variant mb-6">
                {{ __('games.content_optional_useful_for_game_night_coordination') }}
            </p>

            <div>
                <label for="phone" class="block text-sm font-medium text-on-surface mb-1">
                    {{ __('common.field_phone_number') }} <span class="text-on-surface-variant">{{ __('common.content_optional') }}</span>
                </label>
                <input type="tel" id="phone" wire:model="phone" placeholder="+1 (555) 000-0000"
                       class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant" />
                @error('phone') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
            </div>
        @endif

        <!-- Step 4: Game Preferences -->
        @if($step === 4)
            <div class="flex items-center gap-3 mb-1">
                <h2 class="text-xl font-heading font-semibold text-on-surface">
                    {{ __('games.heading_game_preferences') }}
                </h2>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-container-high text-on-surface-variant border border-outline-variant/30">
                    {{ __('common.content_step_optional_badge') }}
                </span>
            </div>
            <p class="text-sm text-on-surface-variant mb-6">
                {{ __('events.content_select_the_games_you_enjoy') }}
            </p>

            <livewire:components.game-system-preference-picker
                :wire:key="'onboarding-favorite-picker'"
                preferenceType="favorite"
                :selectedIds="$favoriteGameSystemIds"
                :conflictIds="[]"
            />

            @error('favoriteGameSystemIds') <p class="mt-2 text-sm text-error">{{ $message }}</p> @enderror

            <p class="mt-4 text-xs text-on-surface-variant">
                {{ __('common.content_this_step_is_optional') }}
            </p>
        @endif

        <!-- Navigation -->
        <div class="flex items-center justify-between mt-6 pt-4 border-t border-outline-variant/15">
            @if($step > 1)
                <button wire:click="previousStep"
                        class="inline-flex items-center text-sm text-on-surface-variant hover:text-primary transition-colors">
                    <span class="material-symbols-outlined text-base mr-1">arrow_back</span>
                    {{ __('common.action_back') }}
                </button>
            @else
                <span></span>
            @endif

            @if($step < 4)
                <button wire:click="nextStep"
                        class="px-6 py-2.5 bg-primary text-on-primary rounded-xl shadow-md hover:brightness-110 active:scale-95 transition-all duration-150 text-sm font-medium font-heading tracking-tight">
                    {{ __('common.action_continue') }}
                </button>
            @else
                <div class="flex items-center gap-3">
                    <button wire:click="complete" wire:loading.attr="disabled"
                            class="px-6 py-2.5 bg-primary text-on-primary rounded-xl shadow-md hover:brightness-110 active:scale-95 transition-all duration-150 text-sm font-medium font-heading tracking-tight">
                        <span wire:loading.remove>{{ __('profile.content_complete_profile') }}</span>
                        <span wire:loading>{{ __('common.content_saving') }}</span>
                    </button>
                </div>
            @endif
        </div>
    </div>
</div>

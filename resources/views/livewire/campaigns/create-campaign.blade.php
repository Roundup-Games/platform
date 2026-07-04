<div class="py-6 sm:py-8">
    <div class="max-w-2xl mx-auto">
        {{-- Page Header --}}
        <div class="mb-6 sm:mb-8">
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('dashboard') }}" wire:navigate class="text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">arrow_back</span>
                </a>
                <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('campaigns.action_create_campaign') }}</h1>
            </div>
            <p class="ml-8 sm:ml-9 text-sm text-on-surface-variant">{{ __('campaigns.content_start_a_recurring_campaign_sessions') }}</p>
        </div>

        <form wire:submit="save" class="space-y-6">

            {{-- Section 1: Campaign Details --}}
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                <x-form-section-header :number="1" :icon="'edit_note'" :title="__('campaigns.content_campaign_details')" />

                <div class="space-y-4">
                    {{-- Translatable fields (name + description) rendered via locale-aware section --}}
                    @php
                        $allLocales = $this->getAllLocales();
                        $baselineLocale = $this->getBaselineLocale();
                    @endphp
                    <x-forms.translatable-section
                        :fields="[
                            ['name' => 'name', 'label' => __('campaigns.field_campaign_name'), 'placeholder' => 'e.g. Shadows of Waterdeep'],
                            ['name' => 'description', 'label' => __('common.field_description'), 'type' => 'textarea', 'rows' => 4, 'placeholder' => 'Describe the campaign setting, tone, and what to expect...'],
                        ]"
                        :active-locale="$activeLocale"
                        :baseline-locale="$baselineLocale"
                        :all-locales="$allLocales"
                        :required="['name']"
                        inputClass="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors"
                    />

                    <div>
                        <label for="campaign-game-type" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_game_type') }} <span class="text-error">*</span></label>
                        <select id="campaign-game-type" wire:model="game_type"
                                class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors">
                            @foreach($this->gameTypeOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @if($game_type === 'gathering')
                            <p class="mt-1 text-xs text-on-surface-variant/60">{{ __('campaigns.content_gathering_campaign_hint') }}</p>
                        @endif
                        @error('game_type') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <livewire:components.game-system-picker
                            :fieldId="'campaign-system'"
                            :label="__('games.content_game_system')"
                            :error="$errors->first('game_system_id')"
                            :gameType="$game_type"
                            wire:model.live="game_system_id"
                        />
                    </div>

                    {{-- Host-uploaded cover image (S07). Optional — resolveCoverUrl() falls back to the representative system cover, then og-default.jpg. --}}
                    <div>
                        <label for="campaign-cover-image" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_cover_image') }}</label>
                        <div class="mt-1">
                            @if($cover_image && str_starts_with($cover_image->getMimeType(), 'image/'))
                                <div class="relative inline-block">
                                    <img src="{{ $cover_image->temporaryUrl() }}" alt="" class="h-32 w-32 object-cover rounded-lg border border-outline-variant">
                                    <button type="button" wire:click="$set('cover_image', null)" class="absolute -top-2 -right-2 bg-surface rounded-full p-1 shadow-md hover:bg-surface-container-high transition-colors" aria-label="{{ __('games.action_remove_cover_image') }}">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">close</span>
                                    </button>
                                </div>
                            @elseif($cover_image)
                                {{-- Non-image file selected (will fail the image validation rule).
                                     Rendered without temporaryUrl(), which throws for non-previewable mimes. --}}
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-sm text-on-surface-variant" aria-hidden="true">description</span>
                                    <span class="text-sm text-on-surface-variant">{{ $cover_image->getClientOriginalName() }}</span>
                                </div>
                            @else
                                <input type="file" id="campaign-cover-image" wire:model="cover_image" accept="image/jpeg,image/png,image/webp"
                                       class="block w-full text-sm text-on-surface file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-primary file:text-on-primary file:font-medium hover:file:brightness-110 cursor-pointer" />
                                <p class="mt-1 text-xs text-on-surface-variant/60">{{ __('games.content_cover_image_hint') }}</p>
                                <div wire:loading wire:target="cover_image" class="mt-1 text-xs text-on-surface-variant flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm animate-spin" aria-hidden="true" role="status">progress_activity</span>
                                    {{ __('common.content_uploading') }}
                                </div>
                            @endif
                        </div>
                        @error('cover_image') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            {{-- Section 2: Schedule --}}
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                <x-form-section-header :number="2" :icon="'schedule'" :title="__('campaigns.content_schedule')" />

                <div class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="campaign-recurrence" class="block text-sm font-medium text-on-surface mb-1">{{ __('campaigns.content_recurrence') }} <span class="text-error">*</span></label>
                            <select id="campaign-recurrence" wire:model="recurrence"
                                    class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors">
                                <option value="weekly">{{ __('campaigns.content_weekly') }}</option>
                                <option value="bi-weekly">{{ __('common.content_every_2_weeks') }}</option>
                                <option value="monthly">{{ __('campaigns.content_monthly') }}</option>
                                <option value="custom">{{ __('common.content_custom') }}</option>
                            </select>
                            @error('recurrence') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="campaign-time" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.field_time_of_day') }} <span class="text-error">*</span></label>
                            <input type="time" id="campaign-time" wire:model="time_of_day"
                                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                            @error('time_of_day') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="campaign-duration" class="block text-sm font-medium text-on-surface mb-1">{{ __('campaigns.content_session_duration_hours') }}</label>
                            <input type="number" id="campaign-duration" wire:model="session_duration" step="0.5" min="0.5" max="24" placeholder="3"
                                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                            @error('session_duration') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="campaign-price" class="block text-sm font-medium text-on-surface mb-1">{{ __('campaigns.field_price_per_session_eur') }}</label>
                            <input type="number" id="campaign-price" wire:model="price_per_session" step="0.01" min="0" placeholder="0.00"
                                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                            <p class="mt-1 text-xs text-on-surface-variant/60">{{ __('billing.content_amount_in_eur_leave_0_for_free') }}</p>
                            @error('price_per_session') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
            </section>

            {{-- Section 3: Players & Location --}}
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                <x-form-section-header :number="3" :icon="'group'" :title="__('location.field_players_capacity') . ' & ' . __('location.content_location')" />

                <div class="space-y-5">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="campaign-min-players" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_min_players') }}</label>
                            <input type="number" id="campaign-min-players" wire:model.live="min_players" min="1" max="99" placeholder="2"
                                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                            @error('min_players') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="campaign-max-players" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_max_players') }}</label>
                            <input type="number" id="campaign-max-players" wire:model.live="max_players" min="1" max="99" placeholder="6"
                                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                            @error('max_players') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    {{-- Bench Mode Toggle --}}
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

                    <div>
                        <livewire:components.venue-picker :location-id="$location_id" :location-instructions="$location_instructions" />
                    </div>
                </div>
            </section>

            {{-- Section 4: Session Meta --}}
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                <x-form-section-header :number="4" :icon="'tune'" :title="__('campaigns.content_discovery_session_meta')" />

                <div class="space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="campaign-experience" class="block text-sm font-medium text-on-surface mb-1">{{ __('discovery.content_experience_level') }}</label>
                            <select id="campaign-experience" wire:model="experience_level"
                                    class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors">
                                @foreach($this->experienceLevelOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('experience_level') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="campaign-complexity" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.content_complexity_1_5') }}</label>
                            <input type="number" id="campaign-complexity" wire:model="complexity" step="0.25" min="1" max="5" placeholder="3.0"
                                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                            <p class="mt-1 text-xs text-on-surface-variant/60">{{ __('common.content_1_light_3_medium_5_heavy') }}</p>
                            @error('complexity') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-on-surface mb-2">{{ __('common.content_vibe_flags') }}</label>
                        <livewire:components.vibe-preference-picker :mode="'selection'" wire:key="campaign-vibe-picker" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-on-surface mb-2">{{ __('safety.content_safety_tools') }}</label>
                        <livewire:components.safety-tool-picker mode="selection" wire:key="campaign-safety-picker" />
                    </div>
                </div>
            </section>

            {{-- Section 5: Visibility --}}
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                <x-form-section-header :number="5" :icon="'visibility'" :title="__('common.content_visibility')" />

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="campaign-language" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.content_language_required') }} <span class="text-error">*</span></label>
                        <select id="campaign-language" wire:model="language"
                                class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors">
                            @foreach($this->languageOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('language') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="campaign-visibility" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.content_visibility') }} <span class="text-error">*</span></label>
                        <select id="campaign-visibility" wire:model="visibility"
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
                <button wire:click="save" wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 px-6 py-2.5 bg-primary text-on-primary rounded-lg hover:brightness-110 active:scale-[0.96] transition-all text-sm font-medium shadow-ambient whitespace-nowrap">
                        {{-- Stable label + icon swap so the redirect trigger stays in the DOM (M054). --}}
                        <span class="inline-flex items-center gap-2">
                            <span class="material-symbols-outlined text-base" wire:loading.remove wire:target="save" aria-hidden="true">add_circle</span>
                            <span class="material-symbols-outlined text-base animate-spin" wire:loading wire:target="save" aria-hidden="true" role="status" aria-label="{{ __('common.content_creating') }}">progress_activity</span>
                            {{ __('campaigns.action_create_campaign') }}
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

<div class="py-8 bg-surface">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 space-y-6">
        {{-- Page Header --}}
        <div>
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('events.index') }}" wire:navigate class="text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">arrow_back</span>
                </a>
                <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('events.action_create_event') }}</h1>
            </div>
            <p class="ml-8 text-sm text-on-surface-variant">{{ __('events.action_set_up_a_new_event_for_your_participants') }}</p>
        </div>

        {{-- Step Indicator --}}
        <div class="bg-surface-container-low rounded-xl shadow-ambient p-4">
            <div class="flex items-center justify-between">
                @php
                    $stepLabels = [1 => __('profile.content_basic_info'), 2 => __('location.content_venue'), 3 => __('billing.field_fees_registration'), 4 => __('events.content_divisions'), 5 => __('profile.content_rules_settings')];
                @endphp
                @foreach($stepLabels as $num => $label)
                    <button wire:click="goToStep({{ $num }})"
                            class="flex items-center gap-2 text-sm {{ $step === $num ? 'text-primary font-semibold' : ($step > $num ? 'text-secondary' : 'text-on-surface-variant/50') }}">
                        <span class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold border-2 {{ $step === $num ? 'border-primary bg-primary text-on-primary' : ($step > $num ? 'border-secondary bg-secondary text-on-secondary' : 'border-outline text-on-surface-variant/50') }}">
                            @if($step > $num)
                                ✓
                            @else
                                {{ $num }}
                            @endif
                        </span>
                        <span class="hidden sm:inline">{{ $label }}</span>
                    </button>
                    @if(!$loop->last)
                        <div class="flex-1 h-0.5 mx-1 {{ $step > $num ? 'bg-secondary' : 'bg-outline-variant/30' }}"></div>
                    @endif
                @endforeach
            </div>
        </div>

        {{-- Flash --}}
        @if(session()->has('error'))
            <div class="bg-error-container border border-error/20 rounded-lg p-3 text-sm text-on-error-container" role="alert" aria-live="polite">
                {{ session('error') }}
            </div>
        @endif

        {{-- Step 1: Basic Info --}}
        @if($step === 1)
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6 space-y-4">
            <h2 class="text-lg font-medium text-on-surface font-heading">{{ __('profile.content_basic_information') }}</h2>

            <div>
                <label for="event-name" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('events.field_event_name') }}</label>
                @if(in_array($content_language, ['de', 'de+en']))
                <div class="mb-2">
                    <div class="flex gap-1">
                        <button type="button" wire:click="setLocaleTab('en')"
                                class="px-3 py-1 text-xs font-medium border-b-2 transition-colors {{ $activeLocale === 'en' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' }}">
                            EN
                        </button>
                        <button type="button" wire:click="setLocaleTab('de')"
                                class="px-3 py-1 text-xs font-medium border-b-2 transition-colors {{ $activeLocale === 'de' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' }}">
                            DE
                        </button>
                    </div>
                </div>
                @endif
                @if(!in_array($content_language, ['de', 'de+en']) || $activeLocale === 'en')
                <input type="text" id="event-name" wire:model="name" placeholder="{{ __('events.placeholder_event_name') }}"
                       class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                @error('name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                @endif
                @if(in_array($content_language, ['de', 'de+en']) && $activeLocale === 'de')
                <input type="text" id="event-name-de" wire:model="name_de" placeholder="{{ __('events.placeholder_german_event_name') }}"
                       class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                @error('name_de') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                @endif
            </div>

            <div>
                <label for="content-language" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('events.field_content_language') }}</label>
                <select id="content-language" wire:model="content_language" class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm">
                    @foreach(\App\Enums\ContentLanguage::cases() as $lang)
                        <option value="{{ $lang->value }}">{{ $lang->label() }}</option>
                    @endforeach
                </select>
                @error('content_language') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="event-short-description" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.field_short_description') }}</label>
                @if(in_array($content_language, ['de', 'de+en']))
                <div class="mb-2">
                    <div class="flex gap-1">
                        <button type="button" wire:click="setLocaleTab('en')"
                                class="px-3 py-1 text-xs font-medium border-b-2 transition-colors {{ $activeLocale === 'en' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' }}">
                            EN
                        </button>
                        <button type="button" wire:click="setLocaleTab('de')"
                                class="px-3 py-1 text-xs font-medium border-b-2 transition-colors {{ $activeLocale === 'de' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' }}">
                            DE
                        </button>
                    </div>
                </div>
                @endif
                @if(!in_array($content_language, ['de', 'de+en']) || $activeLocale === 'en')
                <input type="text" id="event-short-description" wire:model="short_description" maxlength="500" placeholder="{{ __('events.placeholder_one_liner_for_listings') }}"
                       class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                @error('short_description') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                @endif
                @if(in_array($content_language, ['de', 'de+en']) && $activeLocale === 'de')
                <input type="text" id="event-short-description-de" wire:model="short_description_de" maxlength="500" placeholder="{{ __('events.placeholder_german_short_description') }}"
                       class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                @error('short_description_de') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                @endif
            </div>

            <div>
                <label for="event-description" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.field_full_description') }}</label>
                @if(in_array($content_language, ['de', 'de+en']))
                <div class="mb-2">
                    <div class="flex gap-1">
                        <button type="button" wire:click="setLocaleTab('en')"
                                class="px-3 py-1 text-xs font-medium border-b-2 transition-colors {{ $activeLocale === 'en' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' }}">
                            EN
                        </button>
                        <button type="button" wire:click="setLocaleTab('de')"
                                class="px-3 py-1 text-xs font-medium border-b-2 transition-colors {{ $activeLocale === 'de' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' }}">
                            DE
                        </button>
                    </div>
                </div>
                @endif
                @if(!in_array($content_language, ['de', 'de+en']) || $activeLocale === 'en')
                <textarea id="event-description" wire:model="description" rows="5" placeholder="{{ __('events.placeholder_detailed_event_description') }}"
                          class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"></textarea>
                @error('description') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                @endif
                @if(in_array($content_language, ['de', 'de+en']) && $activeLocale === 'de')
                <textarea id="event-description-de" wire:model="description_de" rows="5" placeholder="{{ __('events.placeholder_german_detailed_description') }}"
                          class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"></textarea>
                @error('description_de') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                @endif
            </div>

            <div>
                <label for="event-type" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('events.content_event_type') }}</label>
                <select id="event-type" wire:model="type" class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm">
                    <option value="tournament">{{ __('events.field_tournament') }}</option>
                    <option value="league">{{ __('common.content_league') }}</option>
                    <option value="camp">{{ __('common.content_camp') }}</option>
                    <option value="clinic">{{ __('common.content_clinic') }}</option>
                    <option value="social">{{ __('common.content_social') }}</option>
                    <option value="other">{{ __('common.content_other') }}</option>
                </select>
                @error('type') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="event-start-date" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.field_start_date') }}</label>
                    <input type="date" id="event-start-date" wire:model="start_date"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('start_date') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="event-end-date" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.field_end_date') }}</label>
                    <input type="date" id="event-end-date" wire:model="end_date"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('end_date') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>
        @endif

        {{-- Step 2: Venue --}}
        @if($step === 2)
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6 space-y-4">
            <h2 class="text-lg font-medium text-on-surface font-heading">{{ __('location.content_venue_details') }}</h2>
            <p class="text-sm text-on-surface-variant">{{ __('location.content_all_venue_fields_are_optional') }}</p>

            <div>
                <label for="event-venue-name" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('location.field_venue_name') }}</label>
                <input type="text" id="event-venue-name" wire:model="venue_name" placeholder="{{ __('events.placeholder_venue_name') }}"
                       class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                @error('venue_name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="event-address" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('location.field_address') }}</label>
                <textarea id="event-address" wire:model="venue_address" rows="2" placeholder="{{ __('events.placeholder_street_address') }}"
                          class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"></textarea>
                @error('venue_address') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label for="event-city" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('location.field_city') }}</label>
                    <input type="text" id="event-city" wire:model="city" placeholder="{{ __('events.placeholder_city') }}"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('city') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="event-country" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('location.field_country') }}</label>
                    <input type="text" id="event-country" wire:model="country" maxlength="3" placeholder="{{ __('events.placeholder_country') }}"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('country') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="event-postal-code" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('location.field_postal_code') }}</label>
                    <input type="text" id="event-postal-code" wire:model="postal_code" placeholder="{{ __('events.placeholder_postal_code') }}"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('postal_code') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>
        @endif

        {{-- Step 3: Fees & Registration --}}
        @if($step === 3)
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6 space-y-4">
            <h2 class="text-lg font-medium text-on-surface font-heading">{{ __('billing.field_registration_fees') }}</h2>

            <div>
                <label class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('events.content_registration_type_2') }}</label>
                <div class="flex gap-3 mt-1">
                    @foreach(['team' => __('events.content_team_only'), 'individual' => __('common.content_individual_only'), 'both' => __('common.content_both')] as $val => $label)
                        <button type="button" wire:click="$set('registration_type', '{{ $val }}')"
                                class="px-4 py-2 rounded-lg text-sm font-medium border-2 transition-colors {{ $registration_type === $val ? 'border-primary bg-primary/10 text-primary' : 'border-outline text-on-surface-variant hover:border-outline-variant' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                @error('registration_type') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @if(in_array($registration_type, ['team', 'both']))
                <div>
                    <label for="event-max-teams" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('events.field_max_teams') }}</label>
                    <input type="number" id="event-max-teams" wire:model="max_teams" min="1" placeholder="{{ __('common.content_unlimited') }}"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('max_teams') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="event-min-players" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('events.field_min_players_per_team') }}</label>
                    <input type="number" id="event-min-players" wire:model="min_players_per_team" min="1"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('min_players_per_team') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="event-max-players" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('events.field_max_players_per_team') }}</label>
                    <input type="number" id="event-max-players" wire:model="max_players_per_team" min="1"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('max_players_per_team') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                @endif
                @if(in_array($registration_type, ['individual', 'both']))
                <div>
                    <label for="event-max-participants" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('events.field_max_participants') }}</label>
                    <input type="number" id="event-max-participants" wire:model="max_participants" min="1" placeholder="{{ __('common.content_unlimited') }}"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('max_participants') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                @endif
            </div>

            <h3 class="text-md font-medium text-on-surface pt-2">{{ __('billing.field_fees') }} <span class="text-xs text-on-surface-variant">({{ __('common.content_in_cents') }})</span></h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @if(in_array($registration_type, ['team', 'both']))
                <div>
                    <label for="event-team-fee" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('billing.field_team_registration_fee') }}</label>
                    <input type="number" id="event-team-fee" wire:model="team_registration_fee" min="0" placeholder="{{ __('events.placeholder_0_free') }}"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('team_registration_fee') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                @endif
                @if(in_array($registration_type, ['individual', 'both']))
                <div>
                    <label for="event-individual-fee" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('billing.field_individual_registration_fee') }}</label>
                    <input type="number" id="event-individual-fee" wire:model="individual_registration_fee" min="0" placeholder="{{ __('events.placeholder_0_free') }}"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('individual_registration_fee') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                @endif
                <div>
                    <label for="event-early-bird-discount" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('billing.content_early_bird_discount') }}</label>
                    <input type="number" id="event-early-bird-discount" wire:model="early_bird_discount" min="0" placeholder="{{ __('common.content_no_discount') }}"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('early_bird_discount') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="event-early-bird-deadline" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('billing.content_early_bird_deadline') }}</label>
                    <input type="datetime-local" id="event-early-bird-deadline" wire:model="early_bird_deadline"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('early_bird_deadline') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <h3 class="text-md font-medium text-on-surface pt-2">{{ __('events.content_registration_window') }}</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="event-reg-opens" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.content_opens_at') }}</label>
                    <input type="datetime-local" id="event-reg-opens" wire:model="registration_opens_at"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('registration_opens_at') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="event-reg-closes" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.content_closes_at') }}</label>
                    <input type="datetime-local" id="event-reg-closes" wire:model="registration_closes_at"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('registration_closes_at') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>
        @endif

        {{-- Step 4: Divisions --}}
        @if($step === 4)
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6 space-y-4">
            <h2 class="text-lg font-medium text-on-surface font-heading">{{ __('events.content_divisions') }}</h2>
            <p class="text-sm text-on-surface-variant">{{ __('events.content_optional_add_competitive_divisions_to_your_event') }}</p>

            {{-- Add Division Form --}}
            <div class="bg-surface-container rounded-lg p-4 space-y-3">
                <div>
                    <label for="event-division-name" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('events.field_division_name') }}</label>
                    <input type="text" id="event-division-name" wire:model="newDivisionName" placeholder="{{ __('events.placeholder_division_name') }}"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('newDivisionName') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="event-division-description" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.field_description') }}</label>
                    <input type="text" id="event-division-description" wire:model="newDivisionDescription" placeholder="{{ __('events.placeholder_division_description') }}"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('newDivisionDescription') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                <button wire:click="addDivision" class="px-4 py-2 bg-surface-container-high text-on-surface rounded-lg hover:bg-surface-container-highest text-sm font-medium transition-colors inline-flex items-center gap-1">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">add</span>
                    {{ __('events.action_add_division') }}
                </button>
            </div>

            {{-- Division List --}}
            @if(!empty($divisions))
                <div class="space-y-2">
                    @foreach($divisions as $i => $division)
                        <div class="flex items-center justify-between bg-surface border border-outline-variant rounded-lg px-4 py-3">
                            <div>
                                <p class="font-medium text-on-surface">{{ $division['name'] }}</p>
                                @if(!empty($division['description']))
                                    <p class="text-sm text-on-surface-variant">{{ $division['description'] }}</p>
                                @endif
                            </div>
                            <button wire:click="removeDivision({{ $i }})" wire:confirm="{{ __('events.flash_remove_this_division') }}"
                                    class="text-on-surface-variant hover:text-error transition-colors" aria-label="{{ __('events.aria_remove_division') }}">
                                <span class="material-symbols-outlined text-xl" aria-hidden="true">delete</span>
                            </button>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-center text-on-surface-variant/50 text-sm py-4">{{ __('events.content_no_divisions_added_yet_skip_if_not_applicable') }}</p>
            @endif
        </section>
        @endif

        {{-- Step 5: Rules & Settings --}}
        @if($step === 5)
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6 space-y-4">
            <h2 class="text-lg font-medium text-on-surface font-heading">{{ __('profile.content_rules_settings') }}</h2>

            <div>
                <label for="event-rules" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.content_rules') }} <span class="text-xs text-on-surface-variant">({{ __('common.content_one_per_line') }})</span></label>
                @if(in_array($content_language, ['de', 'de+en']))
                <div class="mb-2">
                    <div class="flex gap-1">
                        <button type="button" wire:click="setLocaleTab('en')"
                                class="px-3 py-1 text-xs font-medium border-b-2 transition-colors {{ $activeLocale === 'en' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' }}">
                            EN
                        </button>
                        <button type="button" wire:click="setLocaleTab('de')"
                                class="px-3 py-1 text-xs font-medium border-b-2 transition-colors {{ $activeLocale === 'de' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' }}">
                            DE
                        </button>
                    </div>
                </div>
                @endif
                @if(!in_array($content_language, ['de', 'de+en']) || $activeLocale === 'en')
                <textarea id="event-rules" wire:model="rules" rows="5" placeholder="{{ __('events.placeholder_enter_each_rule') }}"
                          class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"></textarea>
                @error('rules') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                @endif
                @if(in_array($content_language, ['de', 'de+en']) && $activeLocale === 'de')
                <textarea id="event-rules-de" wire:model="rules_de" rows="5" placeholder="{{ __('events.placeholder_german_rules') }}"
                          class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"></textarea>
                @error('rules_de') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                @endif
            </div>

            <div>
                <label for="event-schedule" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('campaigns.content_schedule') }} <span class="text-xs text-on-surface-variant">({{ __('common.content_one_item_per_line') }})</span></label>
                @if(in_array($content_language, ['de', 'de+en']))
                <div class="mb-2">
                    <div class="flex gap-1">
                        <button type="button" wire:click="setLocaleTab('en')"
                                class="px-3 py-1 text-xs font-medium border-b-2 transition-colors {{ $activeLocale === 'en' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' }}">
                            EN
                        </button>
                        <button type="button" wire:click="setLocaleTab('de')"
                                class="px-3 py-1 text-xs font-medium border-b-2 transition-colors {{ $activeLocale === 'de' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' }}">
                            DE
                        </button>
                    </div>
                </div>
                @endif
                @if(!in_array($content_language, ['de', 'de+en']) || $activeLocale === 'en')
                <textarea id="event-schedule" wire:model="schedule" rows="4" placeholder="{{ __('events.placeholder_enter_schedule_items') }}"
                          class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"></textarea>
                @error('schedule') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                @endif
                @if(in_array($content_language, ['de', 'de+en']) && $activeLocale === 'de')
                <textarea id="event-schedule-de" wire:model="schedule_de" rows="4" placeholder="{{ __('events.placeholder_german_schedule') }}"
                          class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"></textarea>
                @error('schedule_de') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                @endif
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="event-contact-email" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('emails.field_contact_email') }}</label>
                    <input type="email" id="event-contact-email" wire:model="contact_email" placeholder="{{ __('events.placeholder_organizer_email') }}"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('contact_email') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="event-contact-phone" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.field_contact_phone') }}</label>
                    <input type="text" id="event-contact-phone" wire:model="contact_phone" placeholder="{{ __('events.placeholder_contact_phone') }}"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('contact_phone') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex items-center gap-6 pt-2">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" id="event-is-public" wire:model="is_public" class="rounded border-outline text-primary focus:ring-primary/20" />
                    <span class="text-sm text-on-surface-variant">{{ __('events.content_public_event') }}</span>
                </label>
            </div>
        </section>
        @endif

        {{-- Navigation Buttons --}}
        <div class="flex items-center justify-between">
            <div>
                @if($step > 1)
                    <button wire:click="previousStep"
                            class="px-4 py-2.5 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                        {{ __('common.content_back') }}
                    </button>
                @else
                    <a href="{{ route('events.index') }}" wire:navigate
                       class="px-4 py-2.5 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                        {{ __('common.action_cancel') }}
                    </a>
                @endif
            </div>
            <div>
                @if($step < self::MAX_STEPS)
                    <button wire:click="nextStep"
                            class="px-6 py-2.5 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-lg hover:opacity-90 transition-opacity text-sm font-medium">
                        {{ __('common.action_next') }}
                    </button>
                @else
                    <button wire:click="create" wire:loading.attr="disabled"
                            class="px-6 py-2.5 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-lg hover:opacity-90 transition-opacity text-sm font-medium">
                        <span wire:loading.remove>{{ __('events.action_create_event') }}</span>
                        <span wire:loading>{{ __('common.content_creating') }}</span>
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>

<div>
    {{-- Header --}}
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-on-surface leading-tight">
                    {{ __('Manage Event') }}
                </h2>
                <p class="text-sm text-on-surface-variant mt-1">{{ $event->name }}</p>
            </div>
            <div class="flex items-center gap-3">
                @if($event->is_public)
                    <a href="{{ route('events.detail', ['slug' => $event->slug]) }}" wire:navigate target="_blank"
                       class="text-sm text-primary hover:underline flex items-center gap-1">
                        {{ __('View Public Page') }}
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">open_in_new</span>
                    </a>
                @endif
                <a href="{{ route('events.manage-registrations', ['slug' => $event->slug]) }}" wire:navigate
                   class="text-sm px-3 py-1.5 rounded-lg bg-surface-container text-on-surface-variant hover:bg-surface-container-high transition-colors">
                    {{ __('Manage Registrations') }}
                </a>
            </div>
        </div>
    </x-slot>

    {{-- Flash --}}
    @if(session()->has('success'))
        <div class="mb-4 bg-secondary-container border border-secondary/20 rounded-lg p-3 text-sm text-on-secondary-container" role="status" aria-live="polite">
            {{ session('success') }}
        </div>
    @endif
    @if($saved)
        <div class="mb-4 bg-secondary-container border border-secondary/20 rounded-lg p-3 text-sm text-on-secondary-container">
            {{ __('Changes saved successfully.') }}
        </div>
    @endif

    {{-- Status Bar --}}
    <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 mb-6">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div class="flex items-center gap-3">
                @php
                    $statusColors = [
                        'draft' => 'bg-surface-container text-on-surface-variant',
                        'published' => 'bg-tertiary/10 text-on-tertiary-container',
                        'registration_open' => 'bg-secondary-container text-on-secondary-container',
                        'registration_closed' => 'bg-surface-container-high text-on-surface-variant',
                        'in_progress' => 'bg-primary/10 text-primary',
                        'completed' => 'bg-surface-container text-on-surface-variant',
                        'cancelled' => 'bg-error-container text-on-error-container',
                    ];
                @endphp
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold {{ $statusColors[$event->status] ?? 'bg-surface-container text-on-surface-variant' }}">
                    {{ ucfirst(str_replace('_', ' ', $event->status)) }}
                </span>
                <span class="text-sm text-on-surface-variant">
                    {{ $event->registrations()->count() }} {{ __('registration(s)') }}
                </span>
            </div>
            <div class="flex items-center gap-2">
                @if($event->status === 'draft')
                    <button wire:click="publishEvent" wire:confirm="{{ __('Publish this event?') }}"
                            class="px-3 py-1.5 rounded-lg text-sm font-medium bg-tertiary text-on-tertiary hover:opacity-90 transition-opacity">
                        {{ __('Publish') }}
                    </button>
                @endif
                @if(in_array($event->status, ['draft', 'published']))
                    <button wire:click="openRegistration" wire:confirm="{{ __('Open registration for this event?') }}"
                            class="px-3 py-1.5 rounded-lg text-sm font-medium bg-secondary text-on-secondary hover:opacity-90 transition-opacity">
                        {{ __('Open Registration') }}
                    </button>
                @endif
                @if($event->status === 'registration_open')
                    <button wire:click="closeRegistration" wire:confirm="{{ __('Close registration?') }}"
                            class="px-3 py-1.5 rounded-lg text-sm font-medium bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest transition-colors">
                        {{ __('Close Registration') }}
                    </button>
                @endif
                @if($event->status !== 'cancelled' && $event->status !== 'completed')
                    <button wire:click="cancelEvent" wire:confirm="{{ __('Cancel this event? This will notify registered participants.') }}"
                            class="px-3 py-1.5 rounded-lg text-sm font-medium bg-error-container text-on-error-container hover:opacity-90 transition-opacity">
                        {{ __('Cancel Event') }}
                    </button>
                @endif
            </div>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="bg-surface-container-low rounded-xl shadow-ambient mb-6">
        <div class="border-b border-outline-variant">
            <nav class="flex -mb-px">
                @foreach(['details' => __('Details'), 'venue' => __('Venue'), 'registration' => __('Registration & Fees'), 'divisions' => __('Divisions'), 'rules' => __('Rules & Settings')] as $tab => $label)
                    <button wire:click="setActiveTab('{{ $tab }}')"
                            class="px-4 py-3 text-sm font-medium border-b-2 transition-colors {{ $activeTab === $tab ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface hover:border-outline-variant' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </nav>
        </div>

        <div class="p-6">
            {{-- Details Tab --}}
            @if($activeTab === 'details')
                <div class="space-y-4">
                    <div>
                        <label for="content-language" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Content Language *') }}</label>
                        <select id="content-language" wire:model="content_language" class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm">
                            @foreach(\App\Enums\ContentLanguage::cases() as $lang)
                                <option value="{{ $lang->value }}">{{ $lang->label() }}</option>
                            @endforeach
                        </select>
                        @error('content_language') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="event-name" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Event Name *') }}</label>
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
                        <input type="text" id="event-name" wire:model="name"
                               class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                        @error('name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        @endif
                        @if(in_array($content_language, ['de', 'de+en']) && $activeLocale === 'de')
                        <input type="text" id="event-name-de" wire:model="name_de"
                               class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                        @error('name_de') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        @endif
                    </div>
                    <div>
                        <label for="event-short-description" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Short Description') }}</label>
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
                        <input type="text" id="event-short-description" wire:model="short_description" maxlength="500"
                               class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                        @error('short_description') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        @endif
                        @if(in_array($content_language, ['de', 'de+en']) && $activeLocale === 'de')
                        <input type="text" id="event-short-description-de" wire:model="short_description_de" maxlength="500"
                               class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                        @error('short_description_de') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        @endif
                    </div>
                    <div>
                        <label for="event-description" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Description') }}</label>
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
                        <textarea id="event-description" wire:model="description" rows="5"
                                  class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"></textarea>
                        @error('description') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        @endif
                        @if(in_array($content_language, ['de', 'de+en']) && $activeLocale === 'de')
                        <textarea id="event-description-de" wire:model="description_de" rows="5"
                                  class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"></textarea>
                        @error('description_de') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        @endif
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="event-type" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Type *') }}</label>
                            <select id="event-type" wire:model="type" class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm">
                                <option value="tournament">{{ __('Tournament') }}</option>
                                <option value="league">{{ __('League') }}</option>
                                <option value="camp">{{ __('Camp') }}</option>
                                <option value="clinic">{{ __('Clinic') }}</option>
                                <option value="social">{{ __('Social') }}</option>
                                <option value="other">{{ __('Other') }}</option>
                            </select>
                            @error('type') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="event-status" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Status') }}</label>
                            <select id="event-status" wire:model="status" class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm">
                                <option value="draft">{{ __('Draft') }}</option>
                                <option value="published">{{ __('Published') }}</option>
                                <option value="registration_open">{{ __('Registration Open') }}</option>
                                <option value="registration_closed">{{ __('Registration Closed') }}</option>
                                <option value="in_progress">{{ __('In Progress') }}</option>
                                <option value="completed">{{ __('Completed') }}</option>
                                <option value="cancelled">{{ __('Cancelled') }}</option>
                            </select>
                            @error('status') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="event-start-date" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Start Date *') }}</label>
                            <input type="date" id="event-start-date" wire:model="start_date"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                            @error('start_date') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="event-end-date" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('End Date *') }}</label>
                            <input type="date" id="event-end-date" wire:model="end_date"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                            @error('end_date') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
            @endif

            {{-- Venue Tab --}}
            @if($activeTab === 'venue')
                <div class="space-y-4">
                    <div>
                        <label for="event-venue-name" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Venue Name') }}</label>
                        <input type="text" id="event-venue-name" wire:model="venue_name"
                               class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                        @error('venue_name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="event-address" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Address') }}</label>
                        <textarea id="event-address" wire:model="venue_address" rows="2"
                                  class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"></textarea>
                        @error('venue_address') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label for="event-city" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('City') }}</label>
                            <input type="text" id="event-city" wire:model="city"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                            @error('city') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="event-country" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Country') }}</label>
                            <input type="text" id="event-country" wire:model="country" maxlength="3"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                            @error('country') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="event-postal-code" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Postal Code') }}</label>
                            <input type="text" id="event-postal-code" wire:model="postal_code"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                            @error('postal_code') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
            @endif

            {{-- Registration & Fees Tab --}}
            @if($activeTab === 'registration')
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Registration Type *') }}</label>
                        <div class="flex gap-3 mt-1">
                            @foreach(['team' => __('Team Only'), 'individual' => __('Individual Only'), 'both' => __('Both')] as $val => $label)
                                <button type="button" wire:click="$set('registration_type', '{{ $val }}')"
                                        class="px-4 py-2 rounded-lg text-sm font-medium border-2 transition-colors {{ $registration_type === $val ? 'border-primary bg-primary/10 text-primary' : 'border-outline text-on-surface-variant' }}">
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        @if(in_array($registration_type, ['team', 'both']))
                        <div>
                            <label for="event-max-teams" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Max Teams') }}</label>
                            <input type="number" id="event-max-teams" wire:model="max_teams" min="1"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                            @error('max_teams') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="event-min-players" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Min Players/Team') }}</label>
                            <input type="number" id="event-min-players" wire:model="min_players_per_team" min="1"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                        </div>
                        <div>
                            <label for="event-max-players" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Max Players/Team') }}</label>
                            <input type="number" id="event-max-players" wire:model="max_players_per_team" min="1"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                        </div>
                        @endif
                        @if(in_array($registration_type, ['individual', 'both']))
                        <div>
                            <label for="event-max-participants" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Max Participants') }}</label>
                            <input type="number" id="event-max-participants" wire:model="max_participants" min="1"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                            @error('max_participants') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                        @endif
                    </div>
                    <h3 class="text-md font-medium text-on-surface pt-2">{{ __('Fees') }} <span class="text-xs text-on-surface-variant">({{ __('enter amount in cents, e.g. 500 = $5.00') }})</span></h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        @if(in_array($registration_type, ['team', 'both']))
                        <div>
                            <label for="event-team-fee" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Team Fee') }}</label>
                            <input type="number" id="event-team-fee" wire:model="team_registration_fee" min="0"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                        </div>
                        @endif
                        @if(in_array($registration_type, ['individual', 'both']))
                        <div>
                            <label for="event-individual-fee" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Individual Fee') }}</label>
                            <input type="number" id="event-individual-fee" wire:model="individual_registration_fee" min="0"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                        </div>
                        @endif
                        <div>
                            <label for="event-early-bird-discount" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Early Bird Discount') }}</label>
                            <input type="number" id="event-early-bird-discount" wire:model="early_bird_discount" min="0"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                        </div>
                        <div>
                            <label for="event-early-bird-deadline" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Early Bird Deadline') }}</label>
                            <input type="datetime-local" id="event-early-bird-deadline" wire:model="early_bird_deadline"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                        </div>
                    </div>
                    <h3 class="text-md font-medium text-on-surface pt-2">{{ __('Registration Window') }}</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="event-reg-opens" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Opens At') }}</label>
                            <input type="datetime-local" id="event-reg-opens" wire:model="registration_opens_at"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                        </div>
                        <div>
                            <label for="event-reg-closes" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Closes At') }}</label>
                            <input type="datetime-local" id="event-reg-closes" wire:model="registration_closes_at"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                            @error('registration_closes_at') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
            @endif

            {{-- Divisions Tab --}}
            @if($activeTab === 'divisions')
                <div class="space-y-4">
                    <p class="text-sm text-on-surface-variant">{{ __('Add competitive divisions for your event.') }}</p>

                    <div class="bg-surface-container rounded-lg p-4 space-y-3">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <input type="text" wire:model="newDivisionName" placeholder="{{ __('Division name *') }}"
                                       class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                                @error('newDivisionName') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <input type="text" wire:model="newDivisionDescription" placeholder="{{ __('Description') }}"
                                       class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                            </div>
                            <button wire:click="addDivision" class="px-4 py-2 bg-surface-container-high text-on-surface rounded-lg hover:bg-surface-container-highest text-sm font-medium transition-colors inline-flex items-center gap-1">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">add</span>
                                {{ __('Add Division') }}
                            </button>
                        </div>
                    </div>

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
                                    <button wire:click="removeDivision({{ $i }})" wire:confirm="{{ __('Remove this division?') }}"
                                            class="text-on-surface-variant hover:text-error transition-colors" aria-label="{{ __('Remove division') }}">
                                        <span class="material-symbols-outlined text-xl" aria-hidden="true">delete</span>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-center text-on-surface-variant/50 text-sm py-4">{{ __('No divisions defined.') }}</p>
                    @endif
                </div>
            @endif

            {{-- Rules & Settings Tab --}}
            @if($activeTab === 'rules')
                <div class="space-y-4">
                    <div>
                        <label for="event-rules" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Rules') }} <span class="text-xs text-on-surface-variant">({{ __('one per line') }})</span></label>
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
                        <textarea id="event-rules" wire:model="rules" rows="5"
                                  class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"></textarea>
                        @endif
                        @if(in_array($content_language, ['de', 'de+en']) && $activeLocale === 'de')
                        <textarea id="event-rules-de" wire:model="rules_de" rows="5"
                                  class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"></textarea>
                        @error('rules_de') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        @endif
                    </div>
                    <div>
                        <label for="event-schedule" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Schedule') }} <span class="text-xs text-on-surface-variant">({{ __('one item per line') }})</span></label>
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
                        <textarea id="event-schedule" wire:model="schedule" rows="4"
                                  class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"></textarea>
                        @endif
                        @if(in_array($content_language, ['de', 'de+en']) && $activeLocale === 'de')
                        <textarea id="event-schedule-de" wire:model="schedule_de" rows="4"
                                  class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"></textarea>
                        @error('schedule_de') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        @endif
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="event-contact-email" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Contact Email') }}</label>
                            <input type="email" id="event-contact-email" wire:model="contact_email"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                            @error('contact_email') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="event-contact-phone" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('Contact Phone') }}</label>
                            <input type="text" id="event-contact-phone" wire:model="contact_phone"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                        </div>
                    </div>
                    <div class="flex items-center gap-6 pt-2">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="is_public" class="rounded border-outline text-primary focus:ring-primary/20" />
                            <span class="text-sm text-on-surface-variant">{{ __('Public Event') }}</span>
                        </label>
                        @if(auth()->user() && app(\App\Services\ScopedRoleService::class)->isGlobalAdmin(auth()->user()))
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="is_featured" class="rounded border-outline text-primary focus:ring-primary/20" />
                            <span class="text-sm text-on-surface-variant">{{ __('Featured') }}</span>
                        </label>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Save Button --}}
    <div class="flex items-center gap-4">
        <button wire:click="save" wire:loading.attr="disabled"
                class="px-6 py-2.5 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-lg hover:opacity-90 transition-opacity text-sm font-medium">
            <span wire:loading.remove>{{ __('Save Changes') }}</span>
            <span wire:loading>{{ __('Saving...') }}</span>
        </button>
        <a href="{{ route('events.index') }}" wire:navigate
           class="px-4 py-2.5 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
            {{ __('Back to Events') }}
        </a>
    </div>
</div>

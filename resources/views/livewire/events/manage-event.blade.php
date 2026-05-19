<div>
    {{-- Header --}}
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-on-surface leading-tight">
                    {{ __('events.action_manage_event') }}
                </h2>
                <p class="text-sm text-on-surface-variant mt-1">{{ $event->name }}</p>
            </div>
            <div class="flex items-center gap-3">
                @if($event->is_public)
                    <a href="{{ route('events.detail', ['slug' => $event->slug]) }}" wire:navigate target="_blank"
                       class="text-sm text-primary hover:underline flex items-center gap-1">
                        {{ __('common.action_view_public_page') }}
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">open_in_new</span>
                    </a>
                @endif
                <a href="{{ route('events.manage-registrations', ['slug' => $event->slug]) }}" wire:navigate
                   class="text-sm px-3 py-1.5 rounded-lg bg-surface-container text-on-surface-variant hover:bg-surface-container-high transition-colors">
                    {{ __('events.action_manage_registrations') }}
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
            {{ __('common.flash_changes_saved_successfully') }}
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
                    {{ trans_choice('events.content_count_registrations', $event->registrations()->count()) }}
                </span>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @if($event->status === 'draft')
                    <x-confirm-action
                        action="publishEvent"
                        id="publish-event"
                        :trigger-label="__('common.action_publish')"
                        trigger-class="px-3 py-1.5 rounded-lg text-sm font-medium bg-tertiary text-on-tertiary hover:opacity-90 transition-opacity"
                        :confirm-label="__('common.action_publish')"
                        :cancel-label="__('common.action_cancel')"
                        :message="__('events.action_publish_this_event')"
                        variant="standalone"
                        severity="neutral"
                        confirm-icon="publish"
                    />
                @endif
                @if(in_array($event->status, ['draft', 'published']))
                    <x-confirm-action
                        action="openRegistration"
                        id="open-registration"
                        :trigger-label="__('events.action_open_registration')"
                        trigger-class="px-3 py-1.5 rounded-lg text-sm font-medium bg-secondary text-on-secondary hover:opacity-90 transition-opacity"
                        :confirm-label="__('events.action_open_registration')"
                        :cancel-label="__('common.action_cancel')"
                        :message="__('events.action_open_registration_for_this_event')"
                        variant="standalone"
                        severity="neutral"
                        confirm-icon="how_to_reg"
                    />
                @endif
                @if($event->status === 'registration_open')
                    <x-confirm-action
                        action="closeRegistration"
                        id="close-registration"
                        :trigger-label="__('events.action_close_registration')"
                        trigger-class="px-3 py-1.5 rounded-lg text-sm font-medium bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest transition-colors"
                        :confirm-label="__('events.action_close_registration')"
                        :cancel-label="__('common.action_cancel')"
                        :message="__('events.action_confirm_close_registration')"
                        variant="standalone"
                        severity="caution"
                        confirm-icon="lock"
                    />
                @endif
                @if($event->status !== 'cancelled' && $event->status !== 'completed')
                    <x-confirm-action
                        action="cancelEvent"
                        id="cancel-event"
                        :trigger-label="__('events.action_cancel_event')"
                        trigger-class="px-3 py-1.5 rounded-lg text-sm font-medium bg-error-container text-on-error-container hover:opacity-90 transition-opacity"
                        :confirm-label="__('events.action_cancel_event')"
                        :cancel-label="__('common.action_keep')"
                        :message="__('events.content_cancel_this_event_this_will')"
                        variant="standalone"
                        severity="destructive"
                        confirm-icon="cancel"
                    />
                @endif
            </div>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="bg-surface-container-low rounded-xl shadow-ambient mb-6">
        <div class="border-b border-outline-variant">
            <nav class="flex -mb-px">
                @foreach(['details' => __('common.content_details'), 'venue' => __('location.content_venue'), 'registration' => __('billing.field_registration_fees'), 'divisions' => __('events.content_divisions'), 'rules' => __('profile.content_rules_settings')] as $tab => $label)
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
                        <label for="content-language" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('events.field_content_language') }}</label>
                        <select id="content-language" wire:model="content_language" class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm">
                            @foreach(\App\Enums\ContentLanguage::cases() as $lang)
                                <option value="{{ $lang->value }}">{{ $lang->label() }}</option>
                            @endforeach
                        </select>
                        @error('content_language') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="event-name" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('events.field_event_name') }}</label>
                        <input type="text" id="event-name" wire:model="name"
                               class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                        @error('name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="event-short-description" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.field_short_description') }}</label>
                        <input type="text" id="event-short-description" wire:model="short_description" maxlength="500"
                               class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                        @error('short_description') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="event-description" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.field_description') }}</label>
                        <textarea id="event-description" wire:model="description" rows="5"
                                  class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"></textarea>
                        @error('description') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="event-type" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('discovery.content_type') }}</label>
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
                        <div>
                            <label for="event-status" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.content_status') }}</label>
                            <select id="event-status" wire:model="status" class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm">
                                <option value="draft">{{ __('common.status_draft') }}</option>
                                <option value="published">{{ __('common.status_published') }}</option>
                                <option value="registration_open">{{ __('events.content_registration_open') }}</option>
                                <option value="registration_closed">{{ __('events.content_registration_closed') }}</option>
                                <option value="in_progress">{{ __('common.content_in_progress') }}</option>
                                <option value="completed">{{ __('common.status_completed') }}</option>
                                <option value="cancelled">{{ __('events.status_cancelled') }}</option>
                            </select>
                            @error('status') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
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
                </div>
            @endif

            {{-- Venue Tab --}}
            @if($activeTab === 'venue')
                <div class="space-y-4">
                    <div>
                        <label for="event-venue-name" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('location.field_venue_name') }}</label>
                        <input type="text" id="event-venue-name" wire:model="venue_name"
                               class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                        @error('venue_name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="event-address" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('location.field_address') }}</label>
                        <textarea id="event-address" wire:model="venue_address" rows="2"
                                  class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"></textarea>
                        @error('venue_address') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label for="event-city" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('location.field_city') }}</label>
                            <input type="text" id="event-city" wire:model="city"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                            @error('city') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="event-country" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('location.field_country') }}</label>
                            <input type="text" id="event-country" wire:model="country" maxlength="3"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                            @error('country') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="event-postal-code" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('location.field_postal_code') }}</label>
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
                        <label class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('events.field_registration_type') }}</label>
                        <div class="flex gap-3 mt-1">
                            @foreach(['team' => __('events.content_team_only'), 'individual' => __('common.content_individual_only'), 'both' => __('common.content_both')] as $val => $label)
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
                            <label for="event-max-teams" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('events.field_max_teams') }}</label>
                            <input type="number" id="event-max-teams" wire:model="max_teams" min="1"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                            @error('max_teams') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="event-min-players" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('events.field_min_players_team') }}</label>
                            <input type="number" id="event-min-players" wire:model="min_players_per_team" min="1"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                        </div>
                        <div>
                            <label for="event-max-players" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('events.field_max_players_team') }}</label>
                            <input type="number" id="event-max-players" wire:model="max_players_per_team" min="1"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                        </div>
                        @endif
                        @if(in_array($registration_type, ['individual', 'both']))
                        <div>
                            <label for="event-max-participants" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('events.field_max_participants') }}</label>
                            <input type="number" id="event-max-participants" wire:model="max_participants" min="1"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                            @error('max_participants') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                        @endif
                    </div>
                    <h3 class="text-md font-medium text-on-surface pt-2">{{ __('billing.field_fees') }} <span class="text-xs text-on-surface-variant">({{ __('common.action_enter_amount_in_cents_e_g_500_5_00') }})</span></h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        @if(in_array($registration_type, ['team', 'both']))
                        <div>
                            <label for="event-team-fee" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('billing.field_team_fee') }}</label>
                            <input type="number" id="event-team-fee" wire:model="team_registration_fee" min="0"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                        </div>
                        @endif
                        @if(in_array($registration_type, ['individual', 'both']))
                        <div>
                            <label for="event-individual-fee" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('billing.field_individual_fee') }}</label>
                            <input type="number" id="event-individual-fee" wire:model="individual_registration_fee" min="0"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                        </div>
                        @endif
                        <div>
                            <label for="event-early-bird-discount" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('billing.content_early_bird_discount') }}</label>
                            <input type="number" id="event-early-bird-discount" wire:model="early_bird_discount" min="0"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                        </div>
                        <div>
                            <label for="event-early-bird-deadline" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('billing.content_early_bird_deadline') }}</label>
                            <input type="datetime-local" id="event-early-bird-deadline" wire:model="early_bird_deadline"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                        </div>
                    </div>
                    <h3 class="text-md font-medium text-on-surface pt-2">{{ __('events.content_registration_window') }}</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="event-reg-opens" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.content_opens_at') }}</label>
                            <input type="datetime-local" id="event-reg-opens" wire:model="registration_opens_at"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                        </div>
                        <div>
                            <label for="event-reg-closes" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.content_closes_at') }}</label>
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
                    <p class="text-sm text-on-surface-variant">{{ __('events.action_add_competitive_divisions_for_your_event') }}</p>

                    <div class="bg-surface-container rounded-lg p-4 space-y-3">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <input type="text" wire:model="newDivisionName" placeholder="{{ __('events.placeholder_new_division_name') }}"
                                       class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                                @error('newDivisionName') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <input type="text" wire:model="newDivisionDescription" placeholder="{{ __('common.field_description') }}"
                                       class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                            </div>
                            <button wire:click="addDivision" class="px-4 py-2 bg-surface-container-high text-on-surface rounded-lg hover:bg-surface-container-highest text-sm font-medium transition-colors inline-flex items-center gap-1">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">add</span>
                                {{ __('events.action_add_division') }}
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
                                    <x-confirm-action
                                        action="removeDivision({{ $i }})"
                                        id="remove-division-{{ $i }}"
                                        :icon="'delete'"
                                        trigger-class="text-on-surface-variant hover:text-error transition-colors"
                                        :confirm-label="__('common.action_remove')"
                                        :cancel-label="__('common.action_keep')"
                                        variant="compact"
                                        severity="destructive"
                                    />
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-center text-on-surface-variant/50 text-sm py-4">{{ __('events.content_no_divisions_defined') }}</p>
                    @endif
                </div>
            @endif

            {{-- Rules & Settings Tab --}}
            @if($activeTab === 'rules')
                <div class="space-y-4">
                    <div>
                        <label for="event-rules" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.content_rules') }} <span class="text-xs text-on-surface-variant">({{ __('common.content_one_per_line') }})</span></label>
                        <textarea id="event-rules" wire:model="rules" rows="5"
                                  class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"></textarea>
                    </div>
                    <div>
                        <label for="event-schedule" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('campaigns.content_schedule') }} <span class="text-xs text-on-surface-variant">({{ __('common.content_one_item_per_line') }})</span></label>
                        <textarea id="event-schedule" wire:model="schedule" rows="4"
                                  class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"></textarea>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="event-contact-email" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('emails.field_contact_email') }}</label>
                            <input type="email" id="event-contact-email" wire:model="contact_email"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                            @error('contact_email') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="event-contact-phone" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.field_contact_phone') }}</label>
                            <input type="text" id="event-contact-phone" wire:model="contact_phone"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                        </div>
                    </div>
                    <div class="flex items-center gap-6 pt-2">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="is_public" class="rounded border-outline text-primary focus:ring-primary/20" />
                            <span class="text-sm text-on-surface-variant">{{ __('events.content_public_event') }}</span>
                        </label>
                        @if(auth()->user() && app(\App\Services\ScopedRoleService::class)->isGlobalAdmin(auth()->user()))
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="is_featured" class="rounded border-outline text-primary focus:ring-primary/20" />
                            <span class="text-sm text-on-surface-variant">{{ __('discovery.content_featured') }}</span>
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
                class="px-6 py-2.5 bg-primary text-on-primary rounded-lg hover:opacity-90 transition-opacity text-sm font-medium">
            <span wire:loading.remove>{{ __('common.action_save_changes') }}</span>
            <span wire:loading>{{ __('common.content_saving') }}</span>
        </button>
        <a href="{{ route('events.index') }}" wire:navigate
           class="px-4 py-2.5 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
            {{ __('events.action_back_to_events') }}
        </a>
    </div>
</div>

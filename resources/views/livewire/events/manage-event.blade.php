@section('title', __('events.action_manage_event'))

<div>
    {{-- Page header: in-page at every breakpoint (the layout `header` slot is
         desktop-only, which stranded the registrations link on mobile). Back
         goes to the public event page; the secondary "View Public Page" link
         was removed — same destination. --}}
    <x-page-header
        :title="__('events.action_manage_event')"
        :subtitle="$event->name"
        :backUrl="route('events.detail', ['slug' => $event->slug])"
        :backLabel="__('events.action_back_to_event', ['event' => $event->name])"
    >
        <x-slot:actions>
            <a href="{{ route('events.manage-registrations', ['slug' => $event->slug]) }}" wire:navigate
               class="text-sm px-3 py-2 rounded-lg bg-surface-container text-on-surface-variant hover:bg-surface-container-high transition-colors">
                {{ __('events.action_manage_registrations') }}
            </a>
        </x-slot:actions>
    </x-page-header>

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
    @if(session()->has('error'))
        <div class="mb-4 bg-error-container border border-error/20 rounded-lg p-3 text-sm text-on-error-container" role="alert">
            {{ session('error') }}
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
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold {{ $statusColors[$event->status->value] ?? 'bg-surface-container text-on-surface-variant' }}">
                    {{ ucfirst(str_replace('_', ' ', $event->status->value)) }}
                </span>
                <span class="text-sm text-on-surface-variant">
                    {{ trans_choice('events.content_count_registrations', $event->registrations()->count()) }}
                </span>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @if($event->status->value === 'draft')
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
                @if(in_array($event->status->value, ['draft', 'published']))
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
                @if($event->status->value === 'registration_open')
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
                @if($event->status->value !== 'cancelled' && $event->status->value !== 'completed')
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
            {{-- Scrollable rail: six tabs exceed a phone viewport; without
                 overflow handling the last tabs render off-screen and drag the
                 whole page into horizontal scroll (settings/show pattern). --}}
            <nav class="flex overflow-x-auto scrollbar-none" role="tablist" aria-label="{{ __('events.action_manage_event') }} sections">
                @foreach(['details' => __('common.content_details'), 'venue' => __('common.content_venue'), 'registration' => __('billing.field_registration_fees'), 'team' => __('events.content_team'), 'tables' => __('events.content_tables'), 'announcements' => __('events.content_announcements'), 'rules' => __('profile.content_rules_settings')] as $tab => $label)
                    @if($tab === 'announcements')
                        {{-- Announcements management lives on its own page (draft/
                             published workflow, per-announcement actions); the tab
                             routes there so the feature is reachable from the hub. --}}
                        <a href="{{ route('events.announcements', ['slug' => $event->slug]) }}" wire:navigate
                           class="px-4 py-3 text-sm font-medium border-b-2 whitespace-nowrap shrink-0 border-transparent text-on-surface-variant hover:text-on-surface hover:border-outline-variant transition-colors">
                            {{ $label }}
                        </a>
                    @else
                        <button wire:click="setActiveTab('{{ $tab }}')" role="tab" aria-selected="{{ $activeTab === $tab ? 'true' : 'false' }}"
                                class="px-4 py-3 text-sm font-medium border-b-2 whitespace-nowrap shrink-0 transition-colors {{ $activeTab === $tab ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface hover:border-outline-variant' }}">
                            {{ $label }}
                        </button>
                    @endif
                @endforeach
            </nav>
        </div>

        <div class="p-6">
            {{-- Details Tab --}}
            @if($activeTab === 'details')
                <div class="space-y-4">
                    <div>
                        <label for="content-language" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('events.field_content_language') }} <span class="text-error" title="{{ __('common.content_required') }}">*</span></label>
                        <select id="content-language" wire:model="language" class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-xs">
                            @foreach(\App\Enums\ContentLanguage::cases() as $lang)
                                <option value="{{ $lang->value }}">{{ $lang->label() }}</option>
                            @endforeach
                        </select>
                        @error('language') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>

                    {{-- Translatable fields (name + short_description + description) rendered via locale-aware section --}}
                    @php
                        $allLocales = $this->getAllLocales();
                        $baselineLocale = $this->getBaselineLocale();
                    @endphp
                    <x-forms.translatable-section
                        :fields="[
                            ['name' => 'name', 'label' => __('events.field_event_name')],
                            ['name' => 'short_description', 'label' => __('common.field_short_description'), 'maxlength' => 500],
                            ['name' => 'description', 'label' => __('common.field_description'), 'type' => 'textarea', 'rows' => 5],
                        ]"
                        :active-locale="$activeLocale"
                        :baseline-locale="$baselineLocale"
                        :all-locales="$allLocales"
                        :required="['name']"
                        inputClass="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-xs"
                    />
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="event-type" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('events.content_event_type') }} <span class="text-error" title="{{ __('common.content_required') }}">*</span></label>
                            <select id="event-type" wire:model="type" class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-xs">
                                @foreach(\App\Enums\EventType::cases() as $typeCase)
                                    <option value="{{ $typeCase->value }}">{{ $typeCase->label() }}</option>
                                @endforeach
                            </select>
                            @error('type') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="event-status" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.content_status') }}</label>
                            <select id="event-status" wire:model="status" class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-xs">
                                <option value="draft">{{ __('common.status_draft') }}</option>
                                <option value="published">{{ __('common.status_published') }}</option>
                                <option value="registration_open">{{ __('events.content_registration_open') }}</option>
                                <option value="registration_closed">{{ __('events.content_registration_closed') }}</option>
                                <option value="in_progress">{{ __('common.content_in_progress') }}</option>
                                <option value="completed">{{ __('common.status_completed') }}</option>
                                <option value="cancelled">{{ __('common.status_cancelled') }}</option>
                            </select>
                            @error('status') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="event-start-date" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.field_start_date') }} <span class="text-error" title="{{ __('common.content_required') }}">*</span></label>
                            <input type="date" id="event-start-date" wire:model="start_date"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-xs" />
                            @error('start_date') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="event-end-date" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.field_end_date') }} <span class="text-error" title="{{ __('common.content_required') }}">*</span></label>
                            <input type="date" id="event-end-date" wire:model="end_date"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-xs" />
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
                               class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-xs" />
                        @error('venue_name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="event-address" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('location.field_address') }}</label>
                        <textarea id="event-address" wire:model="venue_address" rows="2"
                                  class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-xs"></textarea>
                        @error('venue_address') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label for="event-city" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('location.field_city') }}</label>
                            <input type="text" id="event-city" wire:model="city"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-xs" />
                            @error('city') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="event-country" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('location.field_country') }}</label>
                            <input type="text" id="event-country" wire:model="country" maxlength="3"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-xs" />
                            @error('country') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="event-postal-code" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('location.field_postal_code') }}</label>
                            <input type="text" id="event-postal-code" wire:model="postal_code"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-xs" />
                            @error('postal_code') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
            @endif

            {{-- Registration & Fees Tab --}}
            @if($activeTab === 'registration')
                <div class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="event-max-participants" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('events.field_max_participants') }}</label>
                            <input type="number" id="event-max-participants" wire:model="max_participants" min="1"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-xs" />
                            @error('max_participants') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <h3 class="text-md font-medium text-on-surface pt-2">{{ __('billing.field_fees') }} <span class="text-xs text-on-surface-variant">({{ __('common.action_enter_amount_in_cents_e_g_500_5_00') }})</span></h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="event-individual-fee" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('billing.field_individual_fee') }}</label>
                            <input type="number" id="event-individual-fee" wire:model="individual_registration_fee" min="0"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-xs" />
                        </div>
                        <div>
                            <label for="event-early-bird-discount" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('billing.content_early_bird_discount') }}</label>
                            <input type="number" id="event-early-bird-discount" wire:model="early_bird_discount" min="0"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-xs" />
                        </div>
                        <div>
                            <label for="event-early-bird-deadline" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('billing.content_early_bird_deadline') }}</label>
                            <input type="datetime-local" id="event-early-bird-deadline" wire:model="early_bird_deadline"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-xs" />
                        </div>
                    </div>
                    {{-- Ticket payment: only meaningful once a fee is charged --}}
                    @if((int) $individual_registration_fee > 0)
                        <h3 class="text-md font-medium text-on-surface pt-2">{{ __('events.content_ticket_payment') }}</h3>
                        <div>
                            <label for="event-paddle-price-id" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('events.field_paddle_price_id') }}</label>
                            <input type="text" id="event-paddle-price-id" wire:model="paddle_price_id" maxlength="255"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-xs" />
                            <p class="mt-1 text-xs text-on-surface-variant">
                                {{ __('events.hint_paddle_price_id') }}
                                <a href="https://vendors.paddle.com/products" target="_blank" rel="noopener"
                                   class="text-primary hover:underline">{{ __('events.action_open_paddle_dashboard') }}</a>
                            </p>
                            @error('paddle_price_id') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                        @if(blank($paddle_price_id))
                            <div class="flex items-start gap-2 bg-error-container border border-error/20 rounded-lg p-3 text-sm text-on-error-container" role="alert">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">warning</span>
                                <span>{{ __('events.content_missing_paddle_price_id_hint') }}</span>
                            </div>
                        @endif
                    @endif
                    <h3 class="text-md font-medium text-on-surface pt-2">{{ __('events.content_registration_window') }}</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="event-reg-opens" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.content_opens_at') }}</label>
                            <input type="datetime-local" id="event-reg-opens" wire:model="registration_opens_at"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-xs" />
                        </div>
                        <div>
                            <label for="event-reg-closes" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.content_closes_at') }}</label>
                            <input type="datetime-local" id="event-reg-closes" wire:model="registration_closes_at"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-xs" />
                            @error('registration_closes_at') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
            @endif

            {{-- Team / Co-Organizers Tab (M063/S04) --}}
            @if($activeTab === 'team')
                <div class="space-y-6">
                    <div>
                        <h3 class="text-md font-medium text-on-surface">{{ __('events.content_co_organizers') }}</h3>
                        <p class="text-sm text-on-surface-variant mt-1">{{ __('events.content_team_invite_help') }}</p>
                    </div>

                    @if($this->coOrganizers->isEmpty())
                        <div class="rounded-lg border border-dashed border-outline-variant p-4 text-sm text-on-surface-variant">
                            {{ __('events.content_no_co_organizers_yet') }}
                        </div>
                    @else
                        <ul class="divide-y divide-outline-variant">
                            @foreach($this->coOrganizers as $coOrganizer)
                                <li class="flex items-center justify-between gap-3 py-3">
                                    <div class="flex items-center gap-3 min-w-0">
                                        <x-user-link :user="$coOrganizer" truncate />
                                        <span class="text-xs text-on-surface-variant whitespace-nowrap">
                                            {{ __('events.content_added') }} {{ $coOrganizer->granted_at?->translatedFormat('M j, Y') ?? '—' }}
                                        </span>
                                    </div>
                                    <x-confirm-action
                                        action="revokeCoOrganizer('{{ $coOrganizer->id }}')"
                                        id="revoke-co-organizer-{{ $coOrganizer->id }}"
                                        :trigger-label="__('common.action_remove')"
                                        trigger-class="px-3 py-1.5 rounded-lg text-sm font-medium bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest transition-colors"
                                        :confirm-label="__('common.action_remove')"
                                        :cancel-label="__('common.action_cancel')"
                                        :message="__('events.content_revoke_this_co_organizer', ['name' => $coOrganizer->name])"
                                        variant="inline"
                                        severity="destructive"
                                        confirm-icon="person_remove"
                                    />
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- Invite form: resolves by username (profile slug) or email --}}
                    <form wire:submit="inviteCoOrganizer" class="flex items-end gap-3">
                        <div class="flex-1">
                            <label for="co-organizer-invite" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('events.field_co_organizer_invite') }}</label>
                            <input type="text" id="co-organizer-invite" wire:model="coOrganizerInvite"
                                   placeholder="{{ __('events.placeholder_username_or_email') }}"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-xs" />
                            @error('coOrganizerInvite') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" wire:loading.attr="disabled"
                                class="px-4 py-2 bg-primary text-on-primary rounded-lg hover:opacity-90 transition-opacity text-sm font-medium whitespace-nowrap">
                            <span wire:loading.remove>{{ __('events.action_add_co_organizer') }}</span>
                            <span wire:loading>{{ __('common.content_saving') }}</span>
                        </button>
                    </form>
                </div>
            @endif

            {{-- Tables Tab (host-a-table, M063/S05) --}}
            @if($activeTab === 'tables')
                <div class="space-y-6">
                    <div class="flex items-start justify-between gap-3 flex-wrap">
                        <div>
                            <h3 class="text-md font-medium text-on-surface">{{ __('events.content_tables') }}</h3>
                            <p class="text-sm text-on-surface-variant mt-1 max-w-prose">{{ __('events.content_tables_help') }}</p>
                        </div>
                        {{-- Hosting is offered only while the event is published / open for
                             registration (Event::canHostTables) — closed, completed, cancelled,
                             and draft umbrellas never take new tables. --}}
                        @if($event->canHostTables())
                            <a href="{{ route('games.create', ['type' => 'gathering', 'event' => $event->slug]) }}" wire:navigate
                               class="inline-flex items-center gap-1.5 px-4 py-2 bg-primary text-on-primary rounded-lg hover:opacity-90 transition-opacity text-sm font-medium whitespace-nowrap">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">add</span>
                                {{ __('events.action_host_a_table') }}
                            </a>
                        @endif
                    </div>

                    @if($this->tables->isEmpty())
                        <div class="rounded-lg border border-dashed border-outline-variant p-4 text-sm text-on-surface-variant">
                            {{ __('events.content_no_tables_yet') }}
                        </div>
                    @else
                        <ul class="divide-y divide-outline-variant">
                            @foreach($this->tables as $table)
                                <li class="py-3">
                                    <div class="flex items-center justify-between gap-3">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                                <a href="{{ route('games.detail', ['locale' => app()->getLocale(), 'id' => $table]) }}" wire:navigate
                                                   class="text-sm font-medium text-on-surface hover:text-secondary transition-colors truncate">
                                                    {{ $table->name }}
                                                </a>
                                                @foreach($table->gameSystems as $system)
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container-high text-on-surface-variant">
                                                        {{ $system->name }}
                                                    </span>
                                                @endforeach
                                            </div>
                                            <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-on-surface-variant">
                                                @if($table->owner)
                                                    <span class="flex items-center gap-1">
                                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">person</span>
                                                        <x-user-link :user="$table->owner" truncate />
                                                    </span>
                                                @endif
                                                @if($table->date_time)
                                                    <span class="flex items-center gap-1">
                                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">calendar_today</span>
                                                        {{ format_date($table->date_time, 'datetime') }}
                                                    </span>
                                                @endif
                                                <span class="flex items-center gap-1">
                                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">group</span>
                                                    {{ $table->participants->count() }}/{{ $table->max_players ?? '∞' }}
                                                </span>
                                            </div>
                                        </div>
                                        <x-confirm-action
                                            action="detachTable('{{ $table->id }}')"
                                            id="detach-table-{{ $table->id }}"
                                            :trigger-label="__('events.action_detach')"
                                            trigger-class="px-3 py-1.5 rounded-lg text-sm font-medium bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest transition-colors whitespace-nowrap"
                                            :confirm-label="__('events.action_detach')"
                                            :cancel-label="__('common.action_cancel')"
                                            :message="__('events.content_detach_this_table_from_this_event', ['name' => $table->name])"
                                            variant="inline"
                                            severity="caution"
                                            confirm-icon="link_off"
                                        />
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            {{-- Rules & Settings Tab --}}
            @if($activeTab === 'rules')
                <div class="space-y-4">
                    <div>
                        <label for="event-rules" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.content_rules') }} <span class="text-xs text-on-surface-variant">({{ __('common.content_one_per_line') }})</span></label>
                        <textarea id="event-rules" wire:model="rules" rows="5"
                                  class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-xs"></textarea>
                    </div>
                    <div>
                        <label for="event-schedule" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('campaigns.content_schedule') }} <span class="text-xs text-on-surface-variant">({{ __('common.content_one_item_per_line') }})</span></label>
                        <textarea id="event-schedule" wire:model="schedule" rows="4"
                                  class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-xs"></textarea>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="event-contact-email" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('emails.field_contact_email') }}</label>
                            <input type="email" id="event-contact-email" wire:model="contact_email"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-xs" />
                            @error('contact_email') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="event-contact-phone" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.field_contact_phone') }}</label>
                            <input type="text" id="event-contact-phone" wire:model="contact_phone"
                                   class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-xs" />
                        </div>
                    </div>
                    <div class="flex items-center gap-6 pt-2">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="is_public" class="rounded-sm border-outline text-primary focus:ring-primary/20" />
                            <span class="text-sm text-on-surface-variant">{{ __('events.content_public_event') }}</span>
                        </label>
                        @if(auth()->user() && app(\App\Services\ScopedRoleService::class)->isGlobalAdmin(auth()->user()))
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model="is_featured" class="rounded-sm border-outline text-primary focus:ring-primary/20" />
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

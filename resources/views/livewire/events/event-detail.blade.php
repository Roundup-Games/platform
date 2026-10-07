<div>
    {{-- Back link --}}
    <div class="bg-surface-container-low border-b border-outline-variant">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-3">
            <a href="{{ route('events.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-on-surface transition-colors">
                <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span>
                {{ __('events.action_back_to_events') }}
            </a>
        </div>
    </div>

    {{-- Event Header / Banner --}}
    <section class="bg-primary text-on-primary">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-12 sm:py-16">
            <div class="flex flex-wrap items-center gap-2 mb-4">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-on-primary/20 text-on-primary">
                    {{ $event->type?->label() }}
                </span>
                @if($event->status->value === 'registration_open')
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-on-primary/30 text-on-primary">
                        {{ __('events.content_registration_open_badge') }}
                    </span>
                @elseif($event->status->value === 'in_progress')
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-on-primary/30 text-on-primary">
                        {{ __('common.content_in_progress_badge') }}
                    </span>
                @elseif($event->status->value === 'registration_closed')
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-on-primary/30 text-on-primary">
                        {{ __('events.content_registration_closed_badge') }}
                    </span>
                @endif
                @if($event->is_featured)
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-on-primary/10 text-on-primary">
                        {{ __('discovery.content_featured_badge') }}
                    </span>
                @endif
            </div>

            <h1 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight">{{ $event->name }}</h1>

            @if($event->short_description)
                <p class="mt-3 text-lg text-on-primary/80 max-w-3xl">{{ $event->short_description }}</p>
            @endif

            {{-- Quick info row --}}
            <div class="mt-6 flex flex-wrap gap-6 text-sm text-on-primary/80">
                {{-- Date --}}
                <span class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">calendar_today</span>
                    {{ format_date($event->start_date, 'date') }}
                    @if($event->end_date && $event->end_date->ne($event->start_date))
                        – {{ format_date($event->end_date, 'date') }}
                    @endif
                </span>

                {{-- Location — M053/S1/T06: routed through <x-location-display> (the sole
                     address-rendering authority) so no raw city leaks. Events carry
                     denormalized fields (no Location owner), so this uses the raw-city
                     path at City granularity. --}}
                @if($event->venue_name || $event->city || $event->country)
                    <span class="inline-flex">
                        <x-location-display
                            :venue-name="$event->venue_name"
                            :city="$event->city"
                            :country="$event->country"
                        />
                    </span>
                @endif

                {{-- Offering summary (M063/S06/T02, UI spec §3.3): the derived
                     union of every table's systems — "N games on hand · M
                     tables" — computed in memory by render()'s eager load
                     (zero extra queries). Hidden when no offering exists. --}}
                @if($offeredSystemsCount > 0)
                    <span class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">casino</span>
                        {{ trans_choice('games.content_n_games_on_offer', $offeredSystemsCount) }}
                        · {{ trans_choice('events.content_n_tables', $tablesTotal) }}
                    </span>
                @endif
            </div>
        </div>
    </section>

    {{-- Content --}}
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 bg-surface">

        {{-- Cancelled-event banner — sits above all content with release/refund
             copy for visitors and registrants (UI spec §3.3). --}}
        @if($event->status->value === 'cancelled')
            <div class="mb-6 flex items-start gap-3 px-4 py-3 bg-error-container text-on-error-container rounded-xl" role="alert">
                <span class="material-symbols-outlined text-xl shrink-0" aria-hidden="true">event_busy</span>
                <div>
                    <p class="text-sm font-medium">{{ __('events.content_this_get_together_was_cancelled') }}</p>
                    <p class="mt-1 text-sm opacity-90">
                        @if($userRegistration)
                            {{ __('events.content_cancelled_your_registration_released') }}
                        @else
                            {{ __('events.content_cancelled_registrations_released') }}
                        @endif
                    </p>
                </div>
            </div>
        @endif

        {{-- Language mismatch banner --}}
        <x-language-mismatch-banner :entity-language="$event->language" />

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            {{-- Main Content --}}
            <div class="lg:col-span-2 space-y-6">

                {{-- Description --}}
                @if($event->description)
                    <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                        <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4">{{ __('events.content_about_this_event') }}</h2>
                        <div class="prose prose-sm max-w-none text-on-surface-variant">
                            {{ $event->description }}
                        </div>
                    </section>
                @endif

                {{-- Tables — the map of the day (M063/S06/T01). Each table is an
                     ordinary game hosted under this umbrella: host identity with the
                     trust line from game cards, honest system chips (R051), seat
                     state with the capacity-bar treatment, and the join CTA routed
                     to the game's existing join flow. --}}
                <section class="bg-surface-container-low rounded-xl shadow-ambient p-6" aria-labelledby="event-tables-heading">
                    <div class="flex items-start justify-between gap-3 flex-wrap mb-4">
                        <h2 id="event-tables-heading" class="text-xl font-heading font-bold tracking-tight text-on-surface">
                            {{ __('events.content_tables_at_this_get_together') }}
                            @if($tablesTotal > 0)
                                <span class="ml-1 text-sm font-normal text-on-surface-variant">{{ trans_choice('events.content_n_tables', $tablesTotal) }}</span>
                            @endif
                        </h2>
                        @if($isEventManager && $event->canHostTables())
                            <a href="{{ route('games.create', ['type' => 'gathering', 'event' => $event->slug]) }}" wire:navigate
                               class="inline-flex items-center gap-1.5 px-4 py-2 bg-surface-container-high text-on-surface rounded-lg hover:bg-surface-container-highest transition-colors text-sm font-medium whitespace-nowrap">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">add</span>
                                {{ __('events.action_host_a_table') }}
                            </a>
                        @endif
                    </div>

                    @if($tablesTotal === 0)
                        <p class="text-sm text-on-surface-variant">
                            {{ $isEventManager
                                ? __('events.content_no_tables_yet_manager')
                                : __('events.content_tables_still_being_planned') }}
                        </p>
                    @else
                        <ul class="space-y-4">
                            @foreach($tables as $table)
                                @php
                                    $approvedSeats = (int) ($table->approved_participants_count ?? 0);
                                    $waitlistedSeats = (int) ($table->waitlisted_participants_count ?? 0);
                                    $seatMax = $table->max_players;
                                    $seatPct = $seatMax ? min(100, ($approvedSeats / $seatMax) * 100) : 0;
                                    $tableIsFull = $seatMax !== null && $approvedSeats >= $seatMax;
                                    $tableUrl = route('games.detail', ['locale' => app()->getLocale(), 'id' => $table]);
                                @endphp
                                <li class="rounded-lg border border-outline-variant/50 bg-surface p-4 sm:p-5">
                                    <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
                                        <div class="min-w-0 flex-1">
                                            {{-- Title + start time --}}
                                            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                                <a href="{{ $tableUrl }}" wire:navigate
                                                   class="font-medium text-on-surface hover:text-secondary transition-colors">
                                                    {{ $table->name }}
                                                </a>
                                                @if($table->date_time)
                                                    <span class="text-xs text-on-surface-variant">{{ format_date($table->date_time, 'datetime') }}</span>
                                                @endif
                                            </div>

                                            {{-- Host with the game-card trust line (public profile + GM badge) --}}
                                            @if($table->owner)
                                                <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                                                    <span class="text-on-surface-variant">{{ __('common.content_hosted_by') }}:</span>
                                                    <x-user-link :user="$table->owner" avatar-size="w-7 h-7" :truncate="true" />
                                                    @if($table->owner->isGM())
                                                        <x-gm-badge size="sm" />
                                                    @endif
                                                </div>
                                            @endif

                                            {{-- Honest multi-system chips (R051) --}}
                                            @if($table->gameSystems->isNotEmpty())
                                                <div class="mt-2.5 flex flex-wrap gap-1.5">
                                                    @foreach($table->gameSystems as $system)
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container-high text-on-surface-variant">
                                                            {{ $system->name }}
                                                        </span>
                                                    @endforeach
                                                </div>
                                            @endif

                                            {{-- Seat state + capacity bar (secondary → tertiary → error as it fills) --}}
                                            <div class="mt-3">
                                                <div class="flex flex-wrap items-center gap-2 text-xs text-on-surface-variant">
                                                    <span class="flex items-center gap-1">
                                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">group</span>
                                                        {{ $seatMax
                                                            ? __('events.content_seats_taken_max', ['taken' => $approvedSeats, 'max' => $seatMax])
                                                            : __('events.content_seats_taken', ['taken' => $approvedSeats]) }}
                                                    </span>
                                                    @if($waitlistedSeats > 0)
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-tertiary/10 text-tertiary">
                                                            {{ trans_choice('common.content_n_waitlisted', $waitlistedSeats) }}
                                                        </span>
                                                    @endif
                                                </div>
                                                @if($seatMax)
                                                    <div class="mt-1.5 w-full sm:max-w-xs bg-outline-variant/30 rounded-full h-1.5">
                                                        <div class="h-1.5 rounded-full {{ $seatPct >= 100 ? 'bg-error' : ($seatPct >= 70 ? 'bg-tertiary' : 'bg-secondary') }}" style="width: {{ $seatPct }}%"></div>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>

                                        {{-- Join CTA --}}
                                        <div class="shrink-0 sm:self-center">
                                            @guest
                                                {{-- Guests: registration-cta sign-up variant — the join flow stays behind auth --}}
                                                <a href="{{ route('register') }}" wire:navigate
                                                   class="inline-flex items-center justify-center gap-1.5 w-full sm:w-auto px-4 py-2 bg-surface-container-high text-on-surface rounded-lg hover:bg-surface-container-highest transition-colors text-sm font-medium">
                                                    <span class="material-symbols-outlined text-base" aria-hidden="true">person_add</span>
                                                    {{ __('events.content_sign_up_free_to_join_this_table') }}
                                                </a>
                                            @else
                                                @if($userRegistration)
                                                    {{-- Registered: straight into the game's join flow --}}
                                                    <a href="{{ $tableUrl }}" wire:navigate
                                                       class="inline-flex items-center justify-center gap-1.5 w-full sm:w-auto px-4 py-2 bg-primary text-on-primary rounded-lg hover:opacity-90 transition-opacity text-sm font-medium">
                                                        {{ $tableIsFull ? __('events.action_join_waitlist') : __('events.action_join_table') }}
                                                    </a>
                                                @else
                                                    {{-- D157 join nudge: authenticated but not registered — a
                                                         confirm-action-style dialog, never a hard block. --}}
                                                    <div x-data="{ confirming: false }" class="sm:text-right">
                                                        <button type="button" x-show="!confirming" @click="confirming = true"
                                                                class="inline-flex items-center justify-center gap-1.5 w-full sm:w-auto px-4 py-2 bg-primary text-on-primary rounded-lg hover:opacity-90 transition-opacity text-sm font-medium">
                                                            {{ $tableIsFull ? __('events.action_join_waitlist') : __('events.action_join_table') }}
                                                        </button>
                                                        <div x-show="confirming" x-cloak style="display: none" x-transition.opacity role="alert" aria-live="polite"
                                                             class="mt-2 p-3 rounded-lg bg-surface-container ring-1 ring-outline-variant/20 text-left">
                                                            <p class="text-sm text-on-surface">{{ __('events.content_join_nudge_no_event_spot') }}</p>
                                                            <div class="mt-3 flex flex-wrap gap-2">
                                                                <a href="{{ route('events.register', ['slug' => $event->slug]) }}" wire:navigate
                                                                   class="inline-flex items-center gap-1.5 px-4 py-2 bg-primary text-on-primary rounded-lg hover:opacity-90 transition-opacity text-sm font-medium">
                                                                    {{ __('events.action_register_first') }}
                                                                </a>
                                                                <a href="{{ $tableUrl }}" wire:navigate
                                                                   class="inline-flex items-center gap-1.5 px-4 py-2 bg-surface-container-high text-on-surface rounded-lg hover:bg-surface-container-highest transition-colors text-sm font-medium">
                                                                    {{ __('events.action_join_anyway') }}
                                                                </a>
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endif
                                            @endguest
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>

                        {{-- Page-weight guard: cap the initial map; the full list
                             stays one toggle away (UI spec §3.4). --}}
                        @if($tables->count() < $tablesTotal)
                            <button type="button" wire:click="$set('showAllTables', true)"
                                    class="mt-4 w-full text-center px-4 py-2.5 bg-surface-container-high text-on-surface rounded-lg hover:bg-surface-container-highest transition-colors text-sm font-medium">
                                {{ __('events.action_show_all_n_tables', ['count' => $tablesTotal]) }}
                            </button>
                        @elseif($this->showAllTables)
                            <button type="button" wire:click="$set('showAllTables', false)"
                                    class="mt-4 w-full text-center px-4 py-2.5 bg-surface-container-high text-on-surface rounded-lg hover:bg-surface-container-highest transition-colors text-sm font-medium">
                                {{ __('events.action_show_fewer_tables') }}
                            </button>
                        @endif
                    @endif
                </section>

                {{-- Schedule --}}
                @if($event->schedule && is_array($event->schedule) && count($event->schedule) > 0)
                    <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                        <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4">{{ __('campaigns.content_schedule') }}</h2>
                        <div class="space-y-3">
                            @foreach($event->schedule as $item)
                                <div class="flex items-start gap-3 py-2 {{ !$loop->last ? 'border-b border-outline-variant/50' : '' }}">
                                    <div class="w-2 h-2 mt-2 rounded-full bg-primary shrink-0"></div>
                                    <div>
                                        @if(is_array($item))
                                            <p class="text-sm font-medium text-on-surface">{{ $item['date'] ?? '' }} {{ $item['time'] ?? '' }}</p>
                                            <p class="text-sm text-on-surface-variant">{{ $item['event'] ?? $item['title'] ?? '' }}</p>
                                        @else
                                            <p class="text-sm text-on-surface-variant">{{ $item }}</p>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                {{-- Announcements --}}
                @if($announcements->count())
                    <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                        <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4">{{ __('events.content_announcements') }}</h2>
                        <div class="space-y-4">
                            @foreach($announcements as $announcement)
                                <div class="border-l-4 {{ $announcement->is_pinned ? 'border-primary bg-primary/5' : 'border-outline-variant' }} pl-4 py-2">
                                    <div class="flex items-center gap-2">
                                        <h3 class="font-semibold text-on-surface">{{ $announcement->title }}</h3>
                                        @if($announcement->is_pinned)
                                            <span class="text-xs text-primary">{{ __('common.content_pinned_badge') }}</span>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-sm text-on-surface-variant">{{ $announcement->content }}</p>
                                    <p class="mt-1 text-xs text-on-surface-variant/60">{{ format_date($announcement->created_at, 'datetime') }}</p>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>

            {{-- Sidebar --}}
            <div class="space-y-6">
                {{-- Registration Card --}}
                <div class="bg-surface-container-low rounded-xl shadow-ambient p-6 sticky top-6">
                    <h3 class="font-heading font-bold tracking-tight text-on-surface">{{ __('events.content_registration') }}</h3>

                    {{-- Status --}}
                    <div class="mt-4">
                        @if($event->isRegistrationOpen())
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-medium bg-secondary-container text-on-secondary-container">
                                <span class="w-2 h-2 rounded-full bg-on-secondary-container"></span>
                                {{ __('events.content_registration_open') }}
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-medium bg-surface-container text-on-surface-variant">
                                {{ __('events.content_registration') }} {{ __(ucfirst(str_replace('_', ' ', $event->status->value))) }}
                            </span>
                        @endif
                    </div>

                    {{-- Registration Window --}}
                    @if($event->registration_opens_at || $event->registration_closes_at)
                        <div class="mt-4 text-sm space-y-1">
                            @if($event->registration_opens_at)
                                <p class="text-on-surface-variant">
                                    <span class="font-medium text-on-surface">{{ __('common.content_opens') }}</span>
                                    {{ format_date($event->registration_opens_at, 'datetime') }}
                                </p>
                            @endif
                            @if($event->registration_closes_at)
                                <p class="text-on-surface-variant">
                                    <span class="font-medium text-on-surface">{{ __('common.content_closes') }}</span>
                                    {{ format_date($event->registration_closes_at, 'datetime') }}
                                </p>
                            @endif
                        </div>
                    @endif

                    {{-- Capacity --}}
                    <div class="mt-4">
                        <p class="text-sm font-medium text-on-surface mb-2">{{ __('location.field_capacity') }}</p>
                        <div class="flex items-center justify-between text-sm text-on-surface-variant">
                            <span>{{ __('common.content_participants') }}</span>
                            <span>{{ $individualCount }}{{ $event->max_participants ? '/' . $event->max_participants : '' }}</span>
                        </div>
                        @if($event->max_participants)
                            @php $indPct = min(100, ($individualCount / $event->max_participants) * 100) @endphp
                            <div class="mt-1 w-full bg-outline-variant/30 rounded-full h-2">
                                <div class="h-2 rounded-full {{ $indPct >= 90 ? 'bg-error' : ($indPct >= 70 ? 'bg-tertiary' : 'bg-secondary') }}" style="width: {{ $indPct }}%"></div>
                            </div>
                            @if($indPct >= 90)
                                <p class="text-xs text-error mt-1">{{ __('common.content_nearly_full') }}</p>
                            @endif
                        @endif
                    </div>

                    {{-- Fees --}}
                    <div class="mt-4 pt-4 border-t border-outline-variant">
                        <p class="text-sm font-medium text-on-surface mb-2">{{ __('billing.field_fees') }}</p>
                        @if($event->individual_registration_fee > 0)
                            <p class="text-sm text-on-surface-variant">
                                {{ __('common.field_individual_amount', ['amount' => format_currency($event->individual_registration_fee)]) }}
                                @if($event->early_bird_discount && $event->early_bird_deadline && now()->lt($event->early_bird_deadline))
                                    <span class="text-secondary ml-1">{{ __('billing.content_early_bird_amount', ['amount' => format_currency($event->early_bird_discount)]) }}</span>
                                @endif
                            </p>
                        @else
                            <p class="text-sm text-secondary font-medium">{{ __('common.price_free') }}</p>
                        @endif
                    </div>

                    {{-- Register button / registrant self-state (UI spec §3.5):
                         an authed registrant sees their own state instead of a
                         register CTA; the payment-pending variant carries the
                         instructions line. Cancelled events rely on the banner
                         instead of a self-state that would read as still-on. --}}
                    <div class="mt-6">
                        @if($userRegistration && $event->status->value !== 'cancelled')
                            <div class="rounded-lg bg-secondary-container/50 px-4 py-3 text-center">
                                @if($userRegistration->payment_status === 'pending')
                                    <p class="text-sm font-medium text-on-secondary-container inline-flex items-center justify-center gap-1.5">
                                        <span class="material-symbols-outlined text-base" aria-hidden="true">hourglass_top</span>
                                        {{ __('events.content_your_spot_is_reserved_payment_pending') }}
                                    </p>
                                    <p class="mt-1 text-xs text-on-surface-variant">{{ __('events.content_payment_pending_hint') }}</p>
                                @else
                                    <p class="text-sm font-medium text-on-secondary-container inline-flex items-center justify-center gap-1.5">
                                        <span class="material-symbols-outlined text-base" aria-hidden="true">check_circle</span>
                                        {{ __('events.content_you_re_registered') }}
                                    </p>
                                @endif
                            </div>
                        @elseif($event->isRegistrationOpen() && $event->hasCapacity())
                            @auth
                                <a href="{{ route('events.register', ['slug' => $event->slug]) }}" wire:navigate class="block w-full text-center px-4 py-3 bg-primary text-on-primary rounded-lg hover:opacity-90 transition-opacity font-medium">
                                    {{ __('events.action_register_now') }}
                                </a>
                            @else
                                <a href="{{ route('login') }}" wire:navigate class="block w-full text-center px-4 py-3 bg-primary text-on-primary rounded-lg hover:opacity-90 transition-opacity font-medium">
                                    {{ __('auth.content_sign_in_to_register') }}
                                </a>
                            @endauth
                        @elseif($event->isRegistrationOpen() && !$event->hasCapacity())
                            <button disabled class="block w-full text-center px-4 py-3 bg-surface-container text-on-surface-variant rounded-lg cursor-not-allowed font-medium">
                                {{ __('events.content_event_full') }}
                            </button>
                        @else
                            <button disabled class="block w-full text-center px-4 py-3 bg-surface-container text-on-surface-variant rounded-lg cursor-not-allowed font-medium">
                                {{ __('events.content_registration_closed') }}
                            </button>
                        @endif
                    </div>
                </div>

                {{-- Venue Card — M053/S1/T06: the full venue locality (name/address/city/
                     country/postal) now flows through <x-location-display> (the sole
                     address-rendering authority). Events carry denormalized fields (no
                     Location owner), so this uses the raw-city path at City granularity.
                     without-icon: the card heading carries its own location marker. --}}
                @if($event->venue_name || $event->venue_address || $event->city || $event->country || $event->postal_code)
                    <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                        <h3 class="font-heading font-bold tracking-tight text-on-surface flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg" aria-hidden="true">location_on</span>
                            {{ __('common.content_venue') }}
                        </h3>
                        <div class="mt-3 text-sm text-on-surface-variant">
                            <x-location-display
                                :venue-name="$event->venue_name"
                                :address="$event->venue_address"
                                :city="$event->city"
                                :postal-code="$event->postal_code"
                                :country="$event->country"
                                without-icon
                            />
                        </div>
                    </div>
                @endif

                {{-- Contact Card --}}
                @if($event->contact_email || $event->contact_phone)
                    <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                        <h3 class="font-heading font-bold tracking-tight text-on-surface flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg" aria-hidden="true">mail</span>
                            {{ __('common.content_contact') }}
                        </h3>
                        <div class="mt-3 text-sm space-y-1">
                            @if($event->contact_email)
                                <p class="text-on-surface-variant">
                                    <a href="mailto:{{ $event->contact_email }}" class="text-primary hover:underline">{{ $event->contact_email }}</a>
                                </p>
                            @endif
                            @if($event->contact_phone)
                                <p class="text-on-surface-variant">{{ $event->contact_phone }}</p>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

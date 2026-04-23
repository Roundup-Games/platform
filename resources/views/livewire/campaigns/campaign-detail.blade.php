<div>
    {{-- Back link --}}
    <div class="bg-surface-container-low border-b border-outline-variant">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 py-3">
            @guest
                <a href="{{ route('discover') }}" class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span>
                    {{ __('campaigns.action_back_to_discover') }}
                </a>
            @else
                <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span>
                    {{ __('profile.action_back_to_dashboard') }}
                </a>
            @endguest
        </div>
    </div>

    {{-- Campaign Header / Banner --}}
    <section class="bg-primary text-on-primary">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 py-10 sm:py-14">
            <div class="flex flex-wrap items-center gap-2 mb-4">
                @if($isOwner)
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-on-primary/20 text-on-primary">
                        {{ __('common.content_owner') }}
                    </span>
                @endif
                @if($campaign->gameSystem)
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-on-primary/10 text-on-primary">
                        {{ $campaign->gameSystem?->name }}
                    </span>
                @endif
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                    {{ $campaign->visibility === 'public' ? 'bg-on-primary/20 text-on-primary' : ($campaign->visibility === 'protected' ? 'bg-on-primary/30 text-on-primary' : 'bg-on-primary/10 text-on-primary') }}">
                    {{ __(ucfirst($campaign->visibility)) }}
                </span>
            </div>

            <h1 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight">{{ $campaign->name }}</h1>

            @if($campaign->description)
                <p class="mt-3 text-lg text-on-primary/80 max-w-3xl">{{ $campaign->description }}</p>
            @endif

            {{-- Quick info row --}}
            <div class="mt-6 flex flex-wrap gap-6 text-sm text-on-primary/80">
                <span class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">repeat</span>
                    {{ __(ucfirst($campaign->recurrence)) }}
                </span>
                <span class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">schedule</span>
                    {{ $campaign->time_of_day }}
                    @if($campaign->session_duration)
                        ({{ $campaign->session_duration }}h)
                    @endif
                </span>
                @if($campaign->price_per_session > 0)
                    <span class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">payments</span>
                        {{ format_currency($campaign->price_per_session, false) }}/{{ __('campaigns.content_session') }}
                    </span>
                @else
                    <span class="flex items-center gap-2 text-secondary">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">check_circle</span>
                        {{ __('billing.content_free') }}
                    </span>
                @endif
                @if($campaign->location && !empty($campaign->location['details']))
                    <span class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">location_on</span>
                        @if($isGuest)
                            @php
                                $cityOnly = trim(explode(',', $campaign->location['details'])[0]);
                            @endphp
                            {{ $cityOnly }}
                        @else
                            {{ $campaign->location['details'] }}
                        @endif
                    </span>
                @endif
            </div>
        </div>
    </section>

    {{-- Content --}}
    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-8 bg-surface space-y-6">

        {{-- Flash Messages --}}
        @if(session()->has('success'))
            <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 3000)" x-show="show"
                 class="rounded-xl bg-secondary-container p-4 flex items-center gap-3" role="status" aria-live="polite">
                <span class="material-symbols-outlined text-on-secondary-container" aria-hidden="true">check_circle</span>
                <p class="text-sm text-on-secondary-container">{{ session('success') }}</p>
            </div>
        @endif

        @if(session()->has('error'))
            <div class="rounded-xl bg-error-container p-4 flex items-center gap-3" role="alert" aria-live="polite">
                <span class="material-symbols-outlined text-on-error-container" aria-hidden="true">error</span>
                <p class="text-sm text-on-error-container">{{ session('error') }}</p>
            </div>
        @endif

        {{-- Registration CTA for guests --}}
        <x-registration-cta :message="__('campaigns.guest_nudge_campaign_detail')" />

        {{-- Invitation Banner --}}
        @if($userInvitation)
            <section class="bg-primary/5 border border-primary/20 rounded-xl shadow-ambient p-6">
                <div class="flex items-start gap-4">
                    <span class="material-symbols-outlined text-2xl text-primary mt-0.5" aria-hidden="true">mail</span>
                    <div class="flex-1">
                        <h2 class="text-lg font-heading font-bold text-on-surface">{{ __('common.action_accept_invitation') }}</h2>
                        <p class="mt-1 text-sm text-on-surface-variant">{{ __('people.content_you_have_been_invited') }}</p>
                        <div class="mt-4 flex gap-3">
                            <button wire:click="acceptInvitation('{{ $userInvitation->id }}')"
                                class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">check</span>
                                {{ __('common.action_accept') }}
                            </button>
                            <button wire:click="declineInvitation('{{ $userInvitation->id }}')"
                                wire:confirm="{{ __('people.flash_confirm_decline_invitation') }}"
                                class="inline-flex items-center gap-2 px-4 py-2 bg-surface-container-high text-on-surface-variant text-sm font-medium rounded-lg hover:bg-error-container hover:text-on-error-container transition-colors">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">close</span>
                                {{ __('common.action_decline') }}
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        @endif

        {{-- Upcoming Sessions --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">event_note</span>
                    {{ __('campaigns.content_sessions') }}
                </h2>
                @if($isOwner)
                    <a href="{{ route('campaigns.add-session', $campaign->id) }}" wire:navigate
                       class="inline-flex items-center gap-1 px-3 py-1.5 text-sm font-medium rounded-lg bg-primary text-on-primary hover:bg-primary/90 transition-colors">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">add</span>
                        {{ __('campaigns.action_add_session') }}
                    </a>
                @endif
            </div>

            @if($campaign->sessions->count())
                <div class="divide-y divide-outline-variant/30">
                    @foreach($campaign->sessions as $session)
                        <div class="flex items-center justify-between py-3 first:pt-0 last:pb-0">
                            <div>
                                <a href="{{ route('games.detail', $session->id) }}" wire:navigate class="text-sm font-medium text-on-surface hover:text-primary transition-colors">
                                    {{ $session->name }}
                                </a>
                                <p class="text-xs text-on-surface-variant">
                                    {{ format_date($session->date_time, 'datetime') }}
                                </p>
                            </div>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                {{ $session->status === 'scheduled' ? 'bg-tertiary/10 text-tertiary' : ($session->status === 'completed' ? 'bg-secondary-container text-on-secondary-container' : 'bg-surface-container-high text-on-surface-variant') }}">
                                {{ __(ucfirst($session->status)) }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-on-surface-variant italic py-4 text-center">{{ __('campaigns.content_no_sessions_scheduled_yet') }}</p>
            @endif
        </section>

        {{-- Participants --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-xl" aria-hidden="true">groups</span>
                {{ __('common.content_participants') }}
            </h2>

            @if($campaign->participants->count())
                <div class="divide-y divide-outline-variant/30">
                    @foreach($campaign->participants as $participant)
                        <div class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                            <x-user-link :user="$participant->user" avatar-size="w-10 h-10" :truncate="true" />
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                {{ $participant->role === 'gm' ? 'bg-primary/10 text-primary' : 'bg-surface-container-high text-on-surface-variant' }}">
                                {{ strtoupper($participant->role) }}
                            </span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                {{ $participant->status === 'confirmed' ? 'bg-secondary-container text-on-secondary-container' : 'bg-tertiary/10 text-tertiary' }}">
                                {{ __(ucfirst($participant->status)) }}
                            </span>
                        </div>
                    @endforeach>
                </div>
            @else
                <p class="text-sm text-on-surface-variant italic py-4 text-center">{{ __('common.content_no_participants_yet') }}</p>
            @endif
        </section>

        {{-- Apply / Join CTA --}}
        @auth
            @if($canApply)
                <section class="bg-primary/5 border border-primary/20 rounded-xl shadow-ambient p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-lg font-heading font-bold text-on-surface flex items-center gap-2">
                                <span class="material-symbols-outlined text-xl text-primary" aria-hidden="true">
                                    @if($campaign->visibility === 'public')
                                        login
                                    @else
                                        edit_note
                                    @endif
                                </span>
                                @if($campaign->visibility === 'public')
                                    {{ __('campaigns.action_join_campaign') }}
                                @else
                                    {{ __('campaigns.action_apply_to_join') }}
                                @endif
                            </h2>
                            @if($campaign->visibility === 'protected')
                                <p class="mt-1 text-sm text-on-surface-variant">{{ __('campaigns.content_this_is_a_protected_campaign') }}</p>
                            @endif
                        </div>
                        <a href="{{ route('campaigns.apply', ['locale' => app()->getLocale(), 'id' => $campaign->id]) }}"
                           wire:navigate
                           class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity">
                            <span class="material-symbols-outlined text-base" aria-hidden="true">
                                @if($campaign->visibility === 'public')
                                    login
                                @else
                                    send
                                @endif
                            </span>
                            @if($campaign->visibility === 'public')
                                {{ __('campaigns.action_join_campaign') }}
                            @else
                                {{ __('campaigns.action_apply_to_join') }}
                            @endif
                        </a>
                    </div>
                </section>
            @elseif($hasExistingApplication)
                <section class="bg-tertiary/5 border border-tertiary/20 rounded-xl shadow-ambient p-6 text-center">
                    <span class="material-symbols-outlined text-3xl text-tertiary mb-2" aria-hidden="true">schedule</span>
                    <p class="text-on-surface font-medium">{{ __('campaigns.content_application_pending') }}</p>
                    <p class="text-sm text-on-surface-variant mt-1">{{ __('campaigns.content_waiting_for_host_approval') }}</p>
                </section>
            @endif
        @else
            {{-- Guest CTA: show registration nudge --}}
            <x-registration-cta :message="__('campaigns.guest_nudge_join_campaign')" />
        @endauth

        {{-- Safety Tools --}}
        @if($campaign->safety_rules)
            @include('livewire.games.partials.safety-tools-display', ['safetyRules' => $campaign->safety_rules])
        @endif

        {{-- Reviews --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">rate_review</span>
                    {{ __('reviews.title_reviews') }}
                </h2>
                @auth
                    @if($canReview)
                        <a href="{{ route('reviews.write', ['reviewable_type' => 'campaign', 'reviewable_id' => $campaign->id]) }}" wire:navigate
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-lg bg-primary text-on-primary hover:opacity-90 transition-opacity">
                            <span class="material-symbols-outlined text-base" aria-hidden="true">edit</span>
                            {{ __('reviews.action_write_review') }}
                        </a>
                    @endif
                @endauth
            </div>

            @if($reviews->count())
                <div class="divide-y divide-outline-variant/30">
                    @foreach($reviews as $review)
                        @include('reviews.partials._review-card', ['review' => $review])
                    @endforeach
                </div>
            @else
                <p class="text-sm text-on-surface-variant italic py-4 text-center">{{ __('reviews.content_no_reviews_yet') }}</p>
            @endif
        </section>

        {{-- Campaign Owner Info --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-xl" aria-hidden="true">person</span>
                {{ __('common.content_run_by') }}
            </h2>
            <div class="flex items-center gap-4">
                <x-user-link :user="$campaign->owner" avatar-size="w-12 h-12" />
                @if($campaign->owner->isGM())
                    <x-gm-badge size="sm" />
                @endif
            </div>
        </section>
    </div>
</div>

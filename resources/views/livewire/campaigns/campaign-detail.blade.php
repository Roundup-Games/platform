<div>
    {{-- Back link --}}
    <div class="bg-surface-container-low border-b border-outline-variant">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-3">
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

    {{-- ── Hero ─────────────────────────────────────────────── --}}
    <section class="relative bg-primary text-on-primary overflow-hidden">
        @php($coverUrl = $campaign->gameSystem?->getFirstMediaUrl('cover'))
        @if(!$coverUrl && $campaign->gameSystem?->thumbnail_url)
            @php($coverUrl = $campaign->gameSystem->thumbnail_url)
        @endif
        @if($coverUrl)
            <div class="absolute inset-0">
                <img src="{{ $coverUrl }}" alt="" class="w-full h-full object-cover opacity-95 blur-sm scale-105" aria-hidden="true">
            </div>
            <div class="absolute inset-0 bg-gradient-to-b from-primary/85 via-primary/95 to-primary"></div>
        @endif

        <div class="relative max-w-5xl mx-auto px-4 sm:px-6 py-10 sm:py-14 lg:py-16">
            <div class="flex flex-wrap items-center gap-2 mb-4">
                @if($isOwner)
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-on-primary/20 text-on-primary">{{ __('common.content_owner') }}</span>
                @endif
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                    {{ $campaign->visibility->value === 'public' ? 'bg-on-primary/20 text-on-primary' : ($campaign->visibility->value === 'protected' ? 'bg-on-primary/30 text-on-primary' : 'bg-on-primary/10 text-on-primary') }}">
                    {{ __(ucfirst($campaign->visibility->value)) }}
                </span>
            </div>

            <h1 class="text-3xl sm:text-4xl lg:text-5xl font-heading font-bold tracking-tight leading-tight">{{ $campaign->name }}</h1>

            <div class="mt-6 flex flex-wrap gap-x-6 gap-y-2 text-sm text-on-primary/80">
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
                            {{ trim(explode(',', $campaign->location['details'])[0]) }}
                        @else
                            {{ $campaign->location['details'] }}
                        @endif
                    </span>
                @endif
            </div>
        </div>
    </section>

    {{-- ── Description (featured section) ──────────────────── --}}
    @if($campaign->description)
        <section class="bg-surface-container-low">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
                <div class="max-w-3xl text-base sm:text-lg text-on-surface leading-relaxed">
                    {!! nl2br(e($campaign->description)) !!}
                </div>
            </div>
        </section>
    @endif

    {{-- ── Content ──────────────────────────────────────────── --}}
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8 bg-surface space-y-6">

        <x-language-mismatch-banner :entity-language="$campaign->language" />

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

        {{-- Waitlist Position Banner --}}
        @if($userWaitlistParticipant && $waitlistPosition)
            <section class="bg-tertiary/5 border border-tertiary/20 rounded-xl shadow-ambient p-6">
                <div class="flex items-start gap-4">
                    <span class="material-symbols-outlined text-2xl text-tertiary mt-0.5" aria-hidden="true">playlist_add</span>
                    <div class="flex-1">
                        <h2 class="text-lg font-heading font-bold text-on-surface">{{ __('campaigns.action_join_waitlist') }}</h2>
                        <p class="mt-1 text-sm text-on-surface-variant">{{ __('campaigns.content_waitlist_position', ['position' => $waitlistPosition]) }}</p>
                        <button wire:click="leaveWaitlist('{{ $userWaitlistParticipant->id }}')"
                            wire:confirm="{{ __('games.flash_confirm_leave_waitlist') }}"
                            class="mt-3 inline-flex items-center gap-1 text-sm text-error hover:text-error/80 underline underline-offset-2 transition-colors">
                            <span class="material-symbols-outlined text-sm" aria-hidden="true">logout</span>
                            {{ __('campaigns.action_leave_waitlist') }}
                        </button>
                    </div>
                </div>
            </section>
        @endif

        {{-- Waitlist Confirmation Banner --}}
        @if($userPendingParticipant && $userPendingParticipant->confirmation_expires_at)
            <section class="bg-secondary-container/50 border border-secondary/20 rounded-xl shadow-ambient p-6">
                <div class="flex items-start gap-4">
                    <span class="material-symbols-outlined text-2xl text-on-secondary-container mt-0.5" aria-hidden="true">event_available</span>
                    <div class="flex-1">
                        <h2 class="text-lg font-heading font-bold text-on-surface">{{ __('games.action_confirm_spot') }}</h2>
                        <p class="mt-1 text-sm text-on-surface-variant">
                            {{ __('games.content_spot_opened_confirm', ['deadline' => $userPendingParticipant->confirmation_expires_at->isoFormat('LLL')]) }}
                        </p>
                        <div class="mt-4 flex gap-3">
                            <button wire:click="confirmWaitlistSpot('{{ $userPendingParticipant->id }}')"
                                class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">check</span>
                                {{ __('games.action_confirm_spot') }}
                            </button>
                            <button wire:click="declineWaitlistSpot('{{ $userPendingParticipant->id }}')"
                                wire:confirm="{{ __('people.flash_confirm_decline_invitation') }}"
                                class="inline-flex items-center gap-2 px-4 py-2 bg-surface-container-high text-on-surface-variant text-sm font-medium rounded-lg hover:bg-error-container hover:text-on-error-container transition-colors">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">close</span>
                                {{ __('games.action_decline_spot') }}
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        @endif

        {{-- Benched Banner --}}
        @if($userBenchParticipant)
            <section class="bg-tertiary/5 border border-tertiary/20 rounded-xl shadow-ambient p-6">
                <div class="flex items-start gap-4">
                    <span class="material-symbols-outlined text-2xl text-tertiary mt-0.5" aria-hidden="true">event_seat</span>
                    <div class="flex-1">
                        <h2 class="text-lg font-heading font-bold text-on-surface">{{ __('campaigns.content_you_are_on_the_bench') }}</h2>
                        <p class="mt-1 text-sm text-on-surface-variant">{{ __('campaigns.content_you_have_been_placed_on_the_bench') }}</p>
                        <button wire:click="leaveBench('{{ $userBenchParticipant->id }}')"
                            wire:confirm="{{ __('games.flash_confirm_leave_bench') }}"
                            class="mt-3 inline-flex items-center gap-1 text-sm text-error hover:text-error/80 underline underline-offset-2 transition-colors">
                            <span class="material-symbols-outlined text-sm" aria-hidden="true">logout</span>
                            {{ __('games.action_leave_bench') }}
                        </button>
                    </div>
                </div>
            </section>
        @endif

        {{-- ── Game System Info Card ─────────────────────────── --}}
        @if($campaign->gameSystem)
            @include('livewire.partials.game-system-info', ['entity' => $campaign])
        @endif

        {{-- ── Two-column layout on desktop ──────────────── --}}
        <div class="lg:grid lg:grid-cols-3 lg:gap-8 space-y-6 lg:space-y-0">

            {{-- Main column --}}
            <div class="lg:col-span-2 space-y-6">

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
                                        <p class="text-xs text-on-surface-variant">{{ format_date($session->date_time, 'datetime') }}</p>
                                    </div>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                        {{ $session->status->value === 'scheduled' ? 'bg-tertiary/10 text-tertiary' : ($session->status->value === 'completed' ? 'bg-secondary-container text-on-secondary-container' : 'bg-surface-container-high text-on-surface-variant') }}">
                                        {{ __(ucfirst($session->status->value)) }}
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
                                        {{ $participant->status === \App\Enums\ParticipantStatus::Approved ? 'bg-secondary-container text-on-secondary-container' : ($participant->status === \App\Enums\ParticipantStatus::Benched ? 'bg-tertiary/10 text-tertiary' : 'bg-tertiary/10 text-tertiary') }}">
                                        {{ $participant->status instanceof \BackedEnum ? $participant->status->label() : __(ucfirst($participant->status)) }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-on-surface-variant italic py-4 text-center">{{ __('common.content_no_participants_yet') }}</p>
                    @endif
                </section>

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
            </div>

            {{-- Sidebar --}}
            <aside class="space-y-6">

                {{-- Join via Share Link CTA --}}
                @auth
                    @if($canJoinViaShareLink)
                        <div class="bg-primary/5 border border-primary/20 rounded-xl shadow-ambient p-6">
                            <h3 class="text-lg font-heading font-bold text-on-surface flex items-center gap-2 mb-2">
                                <span class="material-symbols-outlined text-xl text-primary" aria-hidden="true">link</span>
                                @if($isCampaignFull)
                                    {{ __('campaigns.action_join_waitlist') }}
                                @else
                                    {{ __('campaigns.action_join_via_share_link') }}
                                @endif
                            </h3>
                            <button wire:click="joinViaShareLink"
                                class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 bg-primary text-on-primary text-sm font-semibold rounded-xl shadow-ambient hover:brightness-110 transition-all">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">login</span>
                                @if($isCampaignFull)
                                    {{ __('campaigns.action_join_waitlist') }}
                                @else
                                    {{ __('campaigns.action_join_campaign') }}
                                @endif
                            </button>
                        </div>
                    @endif
                @endauth

                {{-- Join / Apply CTA --}}
                @auth
                    @if($canApplyDirectly && !$canJoinViaShareLink)
                        <div class="bg-primary/5 border border-primary/20 rounded-xl shadow-ambient p-6">
                            <h3 class="text-lg font-heading font-bold text-on-surface flex items-center gap-2 mb-2">
                                <span class="material-symbols-outlined text-xl text-primary" aria-hidden="true">
                                    @if($campaign->visibility->value === 'public') login @else edit_note @endif
                                </span>
                                @if($campaign->visibility->value === 'public')
                                    {{ __('campaigns.action_join_campaign') }}
                                @else
                                    {{ __('campaigns.action_apply_to_join') }}
                                @endif
                            </h3>
                            @if($campaign->visibility->value === 'protected')
                                <p class="text-sm text-on-surface-variant mb-4">{{ __('campaigns.content_this_is_a_protected_campaign') }}</p>
                            @endif
                            <a href="{{ route('campaigns.apply', ['locale' => app()->getLocale(), 'id' => $campaign->id]) }}" wire:navigate
                               class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 bg-primary text-on-primary text-sm font-semibold rounded-xl shadow-ambient hover:brightness-110 transition-all">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">
                                    @if($campaign->visibility->value === 'public') login @else send @endif
                                </span>
                                @if($campaign->visibility->value === 'public')
                                    {{ __('campaigns.action_join_campaign') }}
                                @else
                                    {{ __('campaigns.action_apply_to_join') }}
                                @endif
                            </a>
                        </div>
                    @elseif($hasExistingApplication)
                        <div class="bg-tertiary/5 border border-tertiary/20 rounded-xl shadow-ambient p-6 text-center">
                            <span class="material-symbols-outlined text-3xl text-tertiary mb-2" aria-hidden="true">schedule</span>
                            <p class="text-on-surface font-medium">{{ __('campaigns.content_application_pending') }}</p>
                            <p class="text-sm text-on-surface-variant mt-1">{{ __('campaigns.content_waiting_for_host_approval') }}</p>
                        </div>
                    @elseif($canJoinWaitlist)
                        <div class="bg-primary/5 border border-primary/20 rounded-xl shadow-ambient p-6">
                            <h3 class="text-lg font-heading font-bold text-on-surface flex items-center gap-2 mb-2">
                                <span class="material-symbols-outlined text-xl text-primary" aria-hidden="true">playlist_add</span>
                                {{ __('campaigns.action_join_waitlist') }}
                            </h3>
                            <p class="text-sm text-on-surface-variant mb-4">{{ __('campaigns.content_campaign_full_join_waitlist') }}</p>
                            <button wire:click="joinWaitlist"
                                class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 bg-primary text-on-primary text-sm font-semibold rounded-xl shadow-ambient hover:brightness-110 transition-all">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">playlist_add</span>
                                {{ __('campaigns.action_join_waitlist') }}
                            </button>
                        </div>
                    @endif
                @else
                    <x-registration-cta :message="__('campaigns.guest_nudge_join_campaign')" />
                @endauth

                {{-- Organizer --}}
                <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                    <h3 class="text-base font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">person</span>
                        {{ __('common.content_run_by') }}
                    </h3>
                    <div class="flex items-center gap-3">
                        <x-user-link :user="$campaign->owner" avatar-size="w-11 h-11" />
                        @if($campaign->owner->isGM())
                            <x-gm-badge size="sm" />
                        @endif
                    </div>
                </div>

                {{-- Manage Participants (owner only) --}}
                @if($isOwner)
                    <a href="{{ route('campaigns.manage-participants', ['locale' => app()->getLocale(), 'id' => $campaign->id]) }}" wire:navigate
                       class="block bg-surface-container-low rounded-xl shadow-ambient p-4 hover:bg-surface-container-high transition-colors group">
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-xl text-on-surface-variant group-hover:text-primary transition-colors" aria-hidden="true">group</span>
                            <div class="flex-1 min-w-0">
                                <span class="text-sm font-medium text-on-surface">{{ __('events.action_manage_participants') }}</span>
                                <p class="text-xs text-on-surface-variant">{{ trans_choice('common.content_count_participants', $campaign->participants->count()) }}</p>
                            </div>
                            <span class="material-symbols-outlined text-lg text-on-surface-variant" aria-hidden="true">chevron_right</span>
                        </div>
                    </a>
                @endif

                {{-- Waitlist Management (owner only, non-bench campaigns) --}}
                @if($isOwner && $waitlistedPlayers->count())
                    <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                        <h3 class="text-base font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg" aria-hidden="true">playlist_add</span>
                            {{ __('campaigns.content_waitlist_management') }}
                        </h3>
                        <div class="divide-y divide-outline-variant/30">
                            @foreach($waitlistedPlayers as $waitlisted)
                                <div class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                                    <div class="flex-1 min-w-0">
                                        <x-user-link :user="$waitlisted->user" avatar-size="w-9 h-9" :truncate="true" />
                                        <p class="text-xs text-on-surface-variant ml-11">
                                            {{ __('campaigns.content_waitlist_position', ['position' => $loop->iteration]) }}
                                        </p>
                                    </div>
                                    <button wire:click="manualPromote('{{ $waitlisted->id }}')"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg bg-primary text-on-primary hover:opacity-90 transition-opacity">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">arrow_upward</span>
                                        {{ __('campaigns.action_manual_promote') }}
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Bench Management (owner only, bench-mode campaigns) --}}
                @if($isOwner && $benchedPlayers->count())
                    <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                        <h3 class="text-base font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg" aria-hidden="true">event_seat</span>
                            {{ __('campaigns.content_bench') }}
                        </h3>
                        <p class="text-xs text-on-surface-variant mb-3">{{ __('campaigns.content_bench_description') }}</p>
                        <div class="divide-y divide-outline-variant/30">
                            @foreach($benchedPlayers as $benched)
                                <div class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                                    <div class="flex-1 min-w-0">
                                        <x-user-link :user="$benched->user" avatar-size="w-9 h-9" :truncate="true" />
                                    </div>
                                    <button wire:click="promoteFromBench('{{ $benched->id }}')"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg bg-primary text-on-primary hover:opacity-90 transition-opacity">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">arrow_upward</span>
                                        {{ __('campaigns.action_promote_from_bench') }}
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Share Link Management (owner only) --}}
                @if($isOwner)
                    <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                        @include('livewire.partials.share-link', ['hasShareLink' => $hasShareLink, 'shareLinkUrl' => $shareLinkUrl])
                    </div>
                @endif
            </aside>
        </div>
    </div>

    {{-- Mobile sticky CTA --}}
    @auth
        @if($canJoinViaShareLink)
            <div class="lg:hidden sticky bottom-0 z-30 bg-surface/95 backdrop-blur-md border-t border-outline-variant px-4 py-3">
                <button wire:click="joinViaShareLink"
                   class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 bg-primary text-on-primary text-sm font-semibold rounded-xl shadow-lg hover:brightness-110 transition-all">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">login</span>
                    @if($isCampaignFull)
                        {{ __('campaigns.action_join_waitlist') }}
                    @else
                        {{ __('campaigns.action_join_campaign') }}
                    @endif
                </button>
            </div>
        @elseif($canApplyDirectly)
            <div class="lg:hidden sticky bottom-0 z-30 bg-surface/95 backdrop-blur-md border-t border-outline-variant px-4 py-3">
                <a href="{{ route('campaigns.apply', ['locale' => app()->getLocale(), 'id' => $campaign->id]) }}" wire:navigate
                   class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 bg-primary text-on-primary text-sm font-semibold rounded-xl shadow-lg hover:brightness-110 transition-all">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">
                        @if($campaign->visibility->value === 'public') login @else send @endif
                    </span>
                    @if($campaign->visibility->value === 'public')
                        {{ __('campaigns.action_join_campaign') }}
                    @else
                        {{ __('campaigns.action_apply_to_join') }}
                    @endif
                </a>
            </div>
        @elseif($canJoinWaitlist)
            <div class="lg:hidden sticky bottom-0 z-30 bg-surface/95 backdrop-blur-md border-t border-outline-variant px-4 py-3">
                <button wire:click="joinWaitlist"
                   class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 bg-primary text-on-primary text-sm font-semibold rounded-xl shadow-lg hover:brightness-110 transition-all">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">playlist_add</span>
                    {{ __('campaigns.action_join_waitlist') }}
                </button>
            </div>
        @endif
    @endauth
</div>

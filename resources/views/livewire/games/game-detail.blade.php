<div>
    {{-- Back link --}}
    <div class="bg-surface-container-low border-b border-outline-variant">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-3">
            @guest
                <a href="{{ route('discover') }}" class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span>
                    {{ __('games.action_back_to_discover') }}
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
        @php($coverUrl = $game->gameSystem?->getFirstMediaUrl('cover'))
        @if(!$coverUrl && $game->gameSystem?->thumbnail_url)
            @php($coverUrl = $game->gameSystem->thumbnail_url)
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
                @if($game->game_type)
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-on-primary/20 text-on-primary">
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">{{ $game->game_type->value === 'board_game' ? 'casino' : 'auto_stories' }}</span>
                        {{ __('games.type_' . $game->game_type->value) }}
                    </span>
                @endif
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                    {{ $game->visibility === 'public' ? 'bg-on-primary/20 text-on-primary' : ($game->visibility === 'protected' ? 'bg-on-primary/30 text-on-primary' : 'bg-on-primary/10 text-on-primary') }}">
                    {{ __('games.visibility_' . $game->visibility) }}
                </span>
            </div>

            @if($game->campaign)
                <a href="{{ route('campaigns.detail', $game->campaign->id) }}" wire:navigate class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-on-primary/20 text-on-primary hover:bg-on-primary/30 transition-colors mb-3">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">campaign</span>
                    {{ __('games.content_part_of_campaign_name', ['name' => $game->campaign?->name]) }}
                </a>
            @endif

            <h1 class="text-3xl sm:text-4xl lg:text-5xl font-heading font-bold tracking-tight leading-tight">{{ $game->name }}</h1>

            <div class="mt-6 flex flex-wrap gap-x-6 gap-y-2 text-sm text-on-primary/80">
                <span class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">calendar_today</span>
                    {{ format_date($game->date_time, 'datetime') }}
                </span>
                @if($game->expected_duration)
                    <span class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">schedule</span>
                        {{ $game->expected_duration }}h
                    </span>
                @endif
                @if($game->price > 0)
                    <span class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">payments</span>
                        {{ format_currency($game->price, false) }}
                    </span>
                @else
                    <span class="flex items-center gap-2 text-secondary">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">check_circle</span>
                        {{ __('billing.content_free') }}
                    </span>
                @endif
                @if($game->location && !empty($game->location['details']))
                    <span class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">location_on</span>
                        @if($isGuest)
                            {{ trim(explode(',', $game->location['details'])[0]) }}
                        @else
                            {{ $game->location['details'] }}
                        @endif
                    </span>
                @endif
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-on-primary/15 text-on-primary">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">translate</span>
                    {{ App\Enums\ContentLanguage::from($game->language)->label() }}
                </span>
                @if($game->min_players || $game->max_players)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-on-primary/15 text-on-primary">
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">groups</span>
                        @if($game->min_players && $game->max_players)
                            {{ $game->min_players }}–{{ $game->max_players }} {{ __('common.content_players') }}
                        @elseif($game->min_players)
                            {{ trans_choice('common.field_min_count_players', $game->min_players) }}
                        @else
                            {{ trans_choice('common.content_up_to_count_players', $game->max_players) }}
                        @endif
                    </span>
                @endif
                @if($game->experience_level)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-on-primary/15 text-on-primary">
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">school</span>
                        {{ App\Enums\ExperienceLevel::from($game->experience_level)->label() }}
                    </span>
                @endif
            </div>
        </div>
    </section>

    {{-- ── Description (featured section) ──────────────────── --}}
    @if($game->description)
        <section class="bg-surface-container-low">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
                <div class="max-w-3xl text-base sm:text-lg text-on-surface leading-relaxed">
                    {!! nl2br(e($game->description)) !!}
                </div>
            </div>
        </section>
    @endif

    {{-- ── Content ──────────────────────────────────────────── --}}
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8 bg-surface space-y-6">

        <x-language-mismatch-banner :entity-language="$game->language" />

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

        <x-registration-cta :message="__('games.guest_nudge_game_detail')" />

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
                        <h2 class="text-lg font-heading font-bold text-on-surface">{{ __('games.action_join_waitlist') }}</h2>
                        <p class="mt-1 text-sm text-on-surface-variant">{{ __('games.content_waitlist_position', ['position' => $waitlistPosition]) }}</p>
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

        {{-- Benched Banner (campaign sessions) --}}
        @if($userBenchParticipant)
            <section class="bg-tertiary/5 border border-tertiary/20 rounded-xl shadow-ambient p-6">
                <div class="flex items-start gap-4">
                    <span class="material-symbols-outlined text-2xl text-tertiary mt-0.5" aria-hidden="true">event_seat</span>
                    <div class="flex-1">
                        <h2 class="text-lg font-heading font-bold text-on-surface">{{ __('games.content_you_are_on_the_bench') }}</h2>
                        <p class="mt-1 text-sm text-on-surface-variant">{{ __('games.content_you_have_been_placed_on_the_bench') }}</p>
                    </div>
                </div>
            </section>
        @endif
        @if($game->gameSystem)
            @include('livewire.partials.game-system-info', ['entity' => $game])
        @endif

        {{-- ── Two-column layout on desktop ──────────────── --}}
        <div class="lg:grid lg:grid-cols-3 lg:gap-8 space-y-6 lg:space-y-0">

            {{-- Main column --}}
            <div class="lg:col-span-2 space-y-6">

                {{-- Participants --}}
                <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                    <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined text-xl" aria-hidden="true">groups</span>
                        {{ __('common.content_participants') }}
                    </h2>
                    @if($game->participants->count())
                        <div class="divide-y divide-outline-variant/30">
                            @foreach($game->participants as $participant)
                                <div class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                                    <x-user-link :user="$participant->user" avatar-size="w-10 h-10" :truncate="true" />
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                        {{ $participant->role === 'gm' ? 'bg-primary/10 text-primary' : 'bg-surface-container-high text-on-surface-variant' }}">
                                        {{ __('games.field_role_' . $participant->role) }}
                                    </span>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                        {{ $participant->status === 'approved' ? 'bg-secondary-container text-on-secondary-container' : ($participant->status === 'waitlisted' ? 'bg-tertiary/10 text-tertiary' : ($participant->status === 'pending' ? 'bg-primary/10 text-primary' : 'bg-error-container text-on-error-container')) }}">
                                        {{ $participant->status instanceof \BackedEnum ? $participant->status->label() : __('games.status_' . $participant->status) }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-on-surface-variant italic py-4 text-center">{{ __('common.content_no_participants_yet') }}</p>
                    @endif
                </section>

                {{-- Session Zero --}}
                @if($activeSessionZero && ($isParticipant || $isOwner))
                    <section class="bg-primary/5 border border-primary/20 rounded-xl shadow-ambient p-6">
                        <div class="flex items-center justify-between flex-wrap gap-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                                    <span class="material-symbols-outlined text-primary" aria-hidden="true">assignment</span>
                                </div>
                                <div>
                                    <h2 class="text-lg font-heading font-bold text-on-surface">{{ __('session_zero.title_session_zero') }}</h2>
                                    <p class="text-sm text-on-surface-variant">{{ $activeSessionZero->title }}</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                @if($isSessionZeroConfirmed)
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-full bg-secondary-container text-on-secondary-container">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">check_circle</span>
                                        {{ __('session_zero.confirmation_confirmed') }}
                                    </span>
                                @endif
                                <a href="{{ route('session-zero.view', ['locale' => app()->getLocale(), 'uuid' => $activeSessionZero->uuid]) }}" wire:navigate
                                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity">
                                    <span class="material-symbols-outlined text-base" aria-hidden="true">visibility</span>
                                    {{ __('session_zero.action_view_session_zero') }}
                                </a>
                            </div>
                        </div>
                    </section>
                @endif

                {{-- Discovery Meta --}}
                @if($game->complexity || ($game->vibe_flags && count($game->vibe_flags)))
                    <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                        <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-xl" aria-hidden="true">info</span>
                            {{ __('campaigns.content_session_info') }}
                        </h2>
                        @if($game->complexity)
                            <div class="mb-4">
                                <p class="text-sm font-medium text-on-surface mb-1">{{ __('games.content_complexity') }}</p>
                                <div class="flex items-center gap-1">
                                    @for($i = 1; $i <= 5; $i++)
                                        <span class="material-symbols-outlined text-lg {{ $i <= round($game->complexity) ? 'text-primary' : 'text-outline/30' }}" aria-hidden="true">
                                            {{ $i <= round($game->complexity) ? 'star' : 'star_border' }}
                                        </span>
                                    @endfor
                                    <span class="ml-2 text-sm text-on-surface-variant">{{ number_format($game->complexity, 1) }}/5</span>
                                </div>
                            </div>
                        @endif
                        @if($game->vibe_flags && count($game->vibe_flags))
                            <div>
                                <p class="text-sm font-medium text-on-surface mb-2">{{ __('common.content_vibes') }}</p>
                                <div class="flex flex-wrap gap-2">
                                    @foreach($game->vibe_flags as $flag)
                                        @php($flagEnum = App\Enums\VibeFlag::tryFrom($flag))
                                        @if($flagEnum)
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-primary/10 text-primary">
                                                {{ $flagEnum->label() }}
                                            </span>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </section>
                @endif

                {{-- Comfort Notes (board games) --}}
                @php($comfortNotes = $game->game_type?->value === 'board_game' && isset($game->safety_rules['comfort_notes']) ? $game->safety_rules['comfort_notes'] : null)
                @if($comfortNotes)
                    <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                        <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-xl" aria-hidden="true">notes</span>
                            {{ __('games.label_comfort_notes') }}
                        </h2>
                        <p class="text-sm text-on-surface whitespace-pre-line">{{ $comfortNotes }}</p>
                    </section>
                @endif

                {{-- Safety Tools --}}
                @if($game->safety_rules && $game->game_type?->value !== 'board_game')
                    @include('livewire.games.partials.safety-tools-display', ['safetyRules' => $game->safety_rules])
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
                                <a href="{{ route('reviews.write', ['reviewable_type' => 'game', 'reviewable_id' => $game->id]) }}" wire:navigate
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

                {{-- Host Recap (only on completed games with recap content) --}}
                @if($game->status === 'completed' && $game->recap)
                    <section class="bg-tertiary/5 border-l-4 border-tertiary rounded-xl shadow-ambient p-6">
                        <div class="flex items-center gap-2 mb-4">
                            <span class="material-symbols-outlined text-xl text-tertiary" aria-hidden="true">auto_stories</span>
                            <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface">{{ __('games.title_host_recap') }}</h2>
                        </div>
                        <div class="prose prose-sm max-w-none text-on-surface">
                            {!! nl2br(e($game->recap)) !!}
                        </div>
                        @if($game->owner)
                            <div class="mt-4 flex items-center gap-2 text-sm text-on-surface-variant">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">person</span>
                                {{ __('games.content_recap_by', ['host' => $game->owner->name]) }}
                            </div>
                        @endif
                    </section>
                @endif

                {{-- Write Recap (owner only, completed game, no recap yet) --}}
                @auth
                    @if($isOwner && $game->status === 'completed' && empty($game->recap))
                        <section class="bg-tertiary/5 border border-tertiary/20 rounded-xl shadow-ambient p-6">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="material-symbols-outlined text-xl text-tertiary" aria-hidden="true">edit_note</span>
                                <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface">{{ __('games.title_write_recap') }}</h2>
                            </div>
                            <p class="text-sm text-on-surface-variant mb-4">{{ __('games.content_write_recap_description') }}</p>
                            <form wire:submit="writeRecap">
                                <div class="mb-3">
                                    <textarea
                                        id="recap-content"
                                        wire:model="recapContent"
                                        rows="5"
                                        maxlength="2000"
                                        class="w-full rounded-lg border border-outline-variant bg-surface-container-low text-on-surface text-sm px-3 py-2 focus:ring-2 focus:ring-primary focus:border-primary"
                                        placeholder="{{ __('games.label_recap_placeholder') }}"
                                    ></textarea>
                                    @error('recapContent')
                                        <p class="mt-1 text-sm text-error">{{ $message }}</p>
                                    @enderror
                                    <div class="flex justify-end mt-1">
                                        <span class="text-xs text-on-surface-variant"
                                              x-text="'{{ strlen($recapContent ?? '') }}' + '/2000'">
                                            {{ strlen($recapContent ?? '') }}/2000
                                        </span>
                                    </div>
                                </div>
                                <button type="submit"
                                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity">
                                    <span class="material-symbols-outlined text-base" aria-hidden="true">publish</span>
                                    {{ __('games.action_recap_submit') }}
                                </button>
                            </form>
                        </section>
                    @endif
                @endauth

                {{-- Debriefing Section (completed games with debriefing tools) --}}
                @if($game->status === 'completed' && $hasDebriefingTools)
                    {{-- Host: aggregated debriefing dashboard --}}
                    @if($isOwner && $hostDebriefings->count() > 0)
                        <section class="bg-secondary-container/30 border-l-4 border-secondary rounded-xl shadow-ambient p-6">
                            <div class="flex items-center gap-2 mb-4">
                                <span class="material-symbols-outlined text-xl text-on-secondary-container" aria-hidden="true">psychology</span>
                                <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface">{{ __('games.title_debriefing_responses') }}</h2>
                                <span class="ml-auto inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                                    {{ $hostDebriefings->count() }}
                                </span>
                            </div>
                            @foreach($hostDebriefings as $debriefing)
                                <div class="mb-4 last:mb-0 bg-surface-container-low rounded-lg p-4">
                                    <div class="flex items-center gap-2 mb-3">
                                        <x-user-link :user="$debriefing->user" avatar-size="w-7 h-7" :truncate="true" />
                                        <span class="text-xs text-on-surface-variant">{{ $debriefing->submitted_at?->isoFormat('LLL') }}</span>
                                    </div>
                                    @foreach($debriefing->responses as $key => $response)
                                        @php($promptLabel = $debriefingPrompts[$key]['prompt'] ?? $key)
                                        <div class="mb-2 last:mb-0">
                                            <p class="text-xs font-medium text-on-surface-variant">{{ $promptLabel }}</p>
                                            <p class="text-sm text-on-surface">{{ $response }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </section>
                    @elseif($isOwner)
                        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6 text-center">
                            <span class="material-symbols-outlined text-3xl text-on-surface-variant mb-2" aria-hidden="true">psychology</span>
                            <p class="text-on-surface font-medium">{{ __('games.title_debriefing_responses') }}</p>
                            <p class="text-sm text-on-surface-variant mt-1">{{ __('games.content_debriefing_waiting_for_responses') }}</p>
                        </section>
                    @endif

                    {{-- Participant: submit debriefing form --}}
                    @auth
                        @if(!$isOwner && $isParticipant && !$userDebriefing)
                            <section class="bg-primary/5 border border-primary/20 rounded-xl shadow-ambient p-6">
                                <div class="flex items-center gap-2 mb-4">
                                    <span class="material-symbols-outlined text-xl text-primary" aria-hidden="true">psychology</span>
                                    <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface">{{ __('games.title_debriefing_submit') }}</h2>
                                </div>
                                <p class="text-sm text-on-surface-variant mb-4">{{ __('games.content_debriefing_description') }}</p>
                                <form wire:submit="submitDebriefing">
                                    @foreach($debriefingPrompts as $key => $promptData)
                                        <div class="mb-4">
                                            <label for="debriefing_{{ $key }}" class="block text-sm font-medium text-on-surface mb-1">
                                                {{ $promptData['prompt'] }}
                                                @if(!empty($promptData['confidential']))
                                                    <span class="text-xs text-on-surface-variant ml-1">({{ __('games.content_confidential') }})</span>
                                                @endif
                                            </label>
                                            <textarea
                                                id="debriefing_{{ $key }}"
                                                wire:model="debriefingResponses.{{ $key }}"
                                                rows="3"
                                                class="w-full rounded-lg border border-outline-variant bg-surface-container-low text-on-surface text-sm px-3 py-2 focus:ring-2 focus:ring-primary focus:border-primary"
                                                placeholder="{{ $promptData['prompt'] }}"
                                            ></textarea>
                                        </div>
                                    @endforeach
                                    <button type="submit"
                                        class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity">
                                        <span class="material-symbols-outlined text-base" aria-hidden="true">send</span>
                                        {{ __('games.action_submit_debriefing') }}
                                    </button>
                                </form>
                            </section>
                        @elseif(!$isOwner && $isParticipant && $userDebriefing)
                            {{-- Already submitted --}}
                            <section class="bg-secondary-container/30 border-l-4 border-secondary rounded-xl shadow-ambient p-6">
                                <div class="flex items-center gap-3">
                                    <span class="material-symbols-outlined text-xl text-on-secondary-container" aria-hidden="true">check_circle</span>
                                    <div>
                                        <p class="font-medium text-on-surface">{{ __('games.content_debriefing_submitted') }}</p>
                                        @if($userDebriefing->submitted_at)
                                            <p class="text-xs text-on-surface-variant">{{ $userDebriefing->submitted_at->isoFormat('LLL') }}</p>
                                        @endif
                                    </div>
                                </div>
                            </section>

                            {{-- Anonymized summary (available after submitting) --}}
                            @if($debriefingSummary && $debriefingSummary['total_submissions'] > 0)
                                <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                                    <div class="flex items-center gap-2 mb-4">
                                        <span class="material-symbols-outlined text-xl text-on-surface-variant" aria-hidden="true">groups</span>
                                        <h2 class="text-lg font-heading font-bold tracking-tight text-on-surface">{{ __('games.title_debriefing_summary') }}</h2>
                                        <span class="ml-auto text-xs text-on-surface-variant">
                                            {{ trans_choice('games.content_debriefing_response_count', $debriefingSummary['total_submissions']) }}
                                        </span>
                                    </div>
                                    @foreach($debriefingSummary['prompts'] as $key => $responses)
                                        @php($promptLabel = $debriefingPrompts[$key]['prompt'] ?? $key)
                                        <div class="mb-4 last:mb-0">
                                            <p class="text-xs font-medium text-on-surface-variant mb-2">{{ $promptLabel }}</p>
                                            <div class="space-y-1">
                                                @foreach($responses as $response)
                                                    <p class="text-sm text-on-surface bg-surface-container-high rounded px-3 py-2">{{ $response }}</p>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                </section>
                            @endif
                        @endif
                    @endauth
                @endif
            </div>

            {{-- Sidebar --}}
            <aside class="space-y-6">

                {{-- Join / Apply CTA --}}
                @auth
                    @if($canApply)
                        <div class="bg-primary/5 border border-primary/20 rounded-xl shadow-ambient p-6">
                            <h3 class="text-lg font-heading font-bold text-on-surface flex items-center gap-2 mb-2">
                                <span class="material-symbols-outlined text-xl text-primary" aria-hidden="true">
                                    @if($game->visibility === 'public') login @else edit_note @endif
                                </span>
                                @if($game->visibility === 'public')
                                    {{ __('games.action_join_game') }}
                                @else
                                    {{ __('games.action_apply_to_join') }}
                                @endif
                            </h3>
                            @if($game->visibility === 'protected')
                                <p class="text-sm text-on-surface-variant mb-4">{{ __('games.content_this_is_a_protected_game') }}</p>
                            @endif
                            <a href="{{ route('games.apply', ['locale' => app()->getLocale(), 'id' => $game->id]) }}" wire:navigate
                               class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 bg-primary text-on-primary text-sm font-semibold rounded-xl shadow-ambient hover:brightness-110 transition-all">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">
                                    @if($game->visibility === 'public') login @else send @endif
                                </span>
                                @if($game->visibility === 'public')
                                    {{ __('games.action_join_game') }}
                                @else
                                    {{ __('games.action_apply_to_join') }}
                                @endif
                            </a>
                        </div>
                    @elseif($hasExistingApplication)
                        <div class="bg-tertiary/5 border border-tertiary/20 rounded-xl shadow-ambient p-6 text-center">
                            <span class="material-symbols-outlined text-3xl text-tertiary mb-2" aria-hidden="true">schedule</span>
                            <p class="text-on-surface font-medium">{{ __('games.content_application_pending') }}</p>
                            <p class="text-sm text-on-surface-variant mt-1">{{ __('games.content_waiting_for_host_approval') }}</p>
                        </div>
                    @elseif($canJoinWaitlist)
                        <div class="bg-primary/5 border border-primary/20 rounded-xl shadow-ambient p-6">
                            <h3 class="text-lg font-heading font-bold text-on-surface flex items-center gap-2 mb-2">
                                <span class="material-symbols-outlined text-xl text-primary" aria-hidden="true">playlist_add</span>
                                {{ __('games.action_join_waitlist') }}
                            </h3>
                            <p class="text-sm text-on-surface-variant mb-4">{{ __('games.content_game_full_join_waitlist') }}</p>
                            <button wire:click="joinWaitlist"
                                class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 bg-primary text-on-primary text-sm font-semibold rounded-xl shadow-ambient hover:brightness-110 transition-all">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">playlist_add</span>
                                {{ __('games.action_join_waitlist') }}
                            </button>
                        </div>
                    @endif
                @else
                    <x-registration-cta :message="__('games.guest_nudge_join_game')" />
                @endauth

                {{-- Host --}}
                <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                    <h3 class="text-base font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">person</span>
                        {{ __('common.content_hosted_by') }}
                    </h3>
                    <div class="flex items-center gap-3">
                        <x-user-link :user="$game->owner" avatar-size="w-11 h-11" />
                        @if($game->owner->isGM())
                            <x-gm-badge size="sm" />
                        @endif
                    </div>
                </div>

                {{-- Applications (owner only) --}}
                @if($isOwner && $game->applications->count())
                    <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                        <h3 class="text-base font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg" aria-hidden="true">inbox</span>
                            {{ __('common.content_applications') }}
                        </h3>
                        <div class="divide-y divide-outline-variant/30">
                            @foreach($game->applications as $application)
                                <div class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                                    <div class="flex-1 min-w-0">
                                        <x-user-link :user="$application->user" avatar-size="w-9 h-9" :truncate="true" />
                                        @if($application->message)
                                            <p class="text-xs text-on-surface-variant truncate ml-11">{{ $application->message }}</p>
                                        @endif
                                    </div>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium shrink-0
                                        {{ $application->status === 'pending' ? 'bg-tertiary/10 text-tertiary' : ($application->status === 'accepted' ? 'bg-secondary-container text-on-secondary-container' : 'bg-error-container text-on-error-container') }}">
                                        {{ __('games.status_' . $application->status) }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Waitlist Management (owner only, standalone games) --}}
                @if($isOwner && $waitlistedPlayers->count())
                    <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                        <h3 class="text-base font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg" aria-hidden="true">playlist_add</span>
                            {{ __('games.content_waitlist_management') }}
                        </h3>
                        <div class="divide-y divide-outline-variant/30">
                            @foreach($waitlistedPlayers as $waitlisted)
                                <div class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                                    <div class="flex-1 min-w-0">
                                        <x-user-link :user="$waitlisted->user" avatar-size="w-9 h-9" :truncate="true" />
                                        <p class="text-xs text-on-surface-variant ml-11">
                                            {{ __('games.content_waitlist_position', ['position' => $loop->iteration]) }}
                                        </p>
                                    </div>
                                    <button wire:click="manualPromote('{{ $waitlisted->id }}')"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg bg-primary text-on-primary hover:opacity-90 transition-opacity">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">arrow_upward</span>
                                        {{ __('games.action_manual_promote') }}
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Bench Management (owner only, campaign sessions) --}}
                @if($isOwner && $benchedPlayers->count())
                    <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                        <h3 class="text-base font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg" aria-hidden="true">event_seat</span>
                            {{ __('games.content_bench') }}
                        </h3>
                        <p class="text-xs text-on-surface-variant mb-3">{{ __('games.content_bench_description') }}</p>
                        <div class="divide-y divide-outline-variant/30">
                            @foreach($benchedPlayers as $benched)
                                <div class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                                    <div class="flex-1 min-w-0">
                                        <x-user-link :user="$benched->user" avatar-size="w-9 h-9" :truncate="true" />
                                    </div>
                                    <button wire:click="promoteFromBench('{{ $benched->id }}')"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg bg-primary text-on-primary hover:opacity-90 transition-opacity">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">arrow_upward</span>
                                        {{ __('games.action_promote_from_bench') }}
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </aside>
        </div>
    </div>

    {{-- Mobile sticky CTA --}}
    @auth
        @if($canApply)
            <div class="lg:hidden sticky bottom-0 z-30 bg-surface/95 backdrop-blur-md border-t border-outline-variant px-4 py-3">
                <a href="{{ route('games.apply', ['locale' => app()->getLocale(), 'id' => $game->id]) }}" wire:navigate
                   class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 bg-primary text-on-primary text-sm font-semibold rounded-xl shadow-lg hover:brightness-110 transition-all">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">
                        @if($game->visibility === 'public') login @else send @endif
                    </span>
                    @if($game->visibility === 'public')
                        {{ __('games.action_join_game') }}
                    @else
                        {{ __('games.action_apply_to_join') }}
                    @endif
                </a>
            </div>
        @elseif($canJoinWaitlist)
            <div class="lg:hidden sticky bottom-0 z-30 bg-surface/95 backdrop-blur-md border-t border-outline-variant px-4 py-3">
                <button wire:click="joinWaitlist"
                   class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 bg-primary text-on-primary text-sm font-semibold rounded-xl shadow-lg hover:brightness-110 transition-all">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">playlist_add</span>
                    {{ __('games.action_join_waitlist') }}
                </button>
            </div>
        @endif
    @endauth
</div>

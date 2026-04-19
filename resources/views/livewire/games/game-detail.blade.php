<div>
    {{-- Back link --}}
    <div class="bg-surface-container-low border-b border-outline-variant">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 py-3">
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

    {{-- Game Header / Banner --}}
    <section class="bg-gradient-to-br from-primary to-primary-container text-on-primary">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 py-10 sm:py-14">
            <div class="flex flex-wrap items-center gap-2 mb-4">
                @if($isOwner)
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-on-primary/20 text-on-primary">
                        {{ __('common.content_owner') }}
                    </span>
                @endif
                @if($game->gameSystem)
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-on-primary/10 text-on-primary">
                        {{ $game->gameSystem?->name }}
                    </span>
                @endif
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                    {{ $game->visibility === 'public' ? 'bg-on-primary/20 text-on-primary' : ($game->visibility === 'protected' ? 'bg-on-primary/30 text-on-primary' : 'bg-on-primary/10 text-on-primary') }}">
                    {{ __(ucfirst($game->visibility)) }}
                </span>
            </div>

            @if($game->campaign)
                <a href="{{ route('campaigns.detail', $game->campaign->id) }}" wire:navigate class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-on-primary/20 text-on-primary hover:bg-on-primary/30 transition-colors mb-2">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">campaign</span>
                    {{ __('games.content_part_of_campaign_name', ['name' => $game->campaign?->name]) }}
                </a>
            @endif

            <h1 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight">{{ $game->name }}</h1>

            @if($game->description)
                <p class="mt-3 text-lg text-on-primary/80 max-w-3xl">{{ $game->description }}</p>
            @endif

            {{-- Quick info row --}}
            <div class="mt-6 flex flex-wrap gap-6 text-sm text-on-primary/80">
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
                            @php
                                $cityOnly = trim(explode(',', $game->location['details'])[0]);
                            @endphp
                            {{ $cityOnly }}
                        @else
                            {{ $game->location['details'] }}
                        @endif
                    </span>
                @endif
            </div>

            {{-- Language + Player count badges --}}
            <div class="mt-4 flex flex-wrap gap-3">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-on-primary/15 text-on-primary">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">translate</span>
                    {{ App\Enums\ContentLanguage::from($game->language)->label() }}
                </span>
                @if($game->min_players || $game->max_players)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-on-primary/15 text-on-primary">
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">groups</span>
                        @if($game->min_players && $game->max_players)
                            {{ $game->min_players }}–{{ $game->max_players }} {{ __('common.content_count_players', ['count' => $game->max_players]) }}
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
                                class="inline-flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-primary to-primary-container text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity">
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
                                {{ strtoupper($participant->role) }}
                            </span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                {{ $participant->status === 'confirmed' ? 'bg-secondary-container text-on-secondary-container' : 'bg-tertiary/10 text-tertiary' }}">
                                {{ __(ucfirst($participant->status)) }}
                            </span>
                        </div>
                    @endforeach
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
                                    @if($game->visibility === 'public')
                                        login
                                    @else
                                        edit_note
                                    @endif
                                </span>
                                @if($game->visibility === 'public')
                                    {{ __('games.action_join_game') }}
                                @else
                                    {{ __('games.action_apply_to_join') }}
                                @endif
                            </h2>
                            @if($game->visibility === 'protected')
                                <p class="mt-1 text-sm text-on-surface-variant">{{ __('games.content_this_is_a_protected_game') }}</p>
                            @endif
                        </div>
                        <a href="{{ route('games.apply', ['locale' => app()->getLocale(), 'id' => $game->id]) }}"
                           wire:navigate
                           class="inline-flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-primary to-primary-container text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity">
                            <span class="material-symbols-outlined text-base" aria-hidden="true">
                                @if($game->visibility === 'public')
                                    login
                                @else
                                    send
                                @endif
                            </span>
                            @if($game->visibility === 'public')
                                {{ __('games.action_join_game') }}
                            @else
                                {{ __('games.action_apply_to_join') }}
                            @endif
                        </a>
                    </div>
                </section>
            @elseif($hasExistingApplication)
                <section class="bg-tertiary/5 border border-tertiary/20 rounded-xl shadow-ambient p-6 text-center">
                    <span class="material-symbols-outlined text-3xl text-tertiary mb-2" aria-hidden="true">schedule</span>
                    <p class="text-on-surface font-medium">{{ __('games.content_application_pending') }}</p>
                    <p class="text-sm text-on-surface-variant mt-1">{{ __('games.content_waiting_for_host_approval') }}</p>
                </section>
            @endif
        @else
            {{-- Guest CTA: show registration nudge --}}
            <x-registration-cta :message="__('games.guest_nudge_join_game')" />
        @endauth

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

        {{-- Safety Tools --}}
        @if($game->safety_rules)
            @include('livewire.games.partials.safety-tools-display', ['safetyRules' => $game->safety_rules])
        @endif

        {{-- Applications (visible to owner) --}}
        @if($isOwner && $game->applications->count())
            <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">inbox</span>
                    {{ __('common.content_applications') }}
                </h2>

                <div class="divide-y divide-outline-variant/30">
                    @foreach($game->applications as $application)
                        <div class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                            <div class="flex-1 min-w-0">
                                <x-user-link :user="$application->user" avatar-size="w-10 h-10" :truncate="true" />
                                @if($application->message)
                                    <p class="text-xs text-on-surface-variant truncate ml-12">{{ $application->message }}</p>
                                @endif
                            </div>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                {{ $application->status === 'pending' ? 'bg-tertiary/10 text-tertiary' : ($application->status === 'accepted' ? 'bg-secondary-container text-on-secondary-container' : 'bg-error-container text-on-error-container') }}">
                                {{ __(ucfirst($application->status)) }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Game Owner Info --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-xl" aria-hidden="true">person</span>
                {{ __('common.content_hosted_by') }}
            </h2>
            <div class="flex items-center gap-4">
                <x-user-link :user="$game->owner" avatar-size="w-12 h-12" />
            </div>
        </section>
    </div>
</div>

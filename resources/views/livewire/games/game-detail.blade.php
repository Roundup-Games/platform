<div>
    {{-- Back link --}}
    <div class="bg-surface-container-low border-b border-outline-variant">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-3">
            <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-on-surface transition-colors">
                <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span>
                {{ __('profile.action_back_to_dashboard') }}
            </a>
        </div>
    </div>

    {{-- Hero --}}
    @include('livewire.games.partials._game-header')

    {{-- Description --}}
    @if($game->description)
        <section class="bg-surface-container-low">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
                <div class="max-w-3xl text-base sm:text-lg text-on-surface leading-relaxed">
                    {!! nl2br(e($game->description)) !!}
                </div>
            </div>
        </section>
    @endif

    {{-- Content --}}
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8 bg-surface space-y-6">

        <x-language-mismatch-banner :entity-language="$game->language" entity="game" />

        @if(session()->has('success'))
            <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 4000)" x-show="show"
                 x-transition:leave="transition ease-in duration-300"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 wire:key="success-flash-{{ now()->timestamp }}"
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

        {{-- Umbrella context: the table's parent get-together, visible to every
             viewer — previously only the owner saw event context (a detach
             control), so the event was invisible from the table page. --}}
        @if($game->event)
            <a href="{{ route('events.detail', ['slug' => $game->event->slug]) }}" wire:navigate
               class="flex items-center gap-3 rounded-xl bg-surface-container-low border border-outline-variant/30 px-4 py-3 hover:border-primary/40 transition-colors group">
                <span class="material-symbols-outlined text-primary" aria-hidden="true">festival</span>
                <span class="text-sm text-on-surface min-w-0 truncate">{{ __('events.content_part_of_event', ['event' => $game->event->name]) }}</span>
                <span class="material-symbols-outlined text-on-surface-variant ml-auto shrink-0 text-base" aria-hidden="true">chevron_right</span>
            </a>
        @endif

        <x-registration-cta :message="__('games.guest_nudge_game_detail')" />

        @include('livewire.games.partials._invitation-banner')
        @include('livewire.games.partials._waitlist-section')
        @include('livewire.games.partials._bench-section')

        @if($game->gameSystem)
            @include('livewire.partials.game-system-info', ['entity' => $game])
        @endif

        {{-- Two-column layout --}}
        <div class="lg:grid lg:grid-cols-3 lg:gap-8 space-y-6 lg:space-y-0">

            {{-- Main column --}}
            <div class="lg:col-span-2 space-y-6">

                @include('livewire.games.partials._participant-list')

                {{-- Capacity editor (host affordance) --}}
                @include('livewire.games.partials._capacity-editor')

                {{-- Host Bulletin Board --}}
                <livewire:games.game-bulletin-board :game="$game" />

                {{-- Campaign charter discovery (spec §4, D238 closure): the
                     session-page half of the discovery surface — same gate and
                     visual language as the campaign page cards. The published
                     card is for the campaign owner and APPROVED campaign
                     participants only; drafts and the create CTA stay
                     owner-only (no existence oracle for anyone else); one-shots
                     render nothing. --}}
                @if($sessionCharter)
                    <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 flex items-center justify-between gap-4">
                        <div class="flex items-start gap-3 min-w-0">
                            <span class="material-symbols-outlined text-xl text-primary" aria-hidden="true">history_edu</span>
                            <div class="min-w-0">
                                <p class="font-medium text-on-surface flex items-center gap-2 flex-wrap">
                                    {{ __('charter.title_entry') }}
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">{{ __('charter.label_version_badge', ['version' => $sessionCharter->current_version]) }}</span>
                                </p>
                                <p class="text-sm text-on-surface-variant">{{ __('charter.description_entry_teaser_participant') }}</p>
                            </div>
                        </div>
                        <a href="{{ route('charter.view', ['locale' => app()->getLocale(), 'uuid' => $sessionCharter->uuid]) }}" wire:navigate
                           class="inline-flex items-center gap-1 px-3 py-1.5 text-sm font-medium rounded-lg bg-primary text-on-primary hover:bg-primary/90 transition-colors whitespace-nowrap shrink-0">
                            <span class="material-symbols-outlined text-base" aria-hidden="true">open_in_new</span>
                            {{ __('charter.action_view_charter') }}
                        </a>
                    </div>
                @elseif($isCampaignOwner && $campaignCharter?->isDraft())
                    <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 flex items-center justify-between gap-4">
                        <div class="flex items-start gap-3 min-w-0">
                            <span class="material-symbols-outlined text-xl text-primary" aria-hidden="true">history_edu</span>
                            <div class="min-w-0">
                                <p class="font-medium text-on-surface flex items-center gap-2 flex-wrap">
                                    {{ __('charter.title_entry') }}
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container-highest text-on-surface-variant">{{ __('charter.label_draft_badge') }}</span>
                                </p>
                            </div>
                        </div>
                        <a href="{{ route('campaigns.charter', $game->campaign) }}" wire:navigate
                           class="inline-flex items-center gap-1 px-3 py-1.5 text-sm font-medium rounded-lg bg-primary text-on-primary hover:bg-primary/90 transition-colors whitespace-nowrap shrink-0">
                            <span class="material-symbols-outlined text-base" aria-hidden="true">edit</span>
                            {{ __('charter.action_continue_charter') }}
                        </a>
                    </div>
                @elseif($isCampaignOwner)
                    <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 flex items-center justify-between gap-4">
                        <div class="flex items-start gap-3 min-w-0">
                            <span class="material-symbols-outlined text-xl text-primary" aria-hidden="true">history_edu</span>
                            <div class="min-w-0">
                                <p class="font-medium text-on-surface">{{ __('charter.title_entry') }}</p>
                                <p class="text-sm text-on-surface-variant">{{ __('charter.description_entry_teaser') }}</p>
                                <p class="text-xs text-on-surface-variant/80 mt-1">{{ __('charter.description_entry_fit') }}</p>
                            </div>
                        </div>
                        <a href="{{ route('campaigns.charter', $game->campaign) }}" wire:navigate
                           class="inline-flex items-center gap-1 px-3 py-1.5 text-sm font-medium rounded-lg bg-primary text-on-primary hover:bg-primary/90 transition-colors whitespace-nowrap shrink-0">
                            <span class="material-symbols-outlined text-base" aria-hidden="true">add</span>
                            {{ __('charter.action_create_charter') }}
                        </a>
                    </div>
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
                                        <span class="material-symbols-outlined text-lg {{ $i <= round($game->complexity) ? 'text-primary' : 'text-outline/30' }}" style="font-variation-settings: 'FILL' {{ $i <= round($game->complexity) ? 1 : 0 }}" aria-hidden="true">
                                            star
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

                {{-- Comfort Notes --}}
                @if($comfortNotes)
                    <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                        <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-xl" aria-hidden="true">notes</span>
                            {{ __('games.field_comfort_notes') }}
                        </h2>
                        <p class="text-sm text-on-surface whitespace-pre-line">{{ $comfortNotes }}</p>
                    </section>
                @endif

                {{-- Hosted at an event: owner can detach their table from their own manage surface (M063/S05) --}}
                @auth
                    @if($isOwner && $game->event)
                        <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 flex items-center justify-between gap-3 flex-wrap">
                            <span class="text-sm text-on-surface-variant">
                                {{ __('events.content_hosted_at_event_hint', ['event' => $game->event->name]) }}
                            </span>
                            <x-confirm-action
                                action="detachFromEvent()"
                                id="detach-from-event"
                                :trigger-label="__('events.action_detach')"
                                trigger-class="px-3 py-1.5 rounded-lg text-sm font-medium bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest transition-colors whitespace-nowrap"
                                :confirm-label="__('events.action_detach')"
                                :cancel-label="__('common.action_cancel')"
                                :message="__('events.content_detach_this_table_from_this_event', ['name' => $game->name])"
                                variant="inline"
                                severity="caution"
                                confirm-icon="link_off"
                            />
                        </div>
                    @endif
                @endauth

                {{-- Leave Game (non-owner participants only, scheduled games only) --}}
                @auth
                    @if($isParticipant && !$isOwner && $game->status->value === 'scheduled')
                        <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 flex items-center justify-between">
                            <span class="text-sm text-on-surface-variant">{{ __('common.content_leave_hint') }}</span>
                            <x-confirm-action
                                action="leaveGame()"
                                id="leave-game"
                                :icon="'logout'"
                                :trigger-label="__('games.action_leave_game')"
                                trigger-class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium text-error hover:bg-error/10 transition-colors"
                                :confirm-label="__('games.action_leave_game')"
                                :cancel-label="__('common.action_keep')"
                                :message="__('games.confirm_leave_game')"
                                variant="inline"
                                severity="destructive"
                                confirm-icon="logout"
                            />
                        </div>
                    @endif
                @endauth

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

                @include('livewire.games.partials._session-end')
            </div>

            {{-- Sidebar --}}
            @include('livewire.games.partials._game-sidebar')

            {{-- Short Link Management (owner only) --}}
            @if($isOwner)
                <div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                    @include('livewire.partials.short-link-display', [
                        'shortLinks' => $shortLinks,
                        'canCreateMoreShortLinks' => $canCreateMoreShortLinks,
                        'entity' => $game,
                    ])
                </div>
            @endif
        </div>
    </div>

    @include('livewire.games.partials._mobile-cta')
</div>

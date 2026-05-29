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

                {{-- Host Bulletin Board --}}
                <livewire:games.game-bulletin-board :game="$game" />

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
                    ])
                </div>
            @endif
        </div>
    </div>

    @include('livewire.games.partials._mobile-cta')
</div>

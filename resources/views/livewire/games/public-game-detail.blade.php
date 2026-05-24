<div>
    {{-- Back link --}}
    <div class="bg-surface-container-low border-b border-outline-variant">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-3">
            <a href="{{ route('discover') }}" class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-on-surface transition-colors">
                <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span>
                {{ __('games.action_back_to_discover') }}
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

        @if($game->gameSystem)
            @include('livewire.partials.game-system-info', ['entity' => $game])
        @endif

        {{-- Two-column layout --}}
        <div class="lg:grid lg:grid-cols-3 lg:gap-8 space-y-6 lg:space-y-0">

            {{-- Main column --}}
            <div class="lg:col-span-2 space-y-6">

                {{-- Participants --}}
                <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                    <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined text-xl" aria-hidden="true">groups</span>
                        {{ trans_choice('common.content_count_participants', $approvedParticipantsCount) }}
                    </h2>
                    @guest
                        @if($game->max_players)
                            @php($spotsLeft = max(0, $game->max_players - $approvedParticipantsCount))
                            <p class="text-sm text-on-surface-variant">
                                {{ trans_choice('games.content_spots_available', $spotsLeft, ['count' => $spotsLeft, 'max' => $game->max_players]) }}
                            </p>
                        @else
                            <p class="text-sm text-on-surface-variant">
                                {{ trans_choice('common.content_count_participants', $approvedParticipantsCount) }}
                            </p>
                        @endif
                    @endguest
                    @auth
                        @if($approvedParticipantsCount > 0)
                            <div class="flex flex-wrap gap-2">
                                @foreach($game->participants->where('status', \App\Enums\ParticipantStatus::Approved->value) as $participant)
                                    <x-user-link :user="$participant->user" avatar-size="w-9 h-9" :truncate="true" />
                                @endforeach
                            </div>
                        @else
                            <p class="text-sm text-on-surface-variant italic">{{ __('common.content_no_participants_yet') }}</p>
                        @endif
                    @endauth
                </section>

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

                {{-- Reviews (authenticated only) --}}
                @auth
                <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                    <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined text-xl" aria-hidden="true">rate_review</span>
                        {{ __('reviews.title_reviews') }}
                    </h2>
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
                @endauth
            </div>

            {{-- Sidebar --}}
            <aside class="space-y-6">
                {{-- Join CTA for guests --}}
                @guest
                    <x-registration-cta :message="__('games.guest_nudge_join_game')" />
                @endguest

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

                {{-- Report (authenticated non-owners only) --}}
                @auth
                    @unless($this->isOwner)
                        <div class="flex justify-end">
                            <livewire:reports.report-content :entityType="'game'" :entityId="$game->id" :key="'report-game-' . $game->id" />
                        </div>
                    @endunless
                @endauth
            </aside>
        </div>
    </div>

    {{-- Mobile CTA for guests --}}
    @guest
        <div class="lg:hidden sticky bottom-0 z-30 bg-surface/95 backdrop-blur-md border-t border-outline-variant px-4 py-3">
            <x-registration-cta :message="__('games.guest_nudge_join_game')" />
        </div>
    @endguest
</div>

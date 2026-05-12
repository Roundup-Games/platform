<div>
    {{-- Back link --}}
    <div class="bg-surface-container-low border-b border-outline-variant">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-3">
            <a href="{{ route('discover') }}" class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-on-surface transition-colors">
                <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span>
                {{ __('campaigns.action_back_to_discover') }}
            </a>
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
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                    {{ $campaign->visibility->value === 'public' ? 'bg-on-primary/20 text-on-primary' : ($campaign->visibility->value === 'protected' ? 'bg-on-primary/30 text-on-primary' : 'bg-on-primary/10 text-on-primary') }}">
                    {{ $campaign->visibility->label() }}
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
                        {{ trim(explode(',', $campaign->location['details'])[0]) }}
                    </span>
                @endif
            </div>
        </div>
    </section>

    {{-- ── Description ──────────────────────────────────────── --}}
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

        @if($campaign->gameSystem)
            @include('livewire.partials.game-system-info', ['entity' => $campaign])
        @endif

        {{-- Two-column layout --}}
        <div class="lg:grid lg:grid-cols-3 lg:gap-8 space-y-6 lg:space-y-0">

            {{-- Main column --}}
            <div class="lg:col-span-2 space-y-6">

                {{-- Sessions --}}
                <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                    <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined text-xl" aria-hidden="true">event_note</span>
                        {{ __('campaigns.content_sessions') }}
                    </h2>
                    @if($campaign->sessions->count())
                        <div class="divide-y divide-outline-variant/30">
                            @foreach($campaign->sessions as $session)
                                <div class="flex items-center justify-between py-3 first:pt-0 last:pb-0">
                                    <div>
                                        <a href="{{ route('games.detail', $session->id) }}" class="text-sm font-medium text-on-surface hover:text-primary transition-colors">
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

                {{-- Participants (public: approved only) --}}
                <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                    <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined text-xl" aria-hidden="true">groups</span>
                        {{ trans_choice('common.content_count_participants', $approvedParticipantsCount) }}
                    </h2>
                    @if($approvedParticipantsCount > 0)
                        <div class="flex flex-wrap gap-2">
                            @foreach($campaign->participants->where('status', \App\Enums\ParticipantStatus::Approved->value) as $participant)
                                <x-user-link :user="$participant->user" avatar-size="w-9 h-9" :truncate="true" />
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-on-surface-variant italic">{{ __('common.content_no_participants_yet') }}</p>
                    @endif
                </section>

                {{-- Safety Tools --}}
                @if($campaign->safety_rules)
                    @include('livewire.games.partials.safety-tools-display', ['safetyRules' => $campaign->safety_rules])
                @endif

                {{-- Reviews --}}
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
                    @endif>
                </section>
            </div>

            {{-- Sidebar --}}
            <aside class="space-y-6">
                {{-- Join CTA for guests --}}
                @guest
                    <x-registration-cta :message="__('campaigns.guest_nudge_join_campaign')" />
                @endguest

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
            </aside>
        </div>
    </div>

    {{-- Mobile CTA for guests --}}
    @guest
        <div class="lg:hidden sticky bottom-0 z-30 bg-surface/95 backdrop-blur-md border-t border-outline-variant px-4 py-3">
            <x-registration-cta :message="__('campaigns.guest_nudge_join_campaign')" />
        </div>
    @endguest
</div>

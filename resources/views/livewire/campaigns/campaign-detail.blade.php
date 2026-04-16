<div>
    {{-- Back link --}}
    <div class="bg-surface-container-low border-b border-outline-variant">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 py-3">
            <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-on-surface transition-colors">
                <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span>
                {{ __('Back to Dashboard') }}
            </a>
        </div>
    </div>

    {{-- Campaign Header / Banner --}}
    <section class="bg-gradient-to-br from-primary to-primary-container text-on-primary">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 py-10 sm:py-14">
            <div class="flex flex-wrap items-center gap-2 mb-4">
                @if($isOwner)
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-on-primary/20 text-on-primary">
                        {{ __('Owner') }}
                    </span>
                @endif
                @if($campaign->gameSystem)
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-on-primary/10 text-on-primary">
                        {{ $campaign->gameSystem->name }}
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
                        {{ format_currency($campaign->price_per_session, false) }}/{{ __('session') }}
                    </span>
                @else
                    <span class="flex items-center gap-2 text-secondary">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">check_circle</span>
                        {{ __('Free') }}
                    </span>
                @endif
                @if($campaign->location && !empty($campaign->location['details']))
                    <span class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">location_on</span>
                        {{ $campaign->location['details'] }}
                    </span>
                @endif
            </div>
        </div>
    </section>

    {{-- Content --}}
    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-8 bg-surface space-y-6">

        {{-- Upcoming Sessions --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">event_note</span>
                    {{ __('Sessions') }}
                </h2>
                @if($isOwner)
                    <a href="{{ route('campaigns.add-session', $campaign->id) }}" wire:navigate
                       class="inline-flex items-center gap-1 px-3 py-1.5 text-sm font-medium rounded-lg bg-primary text-on-primary hover:bg-primary/90 transition-colors">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">add</span>
                        {{ __('Add Session') }}
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
                <p class="text-sm text-on-surface-variant italic py-4 text-center">{{ __('No sessions scheduled yet.') }}</p>
            @endif
        </section>

        {{-- Participants --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-xl" aria-hidden="true">groups</span>
                {{ __('Participants') }}
            </h2>

            @if($campaign->participants->count())
                <div class="divide-y divide-outline-variant/30">
                    @foreach($campaign->participants as $participant)
                        <div class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold
                                {{ $participant->role === 'gm' ? 'bg-primary/10 text-primary' : 'bg-surface-container-high text-on-surface-variant' }}">
                                {{ strtoupper($participant->user?->name[0] ?? '?') }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-on-surface truncate">{{ $participant->user?->name ?? __('Unknown') }}</p>
                            </div>
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
                <p class="text-sm text-on-surface-variant italic py-4 text-center">{{ __('No participants yet.') }}</p>
            @endif
        </section>

        {{-- Campaign Owner Info --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-xl" aria-hidden="true">person</span>
                {{ __('Run by') }}
            </h2>
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-full flex items-center justify-center text-lg font-bold bg-primary/10 text-primary">
                    {{ strtoupper($campaign->owner?->name[0] ?? '?') }}
                </div>
                <div>
                    <p class="text-sm font-medium text-on-surface">{{ $campaign->owner?->name ?? __('Unknown') }}</p>
                </div>
            </div>
        </section>
    </div>
</div>

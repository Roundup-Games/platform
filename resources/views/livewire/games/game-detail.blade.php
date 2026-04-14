<div>
    {{-- Back link --}}
    <div class="bg-surface-container-low border-b border-outline-variant">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 py-3">
            <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-on-surface transition-colors">
                <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span>
                Back to Dashboard
            </a>
        </div>
    </div>

    {{-- Game Header / Banner --}}
    <section class="bg-gradient-to-br from-primary to-primary-container text-on-primary">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 py-10 sm:py-14">
            <div class="flex flex-wrap items-center gap-2 mb-4">
                @if($isOwner)
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-on-primary/20 text-on-primary">
                        Owner
                    </span>
                @endif
                @if($game->gameSystem)
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-on-primary/10 text-on-primary">
                        {{ $game->gameSystem->name }}
                    </span>
                @endif
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                    {{ $game->visibility === 'public' ? 'bg-on-primary/20 text-on-primary' : ($game->visibility === 'protected' ? 'bg-on-primary/30 text-on-primary' : 'bg-on-primary/10 text-on-primary') }}">
                    {{ ucfirst($game->visibility) }}
                </span>
            </div>

            <h1 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight">{{ $game->name }}</h1>

            @if($game->description)
                <p class="mt-3 text-lg text-on-primary/80 max-w-3xl">{{ $game->description }}</p>
            @endif

            {{-- Quick info row --}}
            <div class="mt-6 flex flex-wrap gap-6 text-sm text-on-primary/80">
                <span class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">calendar_today</span>
                    {{ $game->date_time->format('M j, Y \a\t g:i A') }}
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
                        ${{ number_format($game->price, 2) }}
                    </span>
                @else
                    <span class="flex items-center gap-2 text-secondary">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">check_circle</span>
                        Free
                    </span>
                @endif
                @if($game->location && !empty($game->location['details']))
                    <span class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg" aria-hidden="true">location_on</span>
                        {{ $game->location['details'] }}
                    </span>
                @endif
            </div>
        </div>
    </section>

    {{-- Content --}}
    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-8 bg-surface space-y-6">

        {{-- Participants --}}
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-xl" aria-hidden="true">groups</span>
                Participants
            </h2>

            @if($game->participants->count())
                <div class="divide-y divide-outline-variant/30">
                    @foreach($game->participants as $participant)
                        <div class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold
                                {{ $participant->role === 'gm' ? 'bg-primary/10 text-primary' : 'bg-surface-container-high text-on-surface-variant' }}">
                                {{ strtoupper($participant->user->name[0] ?? '?') }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-on-surface truncate">{{ $participant->user->name }}</p>
                            </div>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                {{ $participant->role === 'gm' ? 'bg-primary/10 text-primary' : 'bg-surface-container-high text-on-surface-variant' }}">
                                {{ strtoupper($participant->role) }}
                            </span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                {{ $participant->status === 'confirmed' ? 'bg-secondary-container text-on-secondary-container' : 'bg-tertiary/10 text-tertiary' }}">
                                {{ ucfirst($participant->status) }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-on-surface-variant italic py-4 text-center">No participants yet.</p>
            @endif
        </section>

        {{-- Applications (visible to owner) --}}
        @if($isOwner && $game->applications->count())
            <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">inbox</span>
                    Applications
                </h2>

                <div class="divide-y divide-outline-variant/30">
                    @foreach($game->applications as $application)
                        <div class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold bg-surface-container-high text-on-surface-variant">
                                {{ strtoupper($application->user->name[0] ?? '?') }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-on-surface truncate">{{ $application->user->name }}</p>
                                @if($application->message)
                                    <p class="text-xs text-on-surface-variant truncate">{{ $application->message }}</p>
                                @endif
                            </div>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                {{ $application->status === 'pending' ? 'bg-tertiary/10 text-tertiary' : ($application->status === 'accepted' ? 'bg-secondary-container text-on-secondary-container' : 'bg-error-container text-on-error-container') }}">
                                {{ ucfirst($application->status) }}
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
                Hosted by
            </h2>
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-full flex items-center justify-center text-lg font-bold bg-primary/10 text-primary">
                    {{ strtoupper($game->owner->name[0] ?? '?') }}
                </div>
                <div>
                    <p class="text-sm font-medium text-on-surface">{{ $game->owner->name }}</p>
                </div>
            </div>
        </section>
    </div>
</div>

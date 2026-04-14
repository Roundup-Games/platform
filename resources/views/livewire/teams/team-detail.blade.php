<div class="py-8">
    <div class="max-w-4xl mx-auto space-y-6">
        {{-- Back --}}
        <a href="{{ route('teams.browse') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary transition-colors">
            <span class="material-symbols-outlined text-base">arrow_back</span>
            {{ __('Back to Teams') }}
        </a>

        {{-- Team Header --}}
        <div class="bg-surface-container-lowest rounded-xl shadow-ambient overflow-hidden">
            {{-- Color banner --}}
            <div class="h-3" style="background: linear-gradient(135deg, {{ $team->primary_color ?: '#B8860B' }}, {{ $team->secondary_color ?: '#8B6914' }})"></div>

            <div class="p-6">
                <div class="flex items-start justify-between">
                    <div>
                        <h1 class="text-3xl font-heading font-bold tracking-tight text-on-surface">
                            {{ $team->name }}
                        </h1>
                        @if($team->city || $team->country)
                            <p class="mt-1 text-on-surface-variant flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm">location_on</span>
                                {{ collect([$team->city, $team->country])->filter()->join(', ') }}
                            </p>
                        @endif
                    </div>

                    @if($isCaptain)
                        <a href="{{ route('teams.manage', $team->slug) }}" wire:navigate
                           class="inline-flex items-center gap-1.5 px-4 py-2 bg-surface-container-high text-on-surface-variant rounded-lg hover:bg-surface-container hover:text-on-surface text-sm font-medium transition-colors">
                            <span class="material-symbols-outlined text-base">settings</span>
                            {{ __('Manage') }}
                        </a>
                    @endif
                </div>

                @if($team->description)
                    <p class="mt-4 text-on-surface-variant">{{ $team->description }}</p>
                @endif

                <div class="mt-4 flex flex-wrap gap-4 text-sm text-on-surface-variant">
                    @if($team->founded_year)
                        <span>{{ __('Est.') }} {{ $team->founded_year }}</span>
                    @endif
                    <span>{{ __(':count members', ['count' => $team->activeMembers->count()]) }}</span>
                    @if($team->website)
                        <a href="{{ $team->website }}" target="_blank" rel="noopener" class="text-primary hover:underline">{{ $team->website }}</a>
                    @endif
                </div>
            </div>
        </div>

        {{-- Roster --}}
        <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-xl" style="font-variation-settings: 'FILL' 1">groups</span>
                {{ __('Roster') }}
            </h2>

            @if($team->activeMembers->count())
                <div class="divide-y divide-outline-variant/30">
                    @foreach($team->activeMembers as $member)
                        <div class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                            {{-- Avatar --}}
                            <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold
                                {{ $member->role === 'captain' ? 'bg-primary/10 text-primary' : ($member->role === 'coach' ? 'bg-tertiary/10 text-tertiary' : 'bg-surface-container-high text-on-surface-variant') }}">
                                {{ strtoupper($member->user->name[0] ?? '?') }}
                            </div>

                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-on-surface truncate">{{ $member->user->name }}</p>
                                @if($member->position)
                                    <p class="text-xs text-on-surface-variant">{{ $member->position }}</p>
                                @endif
                            </div>

                            {{-- Role badge --}}
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                {{ $member->role === 'captain' ? 'bg-primary/10 text-primary' : ($member->role === 'coach' ? 'bg-tertiary/10 text-tertiary' : ($member->role === 'substitute' ? 'bg-surface-container-high text-on-surface-variant' : 'bg-surface-container-high text-on-surface-variant')) }}">
                                {{ ucfirst($member->role) }}
                            </span>

                            @if($member->jersey_number)
                                <span class="text-sm font-mono text-on-surface-variant w-8 text-center">#{{ $member->jersey_number }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-on-surface-variant italic py-4 text-center">{{ __('No active members yet.') }}</p>
            @endif
        </section>
    </div>
</div>

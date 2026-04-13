<div class="py-8">
    <div class="max-w-4xl mx-auto space-y-6">
        {{-- Back --}}
        <a href="{{ route('teams.browse') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
            <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            Back to Teams
        </a>

        {{-- Team Header --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm overflow-hidden">
            {{-- Color banner --}}
            <div class="h-3" style="background: linear-gradient(135deg, {{ $team->primary_color ?: '#C12E26' }}, {{ $team->secondary_color ?: '#9A231F' }})"></div>

            <div class="p-6">
                <div class="flex items-start justify-between">
                    <div>
                        <h1 class="text-3xl font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide">
                            {{ $team->name }}
                        </h1>
                        @if($team->city || $team->country)
                            <p class="mt-1 text-gray-500 dark:text-gray-400 flex items-center gap-1">
                                <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                {{ collect([$team->city, $team->country])->filter()->join(', ') }}
                            </p>
                        @endif
                    </div>

                    @if($isCaptain)
                        <a href="{{ route('teams.manage', $team->slug) }}" wire:navigate
                           class="inline-flex items-center gap-1.5 px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 text-sm font-medium transition-colors">
                            <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            Manage
                        </a>
                    @endif
                </div>

                @if($team->description)
                    <p class="mt-4 text-gray-600 dark:text-gray-300">{{ $team->description }}</p>
                @endif

                <div class="mt-4 flex flex-wrap gap-4 text-sm text-gray-500 dark:text-gray-400">
                    @if($team->founded_year)
                        <span>Est. {{ $team->founded_year }}</span>
                    @endif
                    <span>{{ $team->activeMembers->count() }} members</span>
                    @if($team->website)
                        <a href="{{ $team->website }}" target="_blank" rel="noopener" class="text-brand-dark hover:underline">{{ $team->website }}</a>
                    @endif
                </div>
            </div>
        </div>

        {{-- Roster --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <h2 class="text-xl font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide mb-4">Roster</h2>

            @if($team->activeMembers->count())
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($team->activeMembers as $member)
                        <div class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                            {{-- Avatar --}}
                            <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold uppercase
                                {{ $member->role === 'captain' ? 'bg-[#C12E26]/10 text-[#C12E26]' : ($member->role === 'coach' ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400') }}">
                                {{ strtoupper($member->user->name[0] ?? '?') }}
                            </div>

                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $member->user->name }}</p>
                                @if($member->position)
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $member->position }}</p>
                                @endif
                            </div>

                            {{-- Role badge --}}
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                {{ $member->role === 'captain' ? 'bg-[#C12E26]/10 text-[#C12E26]' : ($member->role === 'coach' ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : ($member->role === 'substitute' ? 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400')) }}">
                                {{ ucfirst($member->role) }}
                            </span>

                            @if($member->jersey_number)
                                <span class="text-sm font-mono text-gray-500 dark:text-gray-400 w-8 text-center">#{{ $member->jersey_number }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400 italic py-4 text-center">No active members yet.</p>
            @endif
        </section>
    </div>
</div>

<div class="py-8">
    <div class="max-w-4xl mx-auto space-y-6">
        {{-- Back --}}
        <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
            <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            Back to Dashboard
        </a>

        {{-- Game Header --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm overflow-hidden">
            <div class="h-3 bg-[#C12E26]"></div>

            <div class="p-6">
                <div class="flex items-start justify-between">
                    <div>
                        <h1 class="text-3xl font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide">
                            {{ $game->name }}
                        </h1>
                        @if($game->gameSystem)
                            <p class="mt-1 text-sm text-brand-dark font-medium">{{ $game->gameSystem->name }}</p>
                        @endif
                    </div>

                    @if($isOwner)
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-[#C12E26]/10 text-[#C12E26]">
                            Owner
                        </span>
                    @endif
                </div>

                @if($game->description)
                    <p class="mt-4 text-gray-600 dark:text-gray-300">{{ $game->description }}</p>
                @endif

                <div class="mt-4 flex flex-wrap gap-4 text-sm text-gray-500 dark:text-gray-400">
                    <span class="flex items-center gap-1">
                        <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                        {{ $game->date_time->format('M j, Y \a\t g:i A') }}
                    </span>
                    @if($game->expected_duration)
                        <span class="flex items-center gap-1">
                            <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            {{ $game->expected_duration }}h
                        </span>
                    @endif
                    @if($game->price > 0)
                        <span class="flex items-center gap-1">
                            ${{ number_format($game->price, 2) }}
                        </span>
                    @else
                        <span class="flex items-center gap-1 text-green-600 dark:text-green-400">Free</span>
                    @endif
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                        {{ $game->visibility === 'public' ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400' : ($game->visibility === 'protected' ? 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400' : 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400') }}">
                        {{ ucfirst($game->visibility) }}
                    </span>
                </div>

                @if($game->location)
                    <div class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                        <span class="flex items-center gap-1">
                            <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                            {{ ucfirst($game->location['type'] ?? 'online') }}
                            @if(!empty($game->location['details']))
                                — {{ $game->location['details'] }}
                            @endif
                        </span>
                    </div>
                @endif
            </div>
        </div>

        {{-- Participants --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <h2 class="text-xl font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide mb-4">Participants</h2>

            @if($game->participants->count())
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($game->participants as $participant)
                        <div class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold uppercase
                                {{ $participant->role === 'gm' ? 'bg-[#C12E26]/10 text-[#C12E26]' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400' }}">
                                {{ strtoupper($participant->user->name[0] ?? '?') }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $participant->user->name }}</p>
                            </div>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                {{ $participant->role === 'gm' ? 'bg-[#C12E26]/10 text-[#C12E26]' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400' }}">
                                {{ strtoupper($participant->role) }}
                            </span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                {{ $participant->status === 'confirmed' ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400' : 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400' }}">
                                {{ ucfirst($participant->status) }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400 italic py-4 text-center">No participants yet.</p>
            @endif
        </section>

        {{-- Applications (visible to owner) --}}
        @if($isOwner && $game->applications->count())
            <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
                <h2 class="text-xl font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide mb-4">Applications</h2>

                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($game->applications as $application)
                        <div class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold uppercase bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400">
                                {{ strtoupper($application->user->name[0] ?? '?') }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $application->user->name }}</p>
                                @if($application->message)
                                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $application->message }}</p>
                                @endif
                            </div>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                {{ $application->status === 'pending' ? 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400' : ($application->status === 'accepted' ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400' : 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400') }}">
                                {{ ucfirst($application->status) }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Game Owner Info --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <h2 class="text-xl font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide mb-4">Hosted by</h2>
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-full flex items-center justify-center text-lg font-bold uppercase bg-[#C12E26]/10 text-[#C12E26]">
                    {{ strtoupper($game->owner->name[0] ?? '?') }}
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $game->owner->name }}</p>
                </div>
            </div>
        </section>
    </div>
</div>

<div class="py-8">
    <div class="max-w-4xl mx-auto space-y-6">
        {{-- Back --}}
        <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            Back to Dashboard
        </a>

        {{-- Campaign Header --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm overflow-hidden">
            <div class="h-3 bg-gradient-to-r from-[#C12E26] to-[#9A231F]"></div>

            <div class="p-6">
                <div class="flex items-start justify-between">
                    <div>
                        <h1 class="text-3xl font-['Oswald'] font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide">
                            {{ $campaign->name }}
                        </h1>
                        @if($campaign->gameSystem)
                            <p class="mt-1 text-sm text-[#C12E26] font-medium">{{ $campaign->gameSystem->name }}</p>
                        @endif
                    </div>

                    @if($isOwner)
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-[#C12E26]/10 text-[#C12E26]">
                            Owner
                        </span>
                    @endif
                </div>

                @if($campaign->description)
                    <p class="mt-4 text-gray-600 dark:text-gray-300">{{ $campaign->description }}</p>
                @endif

                <div class="mt-4 flex flex-wrap gap-4 text-sm text-gray-500 dark:text-gray-400">
                    <span class="flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                        {{ ucfirst($campaign->recurrence) }}
                    </span>
                    <span class="flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        {{ $campaign->time_of_day }}
                        @if($campaign->session_duration)
                            ({{ $campaign->session_duration }}h)
                        @endif
                    </span>
                    @if($campaign->price_per_session > 0)
                        <span>${{ number_format($campaign->price_per_session, 2) }}/session</span>
                    @else
                        <span class="text-green-600 dark:text-green-400">Free</span>
                    @endif
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                        {{ $campaign->visibility === 'public' ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400' : ($campaign->visibility === 'protected' ? 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400' : 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400') }}">
                        {{ ucfirst($campaign->visibility) }}
                    </span>
                </div>

                @if($campaign->location)
                    <div class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                        <span class="flex items-center gap-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                            {{ ucfirst($campaign->location['type'] ?? 'online') }}
                            @if(!empty($campaign->location['details']))
                                — {{ $campaign->location['details'] }}
                            @endif
                        </span>
                    </div>
                @endif
            </div>
        </div>

        {{-- Upcoming Sessions --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <h2 class="text-xl font-['Oswald'] font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide mb-4">Sessions</h2>

            @if($campaign->sessions->count())
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($campaign->sessions as $session)
                        <div class="flex items-center justify-between py-3 first:pt-0 last:pb-0">
                            <div>
                                <a href="{{ route('games.detail', $session->id) }}" class="text-sm font-medium text-gray-900 dark:text-gray-100 hover:text-[#C12E26] transition-colors">
                                    {{ $session->name }}
                                </a>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $session->date_time->format('M j, Y \a\t g:i A') }}
                                </p>
                            </div>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                {{ $session->status === 'scheduled' ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400' : ($session->status === 'completed' ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400') }}">
                                {{ ucfirst($session->status) }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400 italic py-4 text-center">No sessions scheduled yet.</p>
            @endif
        </section>

        {{-- Participants --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <h2 class="text-xl font-['Oswald'] font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide mb-4">Participants</h2>

            @if($campaign->participants->count())
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($campaign->participants as $participant)
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

        {{-- Campaign Owner Info --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
            <h2 class="text-xl font-['Oswald'] font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide mb-4">Run by</h2>
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-full flex items-center justify-center text-lg font-bold uppercase bg-[#C12E26]/10 text-[#C12E26]">
                    {{ strtoupper($campaign->owner->name[0] ?? '?') }}
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $campaign->owner->name }}</p>
                </div>
            </div>
        </section>
    </div>
</div>

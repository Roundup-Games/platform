<div>
    {{-- Back link --}}
    <div class="bg-gray-100 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-3">
            <a href="{{ route('events.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
                <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Back to Events
            </a>
        </div>
    </div>

    {{-- Event Header / Banner --}}
    <section class="bg-gradient-to-br from-[#C12E26] to-[#9A231F] dark:from-gray-800 dark:to-gray-900 text-white">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-12 sm:py-16">
            <div class="flex flex-wrap items-center gap-2 mb-4">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-white/20 text-white">
                    {{ ucfirst($event->type) }}
                </span>
                @if($event->status === 'registration_open')
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-500/30 text-green-100">
                        ● Registration Open
                    </span>
                @elseif($event->status === 'in_progress')
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-500/30 text-blue-100">
                        ● In Progress
                    </span>
                @elseif($event->status === 'registration_closed')
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-500/30 text-yellow-100">
                        ● Registration Closed
                    </span>
                @endif
                @if($event->is_featured)
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-white/10 text-white">
                        ★ Featured
                    </span>
                @endif
            </div>

            <h1 class="text-3xl sm:text-4xl font-heading font-bold uppercase tracking-wide">{{ $event->name }}</h1>

            @if($event->short_description)
                <p class="mt-3 text-lg text-white/80 max-w-3xl">{{ $event->short_description }}</p>
            @endif

            {{-- Quick info row --}}
            <div class="mt-6 flex flex-wrap gap-6 text-sm text-white/80">
                {{-- Date --}}
                <span class="flex items-center gap-2">
                    <svg aria-hidden="true" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                    {{ $event->start_date->format('M j, Y') }}
                    @if($event->end_date && $event->end_date->ne($event->start_date))
                        – {{ $event->end_date->format('M j, Y') }}
                    @endif
                </span>

                {{-- Location --}}
                @if($event->venue_name || $event->city)
                    <span class="flex items-center gap-2">
                        <svg aria-hidden="true" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                        {{ collect([$event->venue_name, $event->city, $event->country])->filter()->join(', ') }}
                    </span>
                @endif

                {{-- Registration Type --}}
                <span class="flex items-center gap-2">
                    <svg aria-hidden="true" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                    {{ ucfirst($event->registration_type) }} Registration
                </span>
            </div>
        </div>
    </section>

    {{-- Content --}}
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            {{-- Main Content --}}
            <div class="lg:col-span-2 space-y-6">

                {{-- Description --}}
                @if($event->description)
                    <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
                        <h2 class="text-xl font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide mb-4">About This Event</h2>
                        <div class="prose prose-sm dark:prose-invert max-w-none text-gray-600 dark:text-gray-300">
                            {{ $event->description }}
                        </div>
                    </section>
                @endif

                {{-- Divisions --}}
                @if($event->divisions && count($event->divisions) > 0)
                    <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
                        <h2 class="text-xl font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide mb-4">Divisions</h2>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            @foreach($event->divisions as $division)
                                <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                                    @if(is_array($division))
                                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $division['name'] ?? 'Division' }}</h3>
                                        @if(isset($division['description']))
                                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $division['description'] }}</p>
                                        @endif
                                    @else
                                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $division }}</h3>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                {{-- Schedule --}}
                @if($event->schedule && count($event->schedule) > 0)
                    <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
                        <h2 class="text-xl font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide mb-4">Schedule</h2>
                        <div class="space-y-3">
                            @foreach($event->schedule as $item)
                                <div class="flex items-start gap-3 py-2 {{ !$loop->last ? 'border-b border-gray-100 dark:border-gray-700' : '' }}">
                                    <div class="w-2 h-2 mt-2 rounded-full bg-[#C12E26] shrink-0"></div>
                                    <div>
                                        @if(is_array($item))
                                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $item['date'] ?? '' }} {{ $item['time'] ?? '' }}</p>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $item['event'] ?? $item['title'] ?? '' }}</p>
                                        @else
                                            <p class="text-sm text-gray-700 dark:text-gray-300">{{ $item }}</p>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                {{-- Announcements --}}
                @if($announcements->count())
                    <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
                        <h2 class="text-xl font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide mb-4">Announcements</h2>
                        <div class="space-y-4">
                            @foreach($announcements as $announcement)
                                <div class="border-l-4 {{ $announcement->is_pinned ? 'border-[#C12E26] bg-[#C12E26]/5 dark:bg-[#C12E26]/10' : 'border-gray-300 dark:border-gray-600' }} pl-4 py-2">
                                    <div class="flex items-center gap-2">
                                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $announcement->title }}</h3>
                                        @if($announcement->is_pinned)
                                            <span class="text-xs text-brand-dark">📌 Pinned</span>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $announcement->content }}</p>
                                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ $announcement->created_at->format('M j, Y \a\t g:i A') }}</p>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>

            {{-- Sidebar --}}
            <div class="space-y-6">
                {{-- Registration Card --}}
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6 sticky top-6">
                    <h3 class="font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide">Registration</h3>

                    {{-- Status --}}
                    <div class="mt-4">
                        @if($event->isRegistrationOpen())
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-medium bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400">
                                <span class="w-2 h-2 rounded-full bg-green-500"></span>
                                Registration Open
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-medium bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400">
                                Registration {{ ucfirst(str_replace('_', ' ', $event->status)) }}
                            </span>
                        @endif
                    </div>

                    {{-- Registration Window --}}
                    @if($event->registration_opens_at || $event->registration_closes_at)
                        <div class="mt-4 text-sm space-y-1">
                            @if($event->registration_opens_at)
                                <p class="text-gray-500 dark:text-gray-400">
                                    <span class="font-medium text-gray-700 dark:text-gray-300">Opens:</span>
                                    {{ $event->registration_opens_at->format('M j, Y \a\t g:i A') }}
                                </p>
                            @endif
                            @if($event->registration_closes_at)
                                <p class="text-gray-500 dark:text-gray-400">
                                    <span class="font-medium text-gray-700 dark:text-gray-300">Closes:</span>
                                    {{ $event->registration_closes_at->format('M j, Y \a\t g:i A') }}
                                </p>
                            @endif
                        </div>
                    @endif

                    {{-- Capacity --}}
                    <div class="mt-4">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Capacity</p>
                        @if($event->registration_type === 'team' || $event->registration_type === 'both')
                            <div class="flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
                                <span>Teams</span>
                                <span>{{ $teamCount }}{{ $event->max_teams ? '/' . $event->max_teams : '' }}</span>
                            </div>
                            @if($event->max_teams)
                                @php $teamPct = min(100, ($teamCount / $event->max_teams) * 100) @endphp
                                <div class="mt-1 w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                    <div class="h-2 rounded-full {{ $teamPct >= 90 ? 'bg-red-500' : ($teamPct >= 70 ? 'bg-yellow-500' : 'bg-green-500') }}" style="width: {{ $teamPct }}%"></div>
                                </div>
                                @if($teamPct >= 90)
                                    <p class="text-xs text-red-600 dark:text-red-400 mt-1">⚠️ Nearly full!</p>
                                @endif
                            @endif
                        @endif
                        @if($event->registration_type === 'individual' || $event->registration_type === 'both')
                            <div class="flex items-center justify-between text-sm text-gray-500 dark:text-gray-400 {{ ($event->registration_type === 'both') ? 'mt-2' : '' }}">
                                <span>Participants</span>
                                <span>{{ $individualCount }}{{ $event->max_participants ? '/' . $event->max_participants : '' }}</span>
                            </div>
                            @if($event->max_participants)
                                @php $indPct = min(100, ($individualCount / $event->max_participants) * 100) @endphp
                                <div class="mt-1 w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                    <div class="h-2 rounded-full {{ $indPct >= 90 ? 'bg-red-500' : ($indPct >= 70 ? 'bg-yellow-500' : 'bg-green-500') }}" style="width: {{ $indPct }}%"></div>
                                </div>
                                @if($indPct >= 90)
                                    <p class="text-xs text-red-600 dark:text-red-400 mt-1">⚠️ Nearly full!</p>
                                @endif
                            @endif
                        @endif
                    </div>

                    {{-- Fees --}}
                    <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Fees</p>
                        @if($event->team_registration_fee > 0)
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                Team: ${{ number_format($event->team_registration_fee / 100, 2) }}
                                @if($event->early_bird_discount && $event->early_bird_deadline && now()->lt($event->early_bird_deadline))
                                    <span class="text-green-600 dark:text-green-400 ml-1">(Early bird: -${{ number_format($event->early_bird_discount / 100, 2) }})</span>
                                @endif
                            </p>
                        @endif
                        @if($event->individual_registration_fee > 0)
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                Individual: ${{ number_format($event->individual_registration_fee / 100, 2) }}
                                @if($event->early_bird_discount && $event->early_bird_deadline && now()->lt($event->early_bird_deadline))
                                    <span class="text-green-600 dark:text-green-400 ml-1">(Early bird: -${{ number_format($event->early_bird_discount / 100, 2) }})</span>
                                @endif
                            </p>
                        @endif
                        @if($event->team_registration_fee === 0 && $event->individual_registration_fee === 0)
                            <p class="text-sm text-green-600 dark:text-green-400 font-medium">Free</p>
                        @endif
                    </div>

                    {{-- Register button --}}
                    <div class="mt-6">
                        @if($event->isRegistrationOpen() && $event->hasCapacity())
                            @auth
                                <a href="{{ route('events.register', ['slug' => $event->slug]) }}" wire:navigate class="block w-full text-center px-4 py-3 bg-[#C12E26] text-white rounded-lg hover:bg-[#9A231F] transition-colors font-medium">
                                    Register Now
                                </a>
                            @else
                                <a href="{{ route('login') }}" wire:navigate class="block w-full text-center px-4 py-3 bg-[#C12E26] text-white rounded-lg hover:bg-[#9A231F] transition-colors font-medium">
                                    Sign in to Register
                                </a>
                            @endauth
                        @elseif($event->isRegistrationOpen() && !$event->hasCapacity())
                            <button disabled class="block w-full text-center px-4 py-3 bg-gray-300 dark:bg-gray-600 text-gray-500 dark:text-gray-400 rounded-lg cursor-not-allowed font-medium">
                                Event Full
                            </button>
                        @else
                            <button disabled class="block w-full text-center px-4 py-3 bg-gray-300 dark:bg-gray-600 text-gray-500 dark:text-gray-400 rounded-lg cursor-not-allowed font-medium">
                                Registration Closed
                            </button>
                        @endif
                    </div>
                </div>

                {{-- Venue Card --}}
                @if($event->venue_name || $event->venue_address)
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
                        <h3 class="font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide">Venue</h3>
                        <div class="mt-3 text-sm text-gray-600 dark:text-gray-300 space-y-1">
                            @if($event->venue_name)
                                <p class="font-medium">{{ $event->venue_name }}</p>
                            @endif
                            @if($event->venue_address)
                                <p>{{ $event->venue_address }}</p>
                            @endif
                            <p>{{ collect([$event->city, $event->country, $event->postal_code])->filter()->join(', ') }}</p>
                        </div>
                    </div>
                @endif

                {{-- Contact Card --}}
                @if($event->contact_email || $event->contact_phone)
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
                        <h3 class="font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide">Contact</h3>
                        <div class="mt-3 text-sm space-y-1">
                            @if($event->contact_email)
                                <p class="text-gray-600 dark:text-gray-300">
                                    <a href="mailto:{{ $event->contact_email }}" class="text-brand-dark hover:underline">{{ $event->contact_email }}</a>
                                </p>
                            @endif
                            @if($event->contact_phone)
                                <p class="text-gray-600 dark:text-gray-300">{{ $event->contact_phone }}</p>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

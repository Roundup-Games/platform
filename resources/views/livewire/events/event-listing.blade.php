<div>
    <x-hero title="Events" subtitle="Discover tournaments, leagues, camps, and more in your area." />

    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 space-y-6">
        {{-- Search & Filters --}}
        <div class="flex flex-col sm:flex-row gap-3">
            <div class="flex-1 relative">
                <svg aria-hidden="true" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input type="text" aria-label="Search events" wire:model.live.debounce.300ms="search" placeholder="Search events by name, city, or venue..."
                       class="w-full pl-10 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
            </div>
            <select wire:model.live="type" aria-label="Filter by event type"
                    class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]">
                <option value="">All Types</option>
                <option value="tournament">Tournament</option>
                <option value="league">League</option>
                <option value="camp">Camp</option>
                <option value="clinic">Clinic</option>
                <option value="social">Social</option>
                <option value="other">Other</option>
            </select>
            <select wire:model.live="status" aria-label="Filter by event status"
                    class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]">
                <option value="">All Statuses</option>
                <option value="registration_open">Registration Open</option>
                <option value="registration_closed">Registration Closed</option>
                <option value="in_progress">In Progress</option>
                <option value="published">Published</option>
            </select>
            <select wire:model.live="date" aria-label="Filter by date"
                    class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]">
                <option value="">Any Date</option>
                <option value="upcoming">Upcoming</option>
                <option value="this_week">This Week</option>
                <option value="this_month">This Month</option>
                <option value="past">Past</option>
            </select>
        </div>

        {{-- Active filters --}}
        @if($search || $type || $status || $date)
            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-sm text-gray-500 dark:text-gray-400">Filters:</span>
                @if($search)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                        "{{ $search }}"
                    </span>
                @endif
                @if($type)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400">
                        {{ ucfirst($type) }}
                    </span>
                @endif
                @if($status)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400">
                        {{ str_replace('_', ' ', ucfirst($status)) }}
                    </span>
                @endif
                @if($date)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400">
                        {{ str_replace('_', ' ', ucfirst($date)) }}
                    </span>
                @endif
                <button wire:click="clearFilters" class="text-xs text-brand-dark hover:underline">Clear all</button>
            </div>
        @endif

        {{-- Events Grid --}}
        @if($events->count())
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($events as $event)
                    <a href="{{ route('events.detail', $event->slug) }}" wire:navigate class="block bg-white dark:bg-gray-800 rounded-lg shadow-sm hover:shadow-md transition-shadow border border-gray-100 dark:border-gray-700 overflow-hidden group">
                        {{-- Featured indicator --}}
                        @if($event->is_featured)
                            <div class="h-1.5 bg-gradient-to-r from-[#C12E26] to-[#E8483F]"></div>
                        @else
                            <div class="h-1.5 bg-gray-200 dark:bg-gray-700"></div>
                        @endif

                        <div class="p-5">
                            <div class="flex items-start justify-between mb-2">
                                <h3 class="font-heading font-semibold text-lg text-gray-900 dark:text-gray-100 uppercase tracking-wide group-hover:text-[#C12E26] transition-colors">
                                    {{ $event->name }}
                                </h3>
                                @if($event->is_featured)
                                    <span class="shrink-0 ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-[#C12E26]/10 text-[#C12E26]">
                                        ★ Featured
                                    </span>
                                @endif
                            </div>

                            {{-- Type badge --}}
                            <div class="flex items-center gap-2 mb-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400">
                                    {{ ucfirst($event->type) }}
                                </span>
                                @if($event->status === 'registration_open')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400">
                                        Registration Open
                                    </span>
                                @elseif($event->status === 'in_progress')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400">
                                        In Progress
                                    </span>
                                @elseif($event->status === 'registration_closed')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400">
                                        Registration Closed
                                    </span>
                                @endif
                            </div>

                            {{-- Date --}}
                            <p class="text-sm text-gray-500 dark:text-gray-400 flex items-center gap-1">
                                <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                                {{ $event->start_date->format('M j, Y') }}
                                @if($event->end_date && $event->end_date->ne($event->start_date))
                                    – {{ $event->end_date->format('M j, Y') }}
                                @endif
                            </p>

                            {{-- Location --}}
                            @if($event->city || $event->venue_name)
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400 flex items-center gap-1">
                                    <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                    {{ collect([$event->venue_name, $event->city])->filter()->join(' · ') }}
                                </p>
                            @endif

                            {{-- Short description --}}
                            @if($event->short_description)
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300 line-clamp-2">{{ $event->short_description }}</p>
                            @endif

                            {{-- Fee info --}}
                            @if($event->team_registration_fee > 0 || $event->individual_registration_fee > 0)
                                <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                                    @if($event->individual_registration_fee > 0)
                                        From ${{ number_format($event->individual_registration_fee / 100, 2) }}/player
                                    @elseif($event->team_registration_fee > 0)
                                        ${{ number_format($event->team_registration_fee / 100, 2) }}/team
                                    @endif
                                </p>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $events->links() }}
            </div>
        @else
            <div class="text-center py-16 bg-white dark:bg-gray-800 rounded-lg">
                <svg aria-hidden="true" class="mx-auto w-12 h-12 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">No events found</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    @if($search || $type || $status || $date)
                        Try adjusting your filters.
                    @else
                        Check back soon for upcoming events!
                    @endif
                </p>
            </div>
        @endif
    </div>
</div>

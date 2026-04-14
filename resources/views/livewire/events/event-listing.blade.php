<div>
    <x-hero title="Events" subtitle="Discover tournaments, leagues, camps, and more in your area." />

    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 space-y-6">
        {{-- Search & Filters --}}
        <div class="flex flex-col sm:flex-row gap-3">
            <div class="flex-1 relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-lg" aria-hidden="true">search</span>
                <input type="text" aria-label="Search events" wire:model.live.debounce.300ms="search" placeholder="Search events by name, city, or venue..."
                       class="w-full pl-10 bg-surface-container-high border border-transparent rounded-full text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
            </div>
            <select wire:model.live="type" aria-label="Filter by event type"
                    class="bg-surface-container-high border border-transparent rounded-lg text-on-surface shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                <option value="">All Types</option>
                <option value="tournament">Tournament</option>
                <option value="league">League</option>
                <option value="camp">Camp</option>
                <option value="clinic">Clinic</option>
                <option value="social">Social</option>
                <option value="other">Other</option>
            </select>
            <select wire:model.live="status" aria-label="Filter by event status"
                    class="bg-surface-container-high border border-transparent rounded-lg text-on-surface shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                <option value="">All Statuses</option>
                <option value="registration_open">Registration Open</option>
                <option value="registration_closed">Registration Closed</option>
                <option value="in_progress">In Progress</option>
                <option value="published">Published</option>
            </select>
            <select wire:model.live="date" aria-label="Filter by date"
                    class="bg-surface-container-high border border-transparent rounded-lg text-on-surface shadow-sm focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
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
                <span class="text-sm text-on-surface-variant">Filters:</span>
                @if($search)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface">
                        "{{ $search }}"
                    </span>
                @endif
                @if($type)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                        {{ ucfirst($type) }}
                    </span>
                @endif
                @if($status)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                        {{ str_replace('_', ' ', ucfirst($status)) }}
                    </span>
                @endif
                @if($date)
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-tertiary/10 text-on-tertiary-container">
                        {{ str_replace('_', ' ', ucfirst($date)) }}
                    </span>
                @endif
                <button wire:click="clearFilters" class="text-xs text-primary hover:underline">Clear all</button>
            </div>
        @endif

        {{-- Events Grid --}}
        @if($events->count())
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($events as $event)
                    <a href="{{ route('events.detail', $event->slug) }}" wire:navigate class="block bg-surface rounded-xl shadow-ambient hover:shadow-md transition-shadow overflow-hidden group">
                        {{-- Featured indicator --}}
                        @if($event->is_featured)
                            <div class="h-1.5 bg-gradient-to-r from-primary to-primary-container"></div>
                        @else
                            <div class="h-1.5 bg-outline-variant/30"></div>
                        @endif

                        <div class="p-5">
                            <div class="flex items-start justify-between mb-2">
                                <h3 class="font-heading font-semibold text-lg text-on-surface tracking-tight group-hover:text-primary transition-colors">
                                    {{ $event->name }}
                                </h3>
                                @if($event->is_featured)
                                    <span class="shrink-0 ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                                        ★ Featured
                                    </span>
                                @endif
                            </div>

                            {{-- Type badge --}}
                            <div class="flex items-center gap-2 mb-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container text-on-surface-variant">
                                    {{ ucfirst($event->type) }}
                                </span>
                                @if($event->status === 'registration_open')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                                        Registration Open
                                    </span>
                                @elseif($event->status === 'in_progress')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-tertiary/10 text-on-tertiary-container">
                                        In Progress
                                    </span>
                                @elseif($event->status === 'registration_closed')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container-high text-on-surface-variant">
                                        Registration Closed
                                    </span>
                                @endif
                            </div>

                            {{-- Date --}}
                            <p class="text-sm text-on-surface-variant flex items-center gap-1">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">calendar_today</span>
                                {{ $event->start_date->format('M j, Y') }}
                                @if($event->end_date && $event->end_date->ne($event->start_date))
                                    – {{ $event->end_date->format('M j, Y') }}
                                @endif
                            </p>

                            {{-- Location --}}
                            @if($event->city || $event->venue_name)
                                <p class="mt-1 text-sm text-on-surface-variant flex items-center gap-1">
                                    <span class="material-symbols-outlined text-base" aria-hidden="true">location_on</span>
                                    {{ collect([$event->venue_name, $event->city])->filter()->join(' · ') }}
                                </p>
                            @endif

                            {{-- Short description --}}
                            @if($event->short_description)
                                <p class="mt-2 text-sm text-on-surface-variant line-clamp-2">{{ $event->short_description }}</p>
                            @endif

                            {{-- Fee info --}}
                            @if($event->team_registration_fee > 0 || $event->individual_registration_fee > 0)
                                <p class="mt-2 text-xs text-on-surface-variant/70">
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
            <div class="text-center py-16 bg-surface rounded-xl shadow-ambient">
                <span class="material-symbols-outlined text-5xl text-on-surface-variant/40" aria-hidden="true">event_busy</span>
                <h3 class="mt-2 text-sm font-medium text-on-surface">No events found</h3>
                <p class="mt-1 text-sm text-on-surface-variant">
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

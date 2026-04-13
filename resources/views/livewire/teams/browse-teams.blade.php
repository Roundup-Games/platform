<div class="py-8">
    <div class="max-w-6xl mx-auto space-y-6">
        {{-- Page Header --}}
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-heading font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide">Browse Teams</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Discover and join teams in your area.</p>
            </div>
            @auth
                <a href="{{ route('teams.create') }}" wire:navigate
                   class="px-4 py-2 bg-[#C12E26] text-white rounded-lg hover:bg-[#9A231F] transition-colors text-sm font-medium">
                    + Create Team
                </a>
            @endauth
        </div>

        {{-- Search & Sort --}}
        <div class="flex flex-col sm:flex-row gap-3">
            <div class="flex-1 relative">
                <svg aria-hidden="true" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input type="text" aria-label="Search teams" wire:model.live.debounce.300ms="search" placeholder="Search by name, city, or country..."
                       class="w-full pl-10 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
            </div>
            <select wire:model.live="sort" aria-label="Sort teams"
                    class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]">
                <option value="newest">Newest</option>
                <option value="name">Name A–Z</option>
                <option value="members">Most Members</option>
            </select>
        </div>

        {{-- Team Grid --}}
        @if($teams->count())
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($teams as $team)
                    <a href="{{ route('teams.detail', $team->slug) }}" wire:navigate class="block bg-white dark:bg-gray-800 rounded-lg shadow-sm hover:shadow-md transition-shadow border border-gray-100 dark:border-gray-700 overflow-hidden group">
                        {{-- Color banner --}}
                        <div class="h-2" style="background-color: {{ $team->primary_color ?: '#C12E26' }}"></div>

                        <div class="p-5">
                            <div class="flex items-start justify-between mb-2">
                                <h3 class="font-heading font-semibold text-lg text-gray-900 dark:text-gray-100 uppercase tracking-wide group-hover:text-[#C12E26] transition-colors">
                                    {{ $team->name }}
                                </h3>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400">
                                    {{ $team->active_members_count ?? 0 }} members
                                </span>
                            </div>

                            @if($team->city || $team->country)
                                <p class="text-sm text-gray-500 dark:text-gray-400 flex items-center gap-1">
                                    <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                    {{ collect([$team->city, $team->country])->filter()->join(', ') }}
                                </p>
                            @endif

                            @if($team->description)
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300 line-clamp-2">{{ Str::limit($team->description, 120) }}</p>
                            @endif

                            @if($team->founded_year)
                                <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">Est. {{ $team->founded_year }}</p>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $teams->links() }}
            </div>
        @else
            <div class="text-center py-16 bg-white dark:bg-gray-800 rounded-lg">
                <svg aria-hidden="true" class="mx-auto w-12 h-12 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">No teams found</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    @if($search)
                        Try adjusting your search terms.
                    @else
                        Be the first to create a team!
                    @endif
                </p>
            </div>
        @endif
    </div>
</div>

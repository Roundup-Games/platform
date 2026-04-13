<x-public-layout title="Home">
    {{-- Hero Section --}}
    <section class="relative bg-gradient-to-br from-[#C12E26] via-[#A82820] to-[#8B2019] text-white overflow-hidden">
        {{-- Decorative background shapes --}}
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 right-0 w-96 h-96 bg-white rounded-full -translate-y-1/2 translate-x-1/3"></div>
            <div class="absolute bottom-0 left-0 w-72 h-72 bg-white rounded-full translate-y-1/2 -translate-x-1/3"></div>
        </div>

        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 py-20 sm:py-28 lg:py-36">
            <div class="max-w-3xl">
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-heading font-bold uppercase tracking-wide leading-tight">
                    Organize. Compete.<br>
                    <span class="text-yellow-300">Connect.</span>
                </h1>
                <p class="mt-6 text-lg sm:text-xl text-white/80 max-w-xl">
                    Roundup Games brings communities together through competitive events. Find tournaments, join teams, and compete in your favorite games.
                </p>
                <div class="mt-8 flex flex-wrap gap-4">
                    <a href="{{ route('events.index') }}" wire:navigate class="inline-flex items-center px-6 py-3 bg-white text-[#C12E26] rounded-lg font-semibold hover:bg-white/90 transition-colors text-sm">
                        <svg aria-hidden="true" class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        Browse Events
                    </a>
                    @auth
                        <a href="{{ route('events.create') }}" wire:navigate class="inline-flex items-center px-6 py-3 bg-white/20 text-white rounded-lg font-semibold hover:bg-white/30 transition-colors text-sm border border-white/30">
                            <svg aria-hidden="true" class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                            Create Event
                        </a>
                    @else
                        <a href="{{ route('register') }}" wire:navigate class="inline-flex items-center px-6 py-3 bg-white/20 text-white rounded-lg font-semibold hover:bg-white/30 transition-colors text-sm border border-white/30">
                            Get Started
                        </a>
                    @endauth
                </div>
            </div>
        </div>
    </section>

    {{-- Stats Bar --}}
    <section class="bg-gray-900 dark:bg-gray-950 text-white">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-6 text-center">
                <div>
                    <div class="text-2xl sm:text-3xl font-heading font-bold text-[#E8483F]">{{ $upcomingEvents->count() + $featuredEvents->count() }}</div>
                    <div class="text-sm text-gray-400 mt-1">Active Events</div>
                </div>
                <div>
                    <div class="text-2xl sm:text-3xl font-heading font-bold text-[#E8483F]">{{ $featuredEvents->count() }}</div>
                    <div class="text-sm text-gray-400 mt-1">Featured</div>
                </div>
                <div>
                    <div class="text-2xl sm:text-3xl font-heading font-bold text-[#E8483F]">{{ $teamCount }}</div>
                    <div class="text-sm text-gray-400 mt-1">Teams</div>
                </div>
                <div>
                    <div class="text-2xl sm:text-3xl font-heading font-bold text-[#E8483F]">{{ $registrationCount }}</div>
                    <div class="text-sm text-gray-400 mt-1">Registrations</div>
                </div>
            </div>
        </div>
    </section>

    {{-- Featured Events --}}
    @if($featuredEvents->isNotEmpty())
    <section class="py-16 sm:py-20 bg-gray-50 dark:bg-gray-900">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <h2 class="text-3xl sm:text-4xl font-heading font-bold uppercase text-gray-900 dark:text-gray-100">
                    Featured Events
                </h2>
                <p class="mt-3 text-gray-600 dark:text-gray-400 max-w-xl mx-auto">
                    Don't miss out on these highlighted competitions happening soon.
                </p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($featuredEvents as $event)
                    <x-event-card :event="$event" />
                @endforeach
            </div>
        </div>
    </section>
    @endif

    {{-- Upcoming Events --}}
    @if($upcomingEvents->isNotEmpty())
    <section class="py-16 sm:py-20 bg-white dark:bg-gray-800">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="flex items-center justify-between mb-10">
                <div>
                    <h2 class="text-3xl sm:text-4xl font-heading font-bold uppercase text-gray-900 dark:text-gray-100">
                        Upcoming Events
                    </h2>
                    <p class="mt-2 text-gray-600 dark:text-gray-400">
                        See what's coming up next.
                    </p>
                </div>
                <a href="{{ route('events.index') }}" wire:navigate class="hidden sm:inline-flex items-center px-4 py-2 text-sm font-medium text-[#C12E26] border border-[#C12E26] rounded-lg hover:bg-[#C12E26] hover:text-white transition-colors">
                    View All Events
                </a>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($upcomingEvents as $event)
                    @if(!$featuredEvents->contains('id', $event->id))
                        <x-event-card :event="$event" />
                    @endif
                @endforeach
            </div>
            <div class="mt-8 text-center sm:hidden">
                <a href="{{ route('events.index') }}" wire:navigate class="inline-flex items-center px-6 py-3 text-sm font-medium text-[#C12E26] border border-[#C12E26] rounded-lg hover:bg-[#C12E26] hover:text-white transition-colors">
                    View All Events
                </a>
            </div>
        </div>
    </section>
    @endif

    {{-- Features Overview --}}
    <section class="py-16 sm:py-20 bg-gray-50 dark:bg-gray-900">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <h2 class="text-3xl sm:text-4xl font-heading font-bold uppercase text-gray-900 dark:text-gray-100">
                    Everything You Need
                </h2>
                <p class="mt-3 text-gray-600 dark:text-gray-400 max-w-xl mx-auto">
                    From event discovery to registration to competition management.
                </p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">
                {{-- Feature: Event Discovery --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="w-12 h-12 bg-[#C12E26]/10 dark:bg-[#C12E26]/20 rounded-lg flex items-center justify-center mb-4">
                        <svg aria-hidden="true" class="w-6 h-6 text-[#C12E26]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </div>
                    <h3 class="font-heading font-semibold text-gray-900 dark:text-gray-100 text-lg">Find Events</h3>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">Browse upcoming tournaments, leagues, and competitions in your area. Filter by type, date, and location.</p>
                </div>

                {{-- Feature: Easy Registration --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="w-12 h-12 bg-[#C12E26]/10 dark:bg-[#C12E26]/20 rounded-lg flex items-center justify-center mb-4">
                        <svg aria-hidden="true" class="w-6 h-6 text-[#C12E26]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <h3 class="font-heading font-semibold text-gray-900 dark:text-gray-100 text-lg">Easy Registration</h3>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">Register as an individual or with your team. Early bird discounts, division selection, and instant confirmation.</p>
                </div>

                {{-- Feature: Team Management --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="w-12 h-12 bg-[#C12E26]/10 dark:bg-[#C12E26]/20 rounded-lg flex items-center justify-center mb-4">
                        <svg aria-hidden="true" class="w-6 h-6 text-[#C12E26]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    </div>
                    <h3 class="font-heading font-semibold text-gray-900 dark:text-gray-100 text-lg">Team Management</h3>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">Create teams, invite members, manage rosters, and register for events together. Captain controls included.</p>
                </div>

                {{-- Feature: Organizer Tools --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="w-12 h-12 bg-[#C12E26]/10 dark:bg-[#C12E26]/20 rounded-lg flex items-center justify-center mb-4">
                        <svg aria-hidden="true" class="w-6 h-6 text-[#C12E26]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </div>
                    <h3 class="font-heading font-semibold text-gray-900 dark:text-gray-100 text-lg">Organizer Tools</h3>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">Create and manage events with divisions, fees, registration windows, announcements, and participant tracking.</p>
                </div>

                {{-- Feature: Secure Payments --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="w-12 h-12 bg-[#C12E26]/10 dark:bg-[#C12E26]/20 rounded-lg flex items-center justify-center mb-4">
                        <svg aria-hidden="true" class="w-6 h-6 text-[#C12E26]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    </div>
                    <h3 class="font-heading font-semibold text-gray-900 dark:text-gray-100 text-lg">Secure Payments</h3>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">Integrated payment processing with Paddle. Accept registration fees, manage refunds, and track payment status.</p>
                </div>

                {{-- Feature: Announcements --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="w-12 h-12 bg-[#C12E26]/10 dark:bg-[#C12E26]/20 rounded-lg flex items-center justify-center mb-4">
                        <svg aria-hidden="true" class="w-6 h-6 text-[#C12E26]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>
                    </div>
                    <h3 class="font-heading font-semibold text-gray-900 dark:text-gray-100 text-lg">Stay Informed</h3>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">Event announcements, schedule updates, and pinned notices keep participants in the loop before and during events.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- CTA Section --}}
    <section class="py-16 sm:py-20 bg-white dark:bg-gray-800">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 text-center">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold uppercase text-gray-900 dark:text-gray-100">
                Ready to Compete?
            </h2>
            <p class="mt-4 text-lg text-gray-600 dark:text-gray-400 max-w-2xl mx-auto">
                Join the Roundup Games community and start organizing or participating in events today.
            </p>
            <div class="mt-8 flex flex-wrap justify-center gap-4">
                <a href="{{ route('events.index') }}" wire:navigate class="inline-flex items-center px-6 py-3 bg-[#C12E26] text-white rounded-lg font-semibold hover:bg-[#9A231F] transition-colors text-sm">
                    Browse Events
                </a>
                @guest
                    <a href="{{ route('register') }}" wire:navigate class="inline-flex items-center px-6 py-3 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg font-semibold hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors text-sm">
                        Create Account
                    </a>
                @endguest
            </div>
        </div>
    </section>
</x-public-layout>

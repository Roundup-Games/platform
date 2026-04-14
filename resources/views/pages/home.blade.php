<x-public-layout>
@section('title', __('Home'))

    {{-- Hero Section — warm amber gradient with editorial typography --}}
    <section class="relative bg-gradient-to-br from-primary to-primary-container text-on-primary overflow-hidden">
        {{-- Decorative background shapes --}}
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 right-0 w-96 h-96 bg-on-primary rounded-full -translate-y-1/2 translate-x-1/3"></div>
            <div class="absolute bottom-0 left-0 w-72 h-72 bg-on-primary rounded-full translate-y-1/2 -translate-x-1/3"></div>
        </div>

        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 py-20 sm:py-28 lg:py-36">
            <div class="max-w-3xl">
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-heading font-bold tracking-tight leading-tight">
                    {{ __('Organize. Compete.') }}<br>
                    <span class="text-on-primary-fixed">{{ __('Connect.') }}</span>
                </h1>
                <p class="mt-6 text-lg sm:text-xl text-on-primary/80 max-w-xl">
                    {{ __('Roundup Games brings communities together through competitive events. Find tournaments, join teams, and compete in your favorite games.') }}
                </p>
                <div class="mt-8 flex flex-wrap gap-4">
                    <a href="{{ route('events.index') }}" wire:navigate class="inline-flex items-center px-6 py-3 bg-surface text-primary rounded-xl font-semibold hover:bg-surface-container-lowest transition-colors text-sm shadow-md">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">search</span>
                        {{ __('Browse Events') }}
                    </a>
                    @auth
                        <a href="{{ route('events.create') }}" wire:navigate class="inline-flex items-center px-6 py-3 bg-on-primary/20 text-on-primary rounded-xl font-semibold hover:bg-on-primary/30 transition-colors text-sm border border-on-primary/30">
                            <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">add</span>
                            {{ __('Create Event') }}
                        </a>
                    @else
                        <a href="{{ route('register') }}" wire:navigate class="inline-flex items-center px-6 py-3 bg-on-primary/20 text-on-primary rounded-xl font-semibold hover:bg-on-primary/30 transition-colors text-sm border border-on-primary/30">
                            {{ __('Get Started') }}
                        </a>
                    @endauth
                </div>
            </div>
        </div>
    </section>

    {{-- Stats Bar — warm inverse surface --}}
    <section class="bg-inverse-surface text-inverse-on-surface">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-6 text-center">
                <div>
                    <div class="text-2xl sm:text-3xl font-heading font-bold text-inverse-primary">{{ $upcomingEvents->count() + $featuredEvents->count() }}</div>
                    <div class="text-sm text-inverse-on-surface/70 mt-1">{{ __('Active Events') }}</div>
                </div>
                <div>
                    <div class="text-2xl sm:text-3xl font-heading font-bold text-inverse-primary">{{ $featuredEvents->count() }}</div>
                    <div class="text-sm text-inverse-on-surface/70 mt-1">{{ __('Featured') }}</div>
                </div>
                <div>
                    <div class="text-2xl sm:text-3xl font-heading font-bold text-inverse-primary">{{ $teamCount }}</div>
                    <div class="text-sm text-inverse-on-surface/70 mt-1">{{ __('Teams') }}</div>
                </div>
                <div>
                    <div class="text-2xl sm:text-3xl font-heading font-bold text-inverse-primary">{{ $registrationCount }}</div>
                    <div class="text-sm text-inverse-on-surface/70 mt-1">{{ __('Registrations') }}</div>
                </div>
            </div>
        </div>
    </section>

    {{-- Featured Events — tonal layering --}}
    @if($featuredEvents->isNotEmpty())
    <section class="py-16 sm:py-20 bg-surface-container-low">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface">
                    {{ __('Featured Events') }}
                </h2>
                <p class="mt-3 text-on-surface-variant max-w-xl mx-auto">
                    {{ __("Don't miss out on these highlighted competitions happening soon.") }}
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
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="flex items-center justify-between mb-10">
                <div>
                    <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface">
                        {{ __('Upcoming Events') }}
                    </h2>
                    <p class="mt-2 text-on-surface-variant">
                        {{ __("See what's coming up next.") }}
                    </p>
                </div>
                <a href="{{ route('events.index') }}" wire:navigate class="hidden sm:inline-flex items-center px-4 py-2 text-sm font-medium text-primary border border-primary rounded-lg hover:bg-primary hover:text-on-primary transition-colors">
                    {{ __('View All Events') }}
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
                <a href="{{ route('events.index') }}" wire:navigate class="inline-flex items-center px-6 py-3 text-sm font-medium text-primary border border-primary rounded-lg hover:bg-primary hover:text-on-primary transition-colors">
                    {{ __('View All Events') }}
                </a>
            </div>
        </div>
    </section>
    @endif

    {{-- Features Overview — editorial shadows, tonal layering, Material Symbols --}}
    <section class="py-16 sm:py-20 bg-surface-container-low">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface">
                    {{ __('Everything You Need') }}
                </h2>
                <p class="mt-3 text-on-surface-variant max-w-xl mx-auto">
                    {{ __('From event discovery to registration to competition management.') }}
                </p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">
                {{-- Feature: Event Discovery --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="w-12 h-12 bg-primary/10 rounded-lg flex items-center justify-center mb-4">
                        <span class="material-symbols-outlined text-primary" aria-hidden="true">search</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg">{{ __('Find Events') }}</h3>
                    <p class="mt-2 text-sm text-on-surface-variant">{{ __('Browse upcoming tournaments, leagues, and competitions in your area. Filter by type, date, and location.') }}</p>
                </div>

                {{-- Feature: Easy Registration --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="w-12 h-12 bg-primary/10 rounded-lg flex items-center justify-center mb-4">
                        <span class="material-symbols-outlined text-primary" aria-hidden="true">check_circle</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg">{{ __('Easy Registration') }}</h3>
                    <p class="mt-2 text-sm text-on-surface-variant">{{ __('Register as an individual or with your team. Early bird discounts, division selection, and instant confirmation.') }}</p>
                </div>

                {{-- Feature: Team Management --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="w-12 h-12 bg-primary/10 rounded-lg flex items-center justify-center mb-4">
                        <span class="material-symbols-outlined text-primary" aria-hidden="true">groups</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg">{{ __('Team Management') }}</h3>
                    <p class="mt-2 text-sm text-on-surface-variant">{{ __('Create teams, invite members, manage rosters, and register for events together. Captain controls included.') }}</p>
                </div>

                {{-- Feature: Organizer Tools --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="w-12 h-12 bg-primary/10 rounded-lg flex items-center justify-center mb-4">
                        <span class="material-symbols-outlined text-primary" aria-hidden="true">settings</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg">{{ __('Organizer Tools') }}</h3>
                    <p class="mt-2 text-sm text-on-surface-variant">{{ __('Create and manage events with divisions, fees, registration windows, announcements, and participant tracking.') }}</p>
                </div>

                {{-- Feature: Secure Payments --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="w-12 h-12 bg-primary/10 rounded-lg flex items-center justify-center mb-4">
                        <span class="material-symbols-outlined text-primary" aria-hidden="true">account_balance_wallet</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg">{{ __('Secure Payments') }}</h3>
                    <p class="mt-2 text-sm text-on-surface-variant">{{ __('Integrated payment processing with Paddle. Accept registration fees, manage refunds, and track payment status.') }}</p>
                </div>

                {{-- Feature: Announcements --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="w-12 h-12 bg-primary/10 rounded-lg flex items-center justify-center mb-4">
                        <span class="material-symbols-outlined text-primary" aria-hidden="true">campaign</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg">{{ __('Stay Informed') }}</h3>
                    <p class="mt-2 text-sm text-on-surface-variant">{{ __('Event announcements, schedule updates, and pinned notices keep participants in the loop before and during events.') }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- CTA Section --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 text-center">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface">
                {{ __('Ready to Compete?') }}
            </h2>
            <p class="mt-4 text-lg text-on-surface-variant max-w-2xl mx-auto">
                {{ __('Join the Roundup Games community and start organizing or participating in events today.') }}
            </p>
            <div class="mt-8 flex flex-wrap justify-center gap-4">
                <a href="{{ route('events.index') }}" wire:navigate class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-xl font-semibold hover:brightness-110 transition-all text-sm">
                    {{ __('Browse Events') }}
                </a>
                @guest
                    <a href="{{ route('register') }}" wire:navigate class="inline-flex items-center px-6 py-3 border border-outline text-on-surface-variant rounded-xl font-semibold hover:bg-surface-container-low transition-colors text-sm">
                        {{ __('Create Account') }}
                    </a>
                @endguest
            </div>
        </div>
    </section>
</x-public-layout>

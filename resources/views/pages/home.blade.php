<x-public-layout>
@section('title', __('Home'))

    {{-- ── Hero Section ────────────────────────────────────── --}}
    <section class="relative bg-gradient-to-br from-primary to-primary-container text-on-primary overflow-hidden">
        {{-- Decorative background shapes --}}
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 right-0 w-96 h-96 bg-on-primary rounded-full -translate-y-1/2 translate-x-1/3"></div>
            <div class="absolute bottom-0 left-0 w-72 h-72 bg-on-primary rounded-full translate-y-1/2 -translate-x-1/3"></div>
        </div>

        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 py-20 sm:py-28 lg:py-36">
            <div class="max-w-3xl">
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-heading font-bold tracking-tight leading-tight">
                    {{ __("There's a seat waiting for you.") }}
                </h1>
                <p class="mt-6 text-lg sm:text-xl text-on-primary/80 max-w-xl">
                    {{ __('Find your people. Discover new worlds. Share stories around the table that you\'ll be talking about for years.') }}
                </p>
                <div class="mt-8 flex flex-wrap gap-4">
                    <a href="#nearby-sessions"
                       class="inline-flex items-center px-6 py-3 bg-surface text-primary rounded-xl font-semibold hover:bg-surface-container-lowest transition-colors text-sm shadow-md">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">explore</span>
                        {{ __('Find sessions near me') }}
                    </a>
                    <a href="{{ route('games.index') }}" wire:navigate
                       class="inline-flex items-center px-6 py-3 bg-on-primary/20 text-on-primary rounded-xl font-semibold hover:bg-on-primary/30 transition-colors text-sm border border-on-primary/30">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">casino</span>
                        {{ __('Explore games') }}
                    </a>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Nearby Sessions (Location Gate) ─────────────────── --}}
    <section id="nearby-sessions-section" class="py-16 sm:py-20 bg-surface">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-10">
                <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface">
                    {{ __("What's happening near you?") }}
                </h2>
                <p class="mt-3 text-on-surface-variant max-w-xl mx-auto">
                    {{ __('Share your location to see game sessions and campaigns happening this week in your area.') }}
                </p>
            </div>
            <div id="nearby-sessions">
                @livewire('components.nearby-sessions', ['radius' => 10, 'limit' => 4])
            </div>
        </div>
    </section>

    {{-- ── Living Stats ────────────────────────────────────── --}}
    <section class="bg-inverse-surface text-inverse-on-surface">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-10">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-8 text-center">
                <div>
                    <div class="text-3xl sm:text-4xl font-heading font-bold text-inverse-primary">
                        {{ $sessionsThisWeek }}
                    </div>
                    <div class="text-sm text-inverse-on-surface/70 mt-1">
                        {{ __('Sessions this week') }}
                    </div>
                </div>
                <div>
                    <div class="text-3xl sm:text-4xl font-heading font-bold text-inverse-primary">
                        {{ $peopleThisWeek }}
                    </div>
                    <div class="text-sm text-inverse-on-surface/70 mt-1">
                        {{ __('People joined sessions this week') }}
                    </div>
                </div>
                <div>
                    <div class="text-3xl sm:text-4xl font-heading font-bold text-inverse-primary">
                        {{ $activeCampaigns }}
                    </div>
                    <div class="text-sm text-inverse-on-surface/70 mt-1">
                        {{ __('Active campaigns') }}
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Values Strip ────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface-container-low">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface">
                    {{ __('Built for real connection') }}
                </h2>
                <p class="mt-3 text-on-surface-variant max-w-xl mx-auto">
                    {{ __('Tabletop gaming is about more than rules. It\'s about the people you share the table with.') }}
                </p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">
                {{-- Welcoming Community --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient text-center">
                    <div class="w-14 h-14 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">diversity_3</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('Welcoming Community') }}</h3>
                    <p class="text-sm text-on-surface-variant">{{ __('Every table has room for one more. Find players and hosts who make you feel at home.') }}</p>
                </div>

                {{-- Imaginative Play --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient text-center">
                    <div class="w-14 h-14 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">auto_awesome</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('Imaginative Play') }}</h3>
                    <p class="text-sm text-on-surface-variant">{{ __('From epic campaigns to quick one-shots, discover stories waiting to be told and worlds waiting to be explored.') }}</p>
                </div>

                {{-- Safe Spaces --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient text-center">
                    <div class="w-14 h-14 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">shield_person</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('Safe Spaces') }}</h3>
                    <p class="text-sm text-on-surface-variant">{{ __('Clear safety tools, session zero support, and community guidelines keep the focus on fun for everyone.') }}</p>
                </div>

                {{-- Discovery --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient text-center">
                    <div class="w-14 h-14 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">explore</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('Discovery') }}</h3>
                    <p class="text-sm text-on-surface-variant">{{ __('Step outside your comfort zone. Try a new system, join a different group, or fall in love with a game you\'d never heard of.') }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- ── CTA Section ─────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 text-center">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface">
                {{ __('Your next adventure starts here') }}
            </h2>
            <p class="mt-4 text-lg text-on-surface-variant max-w-2xl mx-auto">
                {{ __('Join a community of players, hosts, and storytellers. Find a session, bring a friend, or start your own.') }}
            </p>
            <div class="mt-8 flex flex-wrap justify-center gap-4">
                @guest
                    <a href="{{ route('register') }}" wire:navigate
                       class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-xl font-semibold hover:brightness-110 transition-all text-sm shadow-md">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">person_add</span>
                        {{ __('Create Free Account') }}
                    </a>
                @else
                    <a href="{{ route('games.index') }}" wire:navigate
                       class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-xl font-semibold hover:brightness-110 transition-all text-sm shadow-md">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">search</span>
                        {{ __('Browse Sessions') }}
                    </a>
                @endguest
                @guest
                    <a href="{{ route('login') }}" wire:navigate
                       class="inline-flex items-center px-6 py-3 border border-outline text-on-surface-variant rounded-xl font-semibold hover:bg-surface-container-low transition-colors text-sm">
                        {{ __('Sign In') }}
                    </a>
                @endguest
            </div>
        </div>
    </section>
</x-public-layout>

<x-public-layout>
@section('title', __('For Organizers'))

    {{-- ── Hero ─────────────────────────────────────────────── --}}
    <section class="relative bg-gradient-to-br from-primary to-primary-container text-on-primary overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 right-0 w-72 h-72 bg-on-primary rounded-full -translate-y-1/2 translate-x-1/3"></div>
            <div class="absolute bottom-0 left-0 w-56 h-56 bg-on-primary rounded-full translate-y-1/2 -translate-x-1/3"></div>
        </div>
        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 py-20 sm:py-28 lg:py-32 text-center">
            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-heading font-bold tracking-tight leading-tight">
                {{ __('Bring your games to the table') }}
            </h1>
            <p class="mt-6 text-lg sm:text-xl text-on-primary/80 max-w-2xl mx-auto">
                {{ __('You\'ve got the game. We\'ll help you find the right players — and handle the logistics so you can focus on what you do best: hosting unforgettable sessions.') }}
            </p>
            <div class="mt-8 flex flex-wrap justify-center gap-4">
                @guest
                    <a href="{{ route('register') }}" wire:navigate
                       class="inline-flex items-center px-6 py-3 bg-surface text-primary rounded-xl font-semibold hover:bg-surface-container-lowest transition-colors text-sm shadow-md">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">person_add</span>
                        {{ __('Start Hosting — It\'s Free') }}
                    </a>
                @else
                    <a href="{{ route('games.create') }}" wire:navigate
                       class="inline-flex items-center px-6 py-3 bg-surface text-primary rounded-xl font-semibold hover:bg-surface-container-lowest transition-colors text-sm shadow-md">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">add_circle</span>
                        {{ __('Create Your First Session') }}
                    </a>
                @endguest
            </div>
        </div>
    </section>

    {{-- ── Benefit Cards ────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface text-center mb-4">
                {{ __('Everything you need to run great sessions') }}
            </h2>
            <p class="text-on-surface-variant text-center max-w-2xl mx-auto mb-12">
                {{ __('No more group chats, spreadsheets, or "who\'s bringing snacks?" threads. One platform, all your sessions.') }}
            </p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-8 max-w-4xl mx-auto">
                {{-- One link for signups --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">link</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('One link for signups') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('Share a single link for each session. Players sign up with one click — no app install, no account required to browse. You see who\'s coming, they get automatic reminders.') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Automatic player matching --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">group</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('Automatic player matching') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('Your sessions appear in discovery feeds for players near you who love the games you run. Location-based matching means the right people find your table without you lifting a finger.') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Campaign management --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">map</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('Campaign management') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('Running a multi-session campaign? Group your sessions, track attendance, and keep your party connected between games. Your players always know when the next session is.') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Visibility controls --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">tune</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('Visibility controls') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('Make sessions public for anyone to find, protected for your trusted community, or private for your existing group. You decide who gets a seat at your table.') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── How It Works: 3 Steps ────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface-container-low">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface text-center mb-16">
                {{ __('From idea to game night in three steps') }}
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-12 md:gap-8">
                {{-- Step 1: Create --}}
                <div class="text-center">
                    <div class="w-20 h-20 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-6 relative">
                        <span class="material-symbols-outlined text-primary text-4xl" aria-hidden="true">add_circle</span>
                        <span class="absolute -top-1 -right-1 w-7 h-7 bg-primary text-on-primary text-xs font-bold rounded-full flex items-center justify-center">1</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-xl mb-3">{{ __('Create a session') }}</h3>
                    <p class="text-on-surface-variant leading-relaxed max-w-xs mx-auto">
                        {{ __('Pick your game, set the date and location, choose how many players you want. It takes about thirty seconds — faster than shuffling a deck.') }}
                    </p>
                </div>

                {{-- Step 2: Preferences --}}
                <div class="text-center">
                    <div class="w-20 h-20 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-6 relative">
                        <span class="material-symbols-outlined text-primary text-4xl" aria-hidden="true">tune</span>
                        <span class="absolute -top-1 -right-1 w-7 h-7 bg-primary text-on-primary text-xs font-bold rounded-full flex items-center justify-center">2</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-xl mb-3">{{ __('Set your preferences') }}</h3>
                    <p class="text-on-surface-variant leading-relaxed max-w-xs mx-auto">
                        {{ __('Choose your visibility level, add a description, set house rules, or keep it simple. Your call. Every session is as unique as the game you\'re running.') }}
                    </p>
                </div>

                {{-- Step 3: Players Find You --}}
                <div class="text-center">
                    <div class="w-20 h-20 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-6 relative">
                        <span class="material-symbols-outlined text-primary text-4xl" aria-hidden="true">group_add</span>
                        <span class="absolute -top-1 -right-1 w-7 h-7 bg-primary text-on-primary text-xs font-bold rounded-full flex items-center justify-center">3</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-xl mb-3">{{ __('Players find you') }}</h3>
                    <p class="text-on-surface-variant leading-relaxed max-w-xs mx-auto">
                        {{ __('Your session appears in discovery for nearby players who match your game. They sign up, you approve (or let them in automatically), and everyone shows up ready to play.') }}
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Social Proof ─────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-inverse-surface text-inverse-on-surface">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 text-center">
            <div class="max-w-3xl mx-auto">
                <span class="material-symbols-outlined text-inverse-primary text-5xl mb-4 block" aria-hidden="true">groups</span>
                <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight">
                    {{ __('Join :count organizers who bring people together', ['count' => $displayCount]) }}
                </h2>
                <p class="mt-4 text-inverse-on-surface/70 max-w-xl mx-auto text-lg">
                    {{ __('From weekly board game nights to epic multi-year RPG campaigns, organizers on Roundup are building the gaming communities they always wished existed. Your table is next.') }}
                </p>
            </div>
        </div>
    </section>

    {{-- ── CTA Section ──────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-gradient-to-br from-primary to-primary-container text-on-primary">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 text-center">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight">
                {{ __('Start your first session — it\'s free') }}
            </h2>
            <p class="mt-4 text-on-primary/80 max-w-xl mx-auto">
                {{ __('No subscriptions required. No credit card needed. Just create an account, set up your session, and let the players come to you.') }}
            </p>
            <div class="mt-8 flex flex-wrap justify-center gap-4">
                @guest
                    <a href="{{ route('register') }}" wire:navigate
                       class="inline-flex items-center px-6 py-3 bg-surface text-primary rounded-xl font-semibold hover:bg-surface-container-lowest transition-colors text-sm shadow-md">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">person_add</span>
                        {{ __('Sign Up Free') }}
                    </a>
                    <a href="{{ route('discover') }}" wire:navigate
                       class="inline-flex items-center px-6 py-3 bg-on-primary/20 text-on-primary rounded-xl font-semibold hover:bg-on-primary/30 transition-colors text-sm border border-on-primary/30">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">explore</span>
                        {{ __('See How It Works') }}
                    </a>
                @else
                    <a href="{{ route('games.create') }}" wire:navigate
                       class="inline-flex items-center px-6 py-3 bg-surface text-primary rounded-xl font-semibold hover:bg-surface-container-lowest transition-colors text-sm shadow-md">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">add_circle</span>
                        {{ __('Create Your First Session') }}
                    </a>
                    <a href="{{ route('discover') }}" wire:navigate
                       class="inline-flex items-center px-6 py-3 bg-on-primary/20 text-on-primary rounded-xl font-semibold hover:bg-on-primary/30 transition-colors text-sm border border-on-primary/30">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">explore</span>
                        {{ __('Browse Sessions') }}
                    </a>
                @endguest
            </div>
        </div>
    </section>
</x-public-layout>

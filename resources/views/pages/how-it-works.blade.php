<x-public-layout>
@section('title', __('How It Works'))

    {{-- ── Hero ─────────────────────────────────────────────── --}}
    <section class="relative bg-gradient-to-br from-primary to-primary-container text-on-primary overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 right-0 w-72 h-72 bg-on-primary rounded-full -translate-y-1/2 translate-x-1/3"></div>
            <div class="absolute bottom-0 left-0 w-56 h-56 bg-on-primary rounded-full translate-y-1/2 -translate-x-1/3"></div>
        </div>
        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 py-20 sm:py-28 lg:py-32 text-center">
            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-heading font-bold tracking-tight leading-tight">
                {{ __('How Roundup Works') }}
            </h1>
            <p class="mt-6 text-lg sm:text-xl text-on-primary/80 max-w-2xl mx-auto">
                {{ __('We believe everyone deserves a seat at the table. Here\'s how we make that happen — no experience required.') }}
            </p>
        </div>
    </section>

    {{-- ── 3-Step Visual Section ────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface text-center mb-16">
                {{ __('Three steps to your next game night') }}
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-12 md:gap-8">
                {{-- Step 1: Discover --}}
                <div class="text-center">
                    <div class="w-20 h-20 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-6 relative">
                        <span class="material-symbols-outlined text-primary text-4xl" aria-hidden="true">explore</span>
                        <span class="absolute -top-1 -right-1 w-7 h-7 bg-primary text-on-primary text-xs font-bold rounded-full flex items-center justify-center">1</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-xl mb-3">{{ __('Discover sessions near you') }}</h3>
                    <p class="text-on-surface-variant leading-relaxed max-w-xs mx-auto">
                        {{ __('Share your location and browse game sessions, campaigns, and events happening in your area. Filter by game type, date, or skill level — whatever fits your life.') }}
                    </p>
                </div>

                {{-- Step 2: Find --}}
                <div class="text-center">
                    <div class="w-20 h-20 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-6 relative">
                        <span class="material-symbols-outlined text-primary text-4xl" aria-hidden="true">casino</span>
                        <span class="absolute -top-1 -right-1 w-7 h-7 bg-primary text-on-primary text-xs font-bold rounded-full flex items-center justify-center">2</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-xl mb-3">{{ __('Find your kind of game') }}</h3>
                    <p class="text-on-surface-variant leading-relaxed max-w-xs mx-auto">
                        {{ __('Love epic RPG campaigns? Quick board game nights? Competitive card games? Set your preferences and we\'ll surface the games you\'ll actually enjoy playing.') }}
                    </p>
                </div>

                {{-- Step 3: Show Up --}}
                <div class="text-center">
                    <div class="w-20 h-20 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-6 relative">
                        <span class="material-symbols-outlined text-primary text-4xl" aria-hidden="true">groups</span>
                        <span class="absolute -top-1 -right-1 w-7 h-7 bg-primary text-on-primary text-xs font-bold rounded-full flex items-center justify-center">3</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-xl mb-3">{{ __('Show up and play') }}</h3>
                    <p class="text-on-surface-variant leading-relaxed max-w-xs mx-auto">
                        {{ __('Sign up for a session, show up, and have a great time. That\'s it. No dues, no commitments, no pressure. Just roll the dice and see what happens.') }}
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Values Section ───────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface-container-low">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface text-center mb-4">
                {{ __('What we stand for') }}
            </h2>
            <p class="text-on-surface-variant text-center max-w-2xl mx-auto mb-12">
                {{ __('These aren\'t just words on a page. They shape every feature we build and every decision we make.') }}
            </p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-8 max-w-4xl mx-auto">
                {{-- Inclusivity --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">diversity_3</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('Inclusivity') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('Every table has room for one more. We build for shy newcomers and seasoned veterans alike. Whether you\'ve played a hundred campaigns or don\'t know what a d20 is, you belong here.') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Safety --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">shield_person</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('Safety') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('Trust is earned through transparency. Every session is visible to the community. Organizer profiles are public. Protected sessions require membership. We give you the tools to play safe.') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Community --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">favorite</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('Community') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('The best games happen when people care about each other, not just the scoreboard. We prioritize connection over competition, conversation over calculation, and shared stories over individual wins.') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Curiosity --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">auto_awesome</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('Curiosity') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('The best adventures start with "what if?" We celebrate trying new games, exploring unfamiliar mechanics, and stepping outside your comfort zone. That\'s where the magic happens.') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Safety & Vetting Section ─────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="max-w-3xl mx-auto">
                <div class="text-center mb-10">
                    <span class="material-symbols-outlined text-primary text-4xl" aria-hidden="true">verified_user</span>
                    <h2 class="mt-4 text-3xl font-heading font-bold tracking-tight text-on-surface">
                        {{ __('Your safety, built in') }}
                    </h2>
                    <p class="mt-3 text-on-surface-variant max-w-xl mx-auto">
                        {{ __('We take trust seriously. Here\'s how we keep the community safe and transparent.') }}
                    </p>
                </div>
                <div class="space-y-6">
                    <div class="flex items-start gap-4">
                        <span class="material-symbols-outlined text-primary text-xl mt-1 flex-shrink-0" aria-hidden="true">visibility</span>
                        <div>
                            <h4 class="font-heading font-semibold text-on-surface">{{ __('Transparent sessions') }}</h4>
                            <p class="text-sm text-on-surface-variant mt-1">{{ __('Every public session is visible to the community. You can see who\'s hosting, who\'s attending, and what\'s being played before you commit.') }}</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-4">
                        <span class="material-symbols-outlined text-primary text-xl mt-1 flex-shrink-0" aria-hidden="true">person</span>
                        <div>
                            <h4 class="font-heading font-semibold text-on-surface">{{ __('Organizer profiles') }}</h4>
                            <p class="text-sm text-on-surface-variant mt-1">{{ __('Organizer profiles are public. You can read about your host, see their session history, and check community feedback before joining.') }}</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-4">
                        <span class="material-symbols-outlined text-primary text-xl mt-1 flex-shrink-0" aria-hidden="true">lock</span>
                        <div>
                            <h4 class="font-heading font-semibold text-on-surface">{{ __('Protected sessions') }}</h4>
                            <p class="text-sm text-on-surface-variant mt-1">{{ __('Protected sessions require membership approval. Organizers choose their community, and members earn trust through participation.') }}</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-4">
                        <span class="material-symbols-outlined text-primary text-xl mt-1 flex-shrink-0" aria-hidden="true">forum</span>
                        <div>
                            <h4 class="font-heading font-semibold text-on-surface">{{ __('Session zero support') }}</h4>
                            <p class="text-sm text-on-surface-variant mt-1">{{ __('For RPG campaigns, we encourage session zero — a pre-game conversation about expectations, boundaries, and play style. It sets the tone for a great experience.') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── CTA Section ──────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-gradient-to-br from-primary to-primary-container text-on-primary">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 text-center">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight">
                {{ __('Ready to find your table?') }}
            </h2>
            <p class="mt-4 text-on-primary/80 max-w-xl mx-auto">
                {{ __('Create a free account, set your preferences, and discover sessions happening near you this week.') }}
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
                        {{ __('Browse Sessions') }}
                    </a>
                @else
                    <a href="{{ route('discover') }}" wire:navigate
                       class="inline-flex items-center px-6 py-3 bg-surface text-primary rounded-xl font-semibold hover:bg-surface-container-lowest transition-colors text-sm shadow-md">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">explore</span>
                        {{ __('Browse Sessions') }}
                    </a>
                @endguest
            </div>
        </div>
    </section>
</x-public-layout>

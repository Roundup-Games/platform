<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', __('games.action_explore_game_systems')) — {{ config('app.name', 'Roundup Games') }}</title>

    {{-- Dark mode: apply class before paint to prevent flash --}}
    <script>
        (function() {
            var t = localStorage.getItem('theme');
            var dark = t === 'dark' || ((t === 'system' || t === null) && window.matchMedia('(prefers-color-scheme: dark)').matches);
            if (dark) document.documentElement.classList.add('dark');
        })();
    </script>

    <!-- Fonts: Noto Serif for headings, Inter for body -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Noto+Serif:ital,wght@0,400;0,600;0,700;0,800;1,400;1,700&display=swap" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- SEO hreflang alternate tags --}}
    @include('partials.hreflang')
</head>
<body class="font-sans text-on-surface antialiased bg-surface">
    {{-- Skip to content link --}}
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-[100] focus:px-4 focus:py-2 focus:bg-primary focus:text-on-primary focus:rounded-lg focus:text-sm focus:font-semibold">{{ __('common.content_skip_to_content') }}</a>

    <div class="min-h-screen flex flex-col bg-surface">

        {{-- Public Navigation --}}
        <header class="sticky top-0 z-50 bg-surface/90 backdrop-blur-md">
            <nav class="flex justify-between items-center w-full px-6 sm:px-8 py-4 max-w-screen-2xl mx-auto" aria-label="Main navigation">

                {{-- Logo --}}
                <a href="{{ route('home') }}" wire:navigate class="text-2xl font-heading font-bold text-primary tracking-tight">
                    Roundup Games
                </a>

                {{-- Desktop Nav Links --}}
                @php
                    $howItWorksRouteExists = \Illuminate\Support\Facades\Route::has('how-it-works');
                    $gameSystemsRouteExists = \Illuminate\Support\Facades\Route::has('game-systems');
                    $pubOtherLocale = app()->getLocale() === 'en' ? 'de' : 'en';
                    $pubCurrentPath = '/' . request()->path();
                @endphp
                <div class="hidden md:flex items-center gap-8">
                    <a href="{{ route('discover') }}" wire:navigate class="font-heading text-sm tracking-tight {{ request()->routeIs('discover') ? 'text-primary font-bold border-b-2 border-primary-container pb-1' : 'text-on-surface-variant font-medium hover:text-primary transition-colors duration-200' }}">{{ __('discovery.action_discover') }}</a>
                    @auth
                        <a href="{{ route('games.index') }}" wire:navigate class="font-heading text-sm tracking-tight {{ request()->routeIs('games.*') ? 'text-primary font-bold border-b-2 border-primary-container pb-1' : 'text-on-surface-variant font-medium hover:text-primary transition-colors duration-200' }}">{{ __('games.content_games') }}</a>
                        <a href="{{ route('campaigns.index') }}" wire:navigate class="font-heading text-sm tracking-tight {{ request()->routeIs('campaigns.*') ? 'text-primary font-bold border-b-2 border-primary-container pb-1' : 'text-on-surface-variant font-medium hover:text-primary transition-colors duration-200' }}">{{ __('campaigns.content_campaigns') }}</a>
                    @endauth
                    <a href="{{ $howItWorksRouteExists ? route('how-it-works') : url(app()->getLocale() . '/how-it-works') }}" wire:navigate class="font-heading text-sm tracking-tight {{ request()->routeIs('how-it-works') || request()->is('*how-it-works') ? 'text-primary font-bold border-b-2 border-primary-container pb-1' : 'text-on-surface-variant font-medium hover:text-primary transition-colors duration-200' }}">{{ __('pages.content_how_it_works') }}</a>
                    <a href="{{ $gameSystemsRouteExists ? route('game-systems') : url(app()->getLocale() . '/game-systems') }}" wire:navigate class="font-heading text-sm tracking-tight {{ request()->routeIs('game-systems*') ? 'text-primary font-bold border-b-2 border-primary-container pb-1' : 'text-on-surface-variant font-medium hover:text-primary transition-colors duration-200' }}">{{ __('games.content_game_systems') }}</a>
                    <a href="{{ route('locale.switch', ['locale' => $pubOtherLocale, 'redirect' => $pubCurrentPath]) }}" class="font-heading text-sm tracking-tight text-on-surface-variant font-medium hover:text-primary transition-colors duration-200 uppercase">{{ strtoupper($pubOtherLocale) }}</a>
                </div>

                {{-- Desktop CTA Buttons --}}
                <div class="hidden md:flex items-center gap-4">
                    @auth
                        <a href="{{ route('dashboard') }}" wire:navigate class="px-5 py-2 text-primary font-medium hover:text-primary transition-colors text-sm">
                            {{ __('profile.content_dashboard') }}
                        </a>
                    @else
                        <a href="{{ route('login') }}" wire:navigate class="px-5 py-2 text-primary font-medium hover:text-primary transition-colors text-sm">
                            {{ __('auth.content_log_in') }}
                        </a>
                    @endauth
                    @auth
                        <form method="POST" action="{{ route('logout') }}" class="inline">
                            @csrf
                            <button type="submit" class="px-5 py-2.5 bg-primary text-on-primary font-semibold rounded-xl shadow-md active:scale-95 duration-150 text-sm">
                                {{ __('auth.content_log_out') }}
                            </button>
                        </form>
                    @else
                        <a href="{{ route('register') }}" wire:navigate class="px-6 py-2.5 bg-primary text-on-primary font-semibold rounded-xl shadow-md active:scale-95 duration-150 text-sm">
                            {{ __('auth.content_sign_up') }}
                        </a>
                    @endauth
                </div>

                {{-- Mobile menu button --}}
                <div class="md:hidden" x-data="{ open: false }">
                    <button @click="open = !open" class="p-2 text-on-surface-variant hover:text-primary transition-colors" aria-label="Toggle navigation menu" :aria-expanded="open.toString()">
                        <span class="material-symbols-outlined text-2xl" :class="{'hidden': open, 'block': !open}">menu</span>
                        <span class="material-symbols-outlined text-2xl" :class="{'block': open, 'hidden': !open}">close</span>
                    </button>

                    {{-- Mobile Nav Dropdown --}}
                    <div x-show="open"
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 -translate-y-1"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-150"
                         x-transition:leave-start="opacity-100 translate-y-0"
                         x-transition:leave-end="opacity-0 -translate-y-1"
                         @click.away="open = false"
                         class="absolute left-0 right-0 bg-surface/95 backdrop-blur-md border-b border-outline-variant/15 z-50">
                        <div class="px-6 py-4 space-y-1 max-w-screen-2xl mx-auto">
                            @php
                                $mobHowItWorksRouteExists = \Illuminate\Support\Facades\Route::has('how-it-works');
                                $mobGameSystemsRouteExists = \Illuminate\Support\Facades\Route::has('game-systems');
                                $mobPubOtherLocale = app()->getLocale() === 'en' ? 'de' : 'en';
                                $mobPubCurrentPath = '/' . request()->path();
                            @endphp
                            <a href="{{ route('home') }}" wire:navigate class="block px-3 py-2.5 rounded-lg text-sm font-heading tracking-tight {{ request()->routeIs('home') ? 'text-primary font-bold bg-primary/5' : 'text-on-surface-variant font-medium hover:text-primary hover:bg-primary/5' }}">{{ __('common.content_home') }}</a>
                            <a href="{{ route('discover') }}" wire:navigate class="block px-3 py-2.5 rounded-lg text-sm font-heading tracking-tight {{ request()->routeIs('discover') ? 'text-primary font-bold bg-primary/5' : 'text-on-surface-variant font-medium hover:text-primary hover:bg-primary/5' }}">{{ __('discovery.action_discover') }}</a>
                            @auth
                                <a href="{{ route('games.index') }}" wire:navigate class="block px-3 py-2.5 rounded-lg text-sm font-heading tracking-tight {{ request()->routeIs('games.*') ? 'text-primary font-bold bg-primary/5' : 'text-on-surface-variant font-medium hover:text-primary hover:bg-primary/5' }}">{{ __('games.content_games') }}</a>
                                <a href="{{ route('campaigns.index') }}" wire:navigate class="block px-3 py-2.5 rounded-lg text-sm font-heading tracking-tight {{ request()->routeIs('campaigns.*') ? 'text-primary font-bold bg-primary/5' : 'text-on-surface-variant font-medium hover:text-primary hover:bg-primary/5' }}">{{ __('campaigns.content_campaigns') }}</a>
                            @endauth
                            <a href="{{ $mobHowItWorksRouteExists ? route('how-it-works') : url(app()->getLocale() . '/how-it-works') }}" wire:navigate class="block px-3 py-2.5 rounded-lg text-sm font-heading tracking-tight {{ request()->routeIs('how-it-works') || request()->is('*how-it-works') ? 'text-primary font-bold bg-primary/5' : 'text-on-surface-variant font-medium hover:text-primary hover:bg-primary/5' }}">{{ __('pages.content_how_it_works') }}</a>
                            <a href="{{ $mobGameSystemsRouteExists ? route('game-systems') : url(app()->getLocale() . '/game-systems') }}" wire:navigate class="block px-3 py-2.5 rounded-lg text-sm font-heading tracking-tight {{ request()->routeIs('game-systems*') ? 'text-primary font-bold bg-primary/5' : 'text-on-surface-variant font-medium hover:text-primary hover:bg-primary/5' }}">{{ __('games.content_game_systems') }}</a>
                            <a href="{{ route('about') }}" wire:navigate class="block px-3 py-2.5 rounded-lg text-sm font-heading tracking-tight {{ request()->routeIs('about') ? 'text-primary font-bold bg-primary/5' : 'text-on-surface-variant font-medium hover:text-primary hover:bg-primary/5' }}">{{ __('pages.content_about') }}</a>
                            <a href="{{ route('contact') }}" wire:navigate class="block px-3 py-2.5 rounded-lg text-sm font-heading tracking-tight {{ request()->routeIs('contact') ? 'text-primary font-bold bg-primary/5' : 'text-on-surface-variant font-medium hover:text-primary hover:bg-primary/5' }}">{{ __('pages.content_contact') }}</a>
                            <a href="{{ route('locale.switch', ['locale' => $mobPubOtherLocale, 'redirect' => $mobPubCurrentPath]) }}" class="block px-3 py-2.5 rounded-lg text-sm font-heading tracking-tight text-on-surface-variant font-medium hover:text-primary hover:bg-primary/5 uppercase">{{ strtoupper($mobPubOtherLocale) }}</a>

                            <div class="pt-3 mt-2 border-t border-outline-variant/15 space-y-1">
                                @auth
                                    <a href="{{ route('dashboard') }}" wire:navigate class="block px-3 py-2.5 rounded-lg text-sm font-heading tracking-tight text-on-surface-variant font-medium hover:text-primary hover:bg-primary/5">{{ __('profile.content_dashboard') }}</a>
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <button type="submit" class="block w-full text-left px-3 py-2.5 rounded-lg text-sm font-heading tracking-tight text-on-surface-variant font-medium hover:text-primary hover:bg-primary/5">{{ __('auth.content_log_out') }}</button>
                                    </form>
                                @else
                                    <a href="{{ route('login') }}" wire:navigate class="block px-3 py-2.5 rounded-lg text-sm font-heading tracking-tight text-on-surface-variant font-medium hover:text-primary hover:bg-primary/5">{{ __('auth.content_log_in') }}</a>
                                    <a href="{{ route('register') }}" wire:navigate class="block px-3 py-2.5 rounded-lg text-sm font-heading tracking-tight font-bold bg-primary text-on-primary mt-2">{{ __('auth.content_sign_up') }}</a>
                                @endauth
                            </div>
                        </div>
                    </div>
                </div>
            </nav>
        </header>

        {{-- Main Content --}}
        <main id="main-content" class="flex-1">
            {{ $slot }}
        </main>

        {{-- Footer --}}
        <footer class="bg-surface-container-low w-full mt-auto">
            <div class="flex flex-col md:flex-row justify-between items-center px-8 sm:px-12 py-12 w-full max-w-screen-2xl mx-auto border-t border-outline-variant/10">
                {{-- Logo & tagline --}}
                <div class="space-y-4 mb-8 md:mb-0">
                    <div class="font-heading font-semibold text-primary text-xl tracking-tight">Roundup Games</div>
                    <p class="text-sm text-on-surface-variant max-w-xs">
                        &copy; {{ date('Y') }} Roundup Games. {{ __('common.content_the_digital_parlor_for_tabletop_enthusiasts') }}
                    </p>
                    <div class="flex gap-4 items-center">
                        <span class="material-symbols-outlined text-on-surface-variant hover:text-primary cursor-pointer transition-colors">public</span>
                        <span class="material-symbols-outlined text-on-surface-variant hover:text-primary cursor-pointer transition-colors">forum</span>
                        <span class="material-symbols-outlined text-on-surface-variant hover:text-primary cursor-pointer transition-colors">contact_support</span>
                        <span class="border-l border-outline-variant/30 h-5"></span>
                        <x-theme-toggle size="small" />
                    </div>
                </div>

                {{-- Link columns --}}
                <div class="grid grid-cols-2 md:grid-cols-3 gap-x-12 gap-y-4">
                    @php
                        $gameSystemsRouteExists = \Illuminate\Support\Facades\Route::has('game-systems');
                        $footerHowItWorksRouteExists = \Illuminate\Support\Facades\Route::has('how-it-works');
                        $forOrganizersRouteExists = \Illuminate\Support\Facades\Route::has('for-organizers');
                    @endphp
                    <div class="flex flex-col gap-2">
                        <span class="text-xs font-bold text-primary uppercase tracking-wide mb-2">{{ __('common.content_platform') }}</span>
                        <a href="{{ route('discover') }}" wire:navigate class="text-on-surface-variant hover:text-primary text-sm transition-colors">{{ __('discovery.action_discover') }}</a>
                        @auth
                            <a href="{{ route('games.index') }}" wire:navigate class="text-on-surface-variant hover:text-primary text-sm transition-colors">{{ __('games.content_games') }}</a>
                            <a href="{{ route('campaigns.index') }}" wire:navigate class="text-on-surface-variant hover:text-primary text-sm transition-colors">{{ __('campaigns.content_campaigns') }}</a>
                        @endauth
                        <a href="{{ route('events.index') }}" wire:navigate class="text-on-surface-variant hover:text-primary text-sm transition-colors">{{ __('events.content_events') }}</a>
                        <a href="{{ $gameSystemsRouteExists ? route('game-systems') : url(app()->getLocale() . '/game-systems') }}" wire:navigate class="text-on-surface-variant hover:text-primary text-sm transition-colors">{{ __('games.content_game_systems') }}</a>
                    </div>
                    <div class="flex flex-col gap-2">
                        <span class="text-xs font-bold text-primary uppercase tracking-wide mb-2">{{ __('common.content_support') }}</span>
                        <a href="{{ $footerHowItWorksRouteExists ? route('how-it-works') : url(app()->getLocale() . '/how-it-works') }}" class="text-on-surface-variant hover:text-primary text-sm transition-colors">{{ __('pages.content_how_it_works') }}</a>
                        <a href="{{ route('safety-tools') }}" wire:navigate class="text-on-surface-variant hover:text-primary text-sm transition-colors">{{ __('safety.content_safety_tools') }}</a>
                        <a href="{{ $forOrganizersRouteExists ? route('for-organizers') : url(app()->getLocale() . '/for-organizers') }}" class="text-on-surface-variant hover:text-primary text-sm transition-colors">{{ __('common.content_for_organizers') }}</a>
                        <a href="{{ route('contact') }}" wire:navigate class="text-on-surface-variant hover:text-primary text-sm transition-colors">{{ __('pages.content_contact') }}</a>
                    </div>
                    <div class="flex flex-col gap-2 col-span-2 md:col-span-1">
                        <span class="text-xs font-bold text-primary uppercase tracking-wide mb-2">{{ __('profile.content_account') }}</span>
                        @auth
                            <a href="{{ route('dashboard') }}" wire:navigate class="text-on-surface-variant hover:text-primary text-sm transition-colors">{{ __('profile.content_dashboard') }}</a>
                            <form method="POST" action="{{ route('logout') }}" class="inline">
                                @csrf
                                <button type="submit" class="text-on-surface-variant hover:text-primary text-sm transition-colors">{{ __('auth.content_log_out') }}</button>
                            </form>
                        @else
                            <a href="{{ route('login') }}" wire:navigate class="text-on-surface-variant hover:text-primary text-sm transition-colors">{{ __('auth.content_log_in') }}</a>
                            <a href="{{ route('register') }}" wire:navigate class="text-on-surface-variant hover:text-primary text-sm transition-colors">{{ __('auth.content_sign_up') }}</a>
                        @endauth
                    </div>
                </div>
            </div>

            <div class="w-full h-1 bg-primary"></div>
        </footer>
    </div>
</body>
</html>
ml>

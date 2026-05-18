<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('partials.posthog-meta')

    {!! seo() !!}

    {{-- Favicons --}}
    <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/icons/favicon-16x16.png">

    {{-- Dark mode: apply class before paint to prevent flash --}}
    <script>
        (function() {
            var t = localStorage.getItem('theme');
            var dark = t === 'dark' || ((t === 'system' || t === null) && window.matchMedia('(prefers-color-scheme: dark)').matches);
            if (dark) document.documentElement.classList.add('dark');
        })();
    </script>

    {{-- Fonts: self-hosted Inter (body) + Noto Serif (headings) + Material Symbols (icons) via @font-face in app.css --}}
    {{-- Material Symbols is subset to project icons — rebuild with build-tools/subset-icons.sh --}}
    <link rel="preload" href="/fonts/inter-latin-var.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/noto-serif-latin-var.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/material-symbols-subset.woff2" as="font" type="font/woff2" crossorigin>

    {{-- DNS prefetch for external image CDN --}}
    <link rel="dns-prefetch" href="https://spg-images.s3.us-west-1.amazonaws.com">

    {{-- PWA --}}
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#835500">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">

    {{-- PWA update toast translations (read by app.js) --}}
    <script>window.__pwaUpdateToast={message:'{{ addslashes(__('pwa.content_update_available')) }}',action:'{{ addslashes(__('pwa.action_update')) }}'};window.__pwaOfflineToast={queued:'{{ addslashes(__('pwa.offline_action_queued')) }}',offline:'{{ addslashes(__('pwa.offline_action_offline')) }}'};</script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireScripts
</head>
<body class="font-sans text-on-surface antialiased bg-surface">
    {{-- Skip to content link --}}
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-[100] focus:px-4 focus:py-2 focus:bg-primary focus:text-on-primary focus:rounded-lg focus:text-sm focus:font-semibold">{{ __('common.content_skip_to_content') }}</a>

    <div class="min-h-screen flex flex-col bg-surface">

        {{-- Public Navigation --}}
        <header id="pub-nav" class="sticky top-0 z-50 bg-surface/95 transition-[background-color] duration-200" x-data="{ scrolled: false }" x-init="window.addEventListener('scroll', () => { scrolled = window.scrollY > 10 }, { passive: true })" :class="scrolled && 'bg-surface/80 backdrop-blur-md shadow-sm'">
            <nav class="flex justify-between items-center w-full px-6 sm:px-8 py-4 max-w-screen-2xl mx-auto" aria-label="Main navigation">

                {{-- Logo --}}
                <a href="{{ route('home') }}" wire:navigate class="flex items-center gap-2">
                    @include('partials.logo', ['class' => 'h-10 w-auto'])
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
                    <a href="{{ route('gm.directory') }}" wire:navigate class="font-heading text-sm tracking-tight {{ request()->routeIs('gm.directory') ? 'text-primary font-bold border-b-2 border-primary-container pb-1' : 'text-on-surface-variant font-medium hover:text-primary transition-colors duration-200' }}">{{ __('profile.nav_gm_directory') }}</a>
                    @auth
                        <a href="{{ route('games.index') }}" wire:navigate class="font-heading text-sm tracking-tight {{ request()->routeIs('games.*') ? 'text-primary font-bold border-b-2 border-primary-container pb-1' : 'text-on-surface-variant font-medium hover:text-primary transition-colors duration-200' }}">{{ __('games.content_games') }}</a>
                        <a href="{{ route('campaigns.index') }}" wire:navigate class="font-heading text-sm tracking-tight {{ request()->routeIs('campaigns.*') ? 'text-primary font-bold border-b-2 border-primary-container pb-1' : 'text-on-surface-variant font-medium hover:text-primary transition-colors duration-200' }}">{{ __('campaigns.content_campaigns') }}</a>
                    @endauth
                    <a href="{{ $howItWorksRouteExists ? route('how-it-works') : url(app()->getLocale() . '/how-it-works') }}" wire:navigate class="font-heading text-sm tracking-tight {{ request()->routeIs('how-it-works') || request()->is('*how-it-works') ? 'text-primary font-bold border-b-2 border-primary-container pb-1' : 'text-on-surface-variant font-medium hover:text-primary transition-colors duration-200' }}">{{ __('pages.content_how_it_works') }}</a>
                    <a href="{{ route('pledge', app()->getLocale()) }}" wire:navigate class="font-heading text-sm tracking-tight {{ request()->routeIs('pledge*') ? 'text-primary font-bold border-b-2 border-primary-container pb-1' : 'text-on-surface-variant font-medium hover:text-primary transition-colors duration-200' }}">{{ __('common.nav_our_pledge') }}</a>
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
                        <span class="material-symbols-outlined text-2xl" aria-hidden="true" :class="{'hidden': open, 'block': !open}">menu</span>
                        <span class="material-symbols-outlined text-2xl" aria-hidden="true" :class="{'block': open, 'hidden': !open}">close</span>
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
                            <a href="{{ route('gm.directory') }}" wire:navigate class="block px-3 py-2.5 rounded-lg text-sm font-heading tracking-tight {{ request()->routeIs('gm.directory') ? 'text-primary font-bold bg-primary/5' : 'text-on-surface-variant font-medium hover:text-primary hover:bg-primary/5' }}">{{ __('profile.nav_gm_directory') }}</a>
                            @auth
                                <a href="{{ route('games.index') }}" wire:navigate class="block px-3 py-2.5 rounded-lg text-sm font-heading tracking-tight {{ request()->routeIs('games.*') ? 'text-primary font-bold bg-primary/5' : 'text-on-surface-variant font-medium hover:text-primary hover:bg-primary/5' }}">{{ __('games.content_games') }}</a>
                                <a href="{{ route('campaigns.index') }}" wire:navigate class="block px-3 py-2.5 rounded-lg text-sm font-heading tracking-tight {{ request()->routeIs('campaigns.*') ? 'text-primary font-bold bg-primary/5' : 'text-on-surface-variant font-medium hover:text-primary hover:bg-primary/5' }}">{{ __('campaigns.content_campaigns') }}</a>
                            @endauth
                            <a href="{{ $mobHowItWorksRouteExists ? route('how-it-works') : url(app()->getLocale() . '/how-it-works') }}" wire:navigate class="block px-3 py-2.5 rounded-lg text-sm font-heading tracking-tight {{ request()->routeIs('how-it-works') || request()->is('*how-it-works') ? 'text-primary font-bold bg-primary/5' : 'text-on-surface-variant font-medium hover:text-primary hover:bg-primary/5' }}">{{ __('pages.content_how_it_works') }}</a>
                            <a href="{{ route('pledge', app()->getLocale()) }}" wire:navigate class="block px-3 py-2.5 rounded-lg text-sm font-heading tracking-tight {{ request()->routeIs('pledge*') ? 'text-primary font-bold bg-primary/5' : 'text-on-surface-variant font-medium hover:text-primary hover:bg-primary/5' }}">{{ __('common.nav_our_pledge') }}</a>
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
                    <a href="{{ route('home') }}" wire:navigate class="inline-block">
                        @include('partials.logo', ['class' => 'h-8 w-auto'])
                    </a>
                    <p class="text-sm text-on-surface-variant max-w-xs">
                        &copy; {{ date('Y') }} {{ config('company.display_name') }}. {{ __('common.content_the_digital_parlor_for_tabletop_enthusiasts') }}
                    </p>
                    <div class="flex gap-4 items-center">
                        <a href="{{ route('home') }}" wire:navigate class="text-on-surface-variant hover:text-primary transition-colors" aria-label="{{ __('common.content_home') }}">
                            <span class="material-symbols-outlined" aria-hidden="true">public</span>
                        </a>
                        <a href="{{ route('about') }}" wire:navigate class="text-on-surface-variant hover:text-primary transition-colors" aria-label="{{ __('pages.content_about') }}">
                            <span class="material-symbols-outlined" aria-hidden="true">contact_support</span>
                        </a>
                        <a href="{{ route('contact') }}" wire:navigate class="text-on-surface-variant hover:text-primary transition-colors" aria-label="{{ __('pages.content_contact') }}">
                            <span class="material-symbols-outlined" aria-hidden="true">forum</span>
                        </a>
                        <a href="https://github.com/Roundup-Games/" target="_blank" rel="noopener noreferrer" class="text-on-surface-variant hover:text-primary transition-colors" aria-label="GitHub">
                            <svg style="width:1em;height:1em;fill:currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/></svg>
                        </a>
                        <span class="border-l border-outline-variant/30 h-5"></span>
                        <x-theme-toggle size="small" />
                    </div>
                </div>

                {{-- Link columns --}}
                <div class="grid grid-cols-2 md:grid-cols-3 gap-x-12 gap-y-4">
                    @php
                        $gameSystemsRouteExists = \Illuminate\Support\Facades\Route::has('game-systems');
                        $forOrganizersRouteExists = \Illuminate\Support\Facades\Route::has('for-organizers');
                    @endphp
                    <div class="flex flex-col gap-2">
                        <span class="text-xs font-bold text-primary uppercase tracking-wide mb-2">{{ __('common.content_platform') }}</span>
                        <a href="{{ route('discover') }}" wire:navigate class="text-on-surface-variant hover:text-primary text-sm transition-colors">{{ __('discovery.action_discover') }}</a>
                        <a href="{{ route('gm.directory') }}" wire:navigate class="text-on-surface-variant hover:text-primary text-sm transition-colors">{{ __('profile.nav_gm_directory') }}</a>
                        <a href="{{ $gameSystemsRouteExists ? route('game-systems') : url(app()->getLocale() . '/game-systems') }}" wire:navigate class="text-on-surface-variant hover:text-primary text-sm transition-colors">{{ __('games.content_game_systems') }}</a>
                        <a href="{{ route('events.index') }}" wire:navigate class="text-on-surface-variant hover:text-primary text-sm transition-colors">{{ __('events.content_events') }}</a>
                        @auth
                            <a href="{{ route('games.index') }}" wire:navigate class="text-on-surface-variant hover:text-primary text-sm transition-colors">{{ __('games.content_games') }}</a>
                            <a href="{{ route('campaigns.index') }}" wire:navigate class="text-on-surface-variant hover:text-primary text-sm transition-colors">{{ __('campaigns.content_campaigns') }}</a>
                        @endauth
                    </div>
                    <div class="flex flex-col gap-2">
                        <span class="text-xs font-bold text-primary uppercase tracking-wide mb-2">{{ __('common.content_community_and_trust') }}</span>
                        <a href="{{ route('about') }}" wire:navigate class="text-on-surface-variant hover:text-primary text-sm transition-colors">{{ __('pages.content_about') }}</a>
                        <a href="{{ route('pledge', app()->getLocale()) }}" wire:navigate class="text-on-surface-variant hover:text-primary text-sm transition-colors">{{ __('common.nav_our_pledge') }}</a>
                        <a href="{{ route('pledge.algorithms', app()->getLocale()) }}" wire:navigate class="text-on-surface-variant hover:text-primary text-sm transition-colors">{{ __('pages.content_pledge_card_algorithms_title') }}</a>
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

            {{-- Bottom bar: legal links + cookie settings --}}
            <div class="border-t border-outline-variant/10 px-8 sm:px-12 py-4">
                <div class="max-w-screen-2xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-2 text-xs text-on-surface-variant">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 justify-center">
                        <a href="{{ route('impressum', app()->getLocale()) }}" wire:navigate class="hover:text-primary transition-colors">{{ __('common.nav_impressum') }}</a>
                        <span class="hidden sm:inline" aria-hidden="true">·</span>
                        <a href="{{ route('privacy', app()->getLocale()) }}" wire:navigate class="hover:text-primary transition-colors">{{ __('common.nav_privacy') }}</a>
                        <span class="hidden sm:inline" aria-hidden="true">·</span>
                        <a href="{{ route('terms', app()->getLocale()) }}" wire:navigate class="hover:text-primary transition-colors">{{ __('common.nav_terms') }}</a>
                        <span class="hidden sm:inline" aria-hidden="true">·</span>
                        @if(config('cookie-consent.enabled'))
                            <button type="button" onclick="if(window.laravelCookieConsent)window.laravelCookieConsent.showCookieDialog()" class="js-cookie-consent-settings hover:text-primary transition-colors cursor-pointer">{{ __('common.nav_cookie_settings') }}</button>
                        @endif
                    </div>
                    <p>&copy; {{ date('Y') }} {{ config('company.legal_name') }}</p>
                </div>
            </div>

            <div class="w-full h-1 bg-primary"></div>
        </footer>
    </div>

    {{-- Offline indicator (no server round-trips) --}}
    <x-offline-indicator />

    @include('partials.posthog-script')
</body>
</html>


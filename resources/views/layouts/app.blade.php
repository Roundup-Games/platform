<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title', __('profile.content_dashboard')) — {{ config('app.name', 'Roundup Games') }}</title>

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

        {{-- Fonts: self-hosted Inter (body) + Noto Serif (headings) via @font-face in app.css --}}
        {{-- Material Symbols (icons) — remaining Google Fonts dependency, subset via config/fonts.php --}}
        @php
            $iconFontUrl = 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap';
        @endphp
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="preload" href="{{ $iconFontUrl }}" as="style" onload="this.onload=null;this.rel='stylesheet'">
        <noscript><link href="{{ $iconFontUrl }}" rel="stylesheet"></noscript>

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

        {{-- Scripts --}}
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-on-surface antialiased bg-surface">
        {{-- Skip to content link --}}
        <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-[100] focus:px-4 focus:py-2 focus:bg-primary focus:text-on-primary focus:rounded-lg focus:text-sm focus:font-semibold">{{ __('common.content_skip_to_content') }}</a>

        <div class="min-h-screen flex flex-col bg-surface">

            {{-- ============================================================ --}}
            {{-- Mobile Header — cream/amber with Material Symbols icons    --}}
            {{-- ============================================================ --}}
            <div class="lg:hidden bg-surface border-b border-outline-variant/15" x-data="{ open: false }">
                <div class="flex items-center justify-between px-4 py-3">
                    <a href="{{ route('home') }}" wire:navigate class="flex items-center gap-2">
                        @include('partials.logo', ['class' => 'h-10 w-auto'])
                    </a>
                    <button @click="open = !open" class="p-2 text-on-surface-variant hover:text-primary transition-colors" aria-label="{{ __('common.aria_toggle_navigation_menu') }}" :aria-expanded="open.toString()">
                        <span class="material-symbols-outlined text-2xl" :class="{'hidden': open, 'block': !open}">menu</span>
                        <span class="material-symbols-outlined text-2xl" :class="{'block': open, 'hidden': !open}">close</span>
                    </button>
                </div>

                {{-- Mobile Nav Dropdown --}}
                <div x-show="open"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 -translate-y-1"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 translate-y-0"
                     x-transition:leave-end="opacity-0 -translate-y-1"
                     @click.away="open = false"
                     class="bg-surface/95 backdrop-blur-md border-b border-outline-variant/15">
                    <div class="px-4 pb-4 space-y-1">
                        <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium {{ request()->routeIs('dashboard') ? 'bg-primary/10 text-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container-high hover:text-primary' }}">
                            <span class="material-symbols-outlined text-lg" {{ request()->routeIs('dashboard') ? 'style="font-variation-settings: \'FILL\' 1"' : '' }}>dashboard</span>
                            {{ __('profile.content_dashboard') }}
                        </a>
                        <a href="{{ route('notifications.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium {{ request()->routeIs('notifications.*') ? 'bg-primary/10 text-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container-high hover:text-primary' }}">
                            <span class="material-symbols-outlined text-lg" {{ request()->routeIs('notifications.*') ? 'style="font-variation-settings: \'FILL\' 1"' : '' }}>notifications</span>
                            {{ __('notifications.nav_label') }}
                        </a>
                        <a href="{{ route('games.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium {{ request()->routeIs('games.*') ? 'bg-primary/10 text-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container-high hover:text-primary' }}">
                            <span class="material-symbols-outlined text-lg" {{ request()->routeIs('games.*') ? 'style="font-variation-settings: \'FILL\' 1"' : '' }}>stadium</span>
                            {{ __('games.heading_my_games') }}
                        </a>
                        <a href="{{ route('campaigns.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium {{ request()->routeIs('campaigns.*') ? 'bg-primary/10 text-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container-high hover:text-primary' }}">
                            <span class="material-symbols-outlined text-lg" {{ request()->routeIs('campaigns.*') ? 'style="font-variation-settings: \'FILL\' 1"' : '' }}>campaign</span>
                            {{ __('campaigns.heading_my_campaigns') }}
                        </a>
                        <a href="{{ route('people') }}" wire:navigate class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium {{ request()->routeIs('people') ? 'bg-primary/10 text-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container-high hover:text-primary' }}">
                            <span class="material-symbols-outlined text-lg" {{ request()->routeIs('people') ? 'style="font-variation-settings: \'FILL\' 1"' : '' }}>group</span>
                            {{ __('profile.nav_people') }}
                        </a>
                        @if(Auth::user()?->isGM())
                        <a href="{{ route('gm.workspace') }}" wire:navigate class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium {{ request()->routeIs('gm.workspace') ? 'bg-primary/10 text-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container-high hover:text-primary' }}">
                            <span class="material-symbols-outlined text-lg" {{ request()->routeIs('gm.workspace') ? 'style="font-variation-settings: \'FILL\' 1"' : '' }}>casino</span>
                            {{ __('profile.nav_gm_workspace') }}
                        </a>
                        @endif
                        <a href="{{ route('billing.portal') }}" wire:navigate class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium {{ request()->routeIs('billing.*') ? 'bg-primary/10 text-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container-high hover:text-primary' }}">
                            <span class="material-symbols-outlined text-lg" {{ request()->routeIs('billing.*') ? 'style="font-variation-settings: \'FILL\' 1"' : '' }}>account_balance_wallet</span>
                            {{ __('billing.content_billing') }}
                        </a>

                        {{-- Separator --}}
                        <div class="border-t border-outline-variant/15 my-2"></div>

                        <a href="{{ route('profile.show') }}" wire:navigate class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium {{ request()->routeIs('profile.*') ? 'bg-primary/10 text-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container-high hover:text-primary' }}">
                            <span class="material-symbols-outlined text-lg" {{ request()->routeIs('profile.*') ? 'style="font-variation-settings: \'FILL\' 1"' : '' }}>person</span>
                            {{ __('profile.content_profile') }}
                        </a>
                        <form method="POST" action="{{ route('logout') }}" class="mt-1">
                            @csrf
                            <button type="submit" class="flex items-center gap-3 w-full px-4 py-3 rounded-xl text-sm font-medium text-on-surface-variant hover:bg-surface-container-high hover:text-primary transition-colors">
                                <span class="material-symbols-outlined text-lg">logout</span>
                                {{ __('auth.content_log_out') }}
                            </button>
                        </form>

                        {{-- Theme toggle for mobile --}}
                        <div class="pt-2 flex justify-end items-center gap-3">
                            @php
                                $mobileOtherLocale = app()->getLocale() === 'en' ? 'de' : 'en';
                                $mobileCurrentPath = '/' . request()->path();
                            @endphp
                            <a href="{{ route('locale.switch', ['locale' => $mobileOtherLocale, 'redirect' => $mobileCurrentPath]) }}" class="font-heading text-sm font-medium text-on-surface-variant hover:text-primary transition-colors uppercase">{{ strtoupper($mobileOtherLocale) }}</a>
                            <x-theme-toggle size="small" />
                        </div>
                    </div>
                </div>
            </div>

            {{-- ============================================================ --}}
            {{-- Main layout: Sidebar + Content                              --}}
            {{-- ============================================================ --}}
            <div class="flex flex-1">

                {{-- ======================================================== --}}
                {{-- Sidebar (Desktop) — surface_container_low bg             --}}
                {{-- ======================================================== --}}
                <aside class="hidden lg:flex lg:flex-col lg:w-64 bg-surface-container-low border-r border-outline-variant/15 min-h-screen sticky top-0 h-screen overflow-y-auto">

                    {{-- Logo area --}}
                    <div class="flex items-center h-16 px-6 border-b border-outline-variant/15">
                        <a href="{{ route('home') }}" wire:navigate class="flex items-center gap-2">
                            @include('partials.logo', ['class' => 'h-10 w-auto'])
                        </a>
                        @php
                            $otherLocale = app()->getLocale() === 'en' ? 'de' : 'en';
                            $currentPath = '/' . request()->path();
                        @endphp
                        <a href="{{ route('locale.switch', ['locale' => $otherLocale, 'redirect' => $currentPath]) }}" class="ml-auto font-heading text-sm font-medium text-on-surface-variant hover:text-primary transition-colors uppercase">{{ strtoupper($otherLocale) }}</a>
                    </div>

                    {{-- Navigation --}}
                    <nav class="flex-1 px-3 py-6 space-y-1" aria-label="{{ __('common.aria_main_navigation') }}">
                        {{-- Primary navigation items --}}
                        <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm transition-all duration-200 {{ request()->routeIs('dashboard') ? 'bg-surface-container-lowest text-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container-high hover:text-primary font-medium' }}">
                            <span class="material-symbols-outlined text-lg" {{ request()->routeIs('dashboard') ? 'style="font-variation-settings: \'FILL\' 1"' : '' }}>dashboard</span>
                            {{ __('profile.content_dashboard') }}
                        </a>

                        {{-- Notifications bell --}}
                        <livewire:notifications.notification-bell />

                        <a href="{{ route('games.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm transition-all duration-200 {{ request()->routeIs('games.*') ? 'bg-surface-container-lowest text-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container-high hover:text-primary font-medium' }}">
                            <span class="material-symbols-outlined text-lg" {{ request()->routeIs('games.*') ? 'style="font-variation-settings: \'FILL\' 1"' : '' }}>stadium</span>
                            {{ __('games.heading_my_games') }}
                        </a>

                        <a href="{{ route('campaigns.index') }}" wire:navigate class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm transition-all duration-200 {{ request()->routeIs('campaigns.*') ? 'bg-surface-container-lowest text-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container-high hover:text-primary font-medium' }}">
                            <span class="material-symbols-outlined text-lg" {{ request()->routeIs('campaigns.*') ? 'style="font-variation-settings: \'FILL\' 1"' : '' }}>campaign</span>
                            {{ __('campaigns.heading_my_campaigns') }}
                        </a>

                        <a href="{{ route('people') }}" wire:navigate class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm transition-all duration-200 {{ request()->routeIs('people') ? 'bg-surface-container-lowest text-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container-high hover:text-primary font-medium' }}">
                            <span class="material-symbols-outlined text-lg" {{ request()->routeIs('people') ? 'style="font-variation-settings: \'FILL\' 1"' : '' }}>group</span>
                            {{ __('profile.nav_people') }}
                        </a>

                        {{-- Divider before GM / account section --}}
                        <div class="pt-4 mt-4 border-t border-outline-variant/15"></div>

                        @if(Auth::user()?->isGM())
                        <a href="{{ route('gm.workspace') }}" wire:navigate class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm transition-all duration-200 {{ request()->routeIs('gm.workspace') ? 'bg-surface-container-lowest text-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container-high hover:text-primary font-medium' }}">
                            <span class="material-symbols-outlined text-lg" {{ request()->routeIs('gm.workspace') ? 'style="font-variation-settings: \'FILL\' 1"' : '' }}>casino</span>
                            {{ __('profile.nav_gm_workspace') }}
                        </a>
                        @endif

                        <a href="{{ route('billing.portal') }}" wire:navigate class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm transition-all duration-200 {{ request()->routeIs('billing.*') ? 'bg-surface-container-lowest text-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container-high hover:text-primary font-medium' }}">
                            <span class="material-symbols-outlined text-lg" {{ request()->routeIs('billing.*') ? 'style="font-variation-settings: \'FILL\' 1"' : '' }}>account_balance_wallet</span>
                            {{ __('billing.content_billing') }}
                        </a>

                        <a href="{{ route('profile.show') }}" wire:navigate class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm transition-all duration-200 {{ request()->routeIs('profile.*') ? 'bg-surface-container-lowest text-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container-high hover:text-primary font-medium' }}">
                            <span class="material-symbols-outlined text-lg" {{ request()->routeIs('profile.*') ? 'style="font-variation-settings: \'FILL\' 1"' : '' }}>person</span>
                            {{ __('profile.content_profile') }}
                        </a>
                    </nav>

                    {{-- User section at sidebar bottom --}}
                    <div class="border-t border-outline-variant/15 p-4">
                        <div class="flex items-center gap-3">
                            <x-user-avatar :user="Auth::user()" size="w-9 h-9" text-size="text-sm" />
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-on-surface truncate">{{ Auth::user()->name }}</p>
                                <p class="text-xs text-on-surface-variant truncate">{{ Auth::user()->email }}</p>
                            </div>
                        </div>
                        <div class="mt-3 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <a href="{{ route('profile.show') }}" wire:navigate class="text-xs text-on-surface-variant hover:text-primary transition-colors">
                                    <span class="material-symbols-outlined text-sm align-middle">settings</span>
                                    {{ __('profile.content_settings') }}
                                </a>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" onclick="event.preventDefault(); this.closest('form').submit();" class="text-xs text-on-surface-variant hover:text-primary transition-colors">
                                        <span class="material-symbols-outlined text-sm align-middle">logout</span>
                                        {{ __('auth.content_log_out') }}
                                    </button>
                                </form>
                            </div>
                            <x-theme-toggle size="small" />
                        </div>
                    </div>
                </aside>

                {{-- ======================================================== --}}
                {{-- Main Content Area                                        --}}
                {{-- ======================================================== --}}
                <div class="flex-1 flex flex-col min-w-0">

                    {{-- Top Bar (Desktop) --}}
                    <header class="hidden lg:flex h-16 bg-surface border-b border-outline-variant/15 items-center px-6 sticky top-0 z-40">
                        <div class="flex items-center">
                            @hasSection('title')
                                <h1 class="font-heading text-lg font-semibold text-on-surface tracking-tight">
                                    @yield('title')
                                </h1>
                            @elseif(isset($header))
                                <h2 class="font-heading text-lg font-semibold text-on-surface tracking-tight">
                                    {{ $header }}
                                </h2>
                            @else
                                <h1 class="font-heading text-lg font-semibold text-on-surface tracking-tight">{{ __('profile.content_dashboard') }}</h1>
                            @endif
                        </div>
                    </header>

                    {{-- Page Content --}}
                    <main id="main-content" class="flex-1 p-6">
                        {{ $slot }}
                    </main>
                </div>
            </div>
        </div>
        {{-- Offline indicator (no server round-trips) --}}
        <x-offline-indicator />

        {{-- PWA Install Prompt (only for authenticated + eligible users) --}}
        @auth
            @php
                $pwaEligible = app(App\Services\PwaEligibilityService::class)->isEligible(Auth::user())->eligible;
            @endphp
            <x-pwa-install-prompt :eligible="$pwaEligible" />
        @endauth
    </body>
</html>

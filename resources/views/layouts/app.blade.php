<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Roundup Games') }} — @yield('title', 'Dashboard')</title>

        <!-- Fonts: Oswald for headers, Montserrat for body -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=montserrat:400,500,600,700&family=oswald:500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen bg-gray-50 dark:bg-gray-900">
            <!-- Mobile Header -->
            <div class="lg:hidden bg-brand dark:bg-brand-dark" x-data="{ open: false }">
                <div class="flex items-center justify-between px-4 py-3">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                        <span class="text-xl font-heading font-bold uppercase text-white">Roundup<span class="text-white/70">Games</span></span>
                    </a>
                    <button @click="open = !open" class="text-white p-2">
                        <svg class="h-6 w-6" :class="{'hidden': open, 'block': !open}" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                        <svg class="h-6 w-6" :class="{'block': open, 'hidden': !open}" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <!-- Mobile Nav Menu -->
                <div :class="{'block': open, 'hidden': !open}" class="px-4 pb-4 space-y-1">
                    <a href="{{ route('dashboard') }}" class="block px-3 py-2 rounded-lg text-sm font-medium text-white/90 hover:text-white hover:bg-white/10 {{ request()->routeIs('dashboard') ? 'bg-white/20 text-white' : '' }}">Dashboard</a>
                    <a href="{{ route('teams.browse') }}" class="block px-3 py-2 rounded-lg text-sm font-medium text-white/90 hover:text-white hover:bg-white/10 {{ request()->routeIs('teams.*') ? 'bg-white/20 text-white' : '' }}">Teams</a>
                    <a href="{{ route('profile.show') }}" class="block px-3 py-2 rounded-lg text-sm font-medium text-white/90 hover:text-white hover:bg-white/10 {{ request()->routeIs('profile.*') ? 'bg-white/20 text-white' : '' }}">Profile</a>
                    <form method="POST" action="{{ route('logout') }}" class="mt-2">
                        @csrf
                        <button type="submit" class="block w-full text-left px-3 py-2 rounded-lg text-sm font-medium text-white/90 hover:text-white hover:bg-white/10">Log Out</button>
                    </form>
                </div>
            </div>

            <div class="flex">
                <!-- Sidebar (Desktop) -->
                <aside class="hidden lg:flex lg:flex-col lg:w-64 bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 min-h-screen">
                    <!-- Logo -->
                    <div class="flex items-center h-16 px-6 border-b border-gray-200 dark:border-gray-700">
                        <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                            <span class="text-xl font-heading font-bold uppercase text-brand">Roundup<span class="text-gray-800 dark:text-gray-200">Games</span></span>
                        </a>
                    </div>

                    <!-- Navigation -->
                    <nav class="flex-1 px-4 py-6 space-y-1 overflow-y-auto">
                        <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                            <span class="flex items-center gap-3">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" /></svg>
                                Dashboard
                            </span>
                        </x-responsive-nav-link>

                        <x-responsive-nav-link :href="route('teams.browse')" :active="request()->routeIs('teams.*')">
                            <span class="flex items-center gap-3">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                Teams
                            </span>
                        </x-responsive-nav-link>

                        <x-responsive-nav-link :href="route('profile.show')" :active="request()->routeIs('profile.*')">
                            <span class="flex items-center gap-3">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                                Profile
                            </span>
                        </x-responsive-nav-link>
                    </nav>

                    <!-- User Section -->
                    <div class="border-t border-gray-200 dark:border-gray-700 p-4">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-full bg-brand/10 flex items-center justify-center">
                                <span class="text-brand font-heading font-bold text-sm uppercase">{{ strtoupper(Auth::user()->name[0] ?? 'U') }}</span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate">{{ Auth::user()->name }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ Auth::user()->email }}</p>
                            </div>
                        </div>
                        <div class="mt-3 space-y-1">
                            <x-responsive-nav-link :href="route('profile.show')">
                                <span class="text-xs">{{ __('Settings') }}</span>
                            </x-responsive-nav-link>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <x-responsive-nav-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">
                                    <span class="text-xs">{{ __('Log Out') }}</span>
                                </x-responsive-nav-link>
                            </form>
                        </div>
                    </div>
                </aside>

                <!-- Main Content -->
                <div class="flex-1 flex flex-col min-w-0">
                    <!-- Top Bar (Desktop) -->
                    <header class="hidden lg:flex h-16 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 items-center px-6">
                        @hasSection('title')
                            <h1 class="font-heading text-lg font-semibold text-gray-800 dark:text-gray-200 uppercase">
                                @yield('title')
                            </h1>
                        @elseif(isset($header))
                            <h2 class="font-heading text-lg font-semibold text-gray-800 dark:text-gray-200 uppercase leading-tight">
                                {{ $header }}
                            </h2>
                        @else
                            <h1 class="font-heading text-lg font-semibold text-gray-800 dark:text-gray-200 uppercase">Dashboard</h1>
                        @endif
                    </header>

                    <!-- Page Content -->
                    <main class="flex-1 p-6">
                        {{ $slot }}
                    </main>
                </div>
            </div>
        </div>
    </body>
</html>

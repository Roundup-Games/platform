<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Events') — {{ config('app.name', 'Roundup Games') }}</title>

    <!-- Fonts: Oswald for headers, Montserrat for body -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=montserrat:400,500,600,700&family=oswald:500,600,700&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans text-gray-900 antialiased">
    <div class="min-h-screen bg-gray-50 dark:bg-gray-900 flex flex-col">
        {{-- Public Navigation --}}
        <nav class="bg-[#C12E26] dark:bg-brand-dark">
            <div class="max-w-6xl mx-auto px-4 sm:px-6">
                <div class="flex items-center justify-between h-16">
                    <a href="{{ url('/') }}" class="flex items-center gap-2">
                        <span class="text-xl font-heading font-bold uppercase text-white">Roundup<span class="text-white/70">Games</span></span>
                    </a>

                    {{-- Desktop Nav --}}
                    <div class="hidden sm:flex items-center gap-6">
                        <a href="{{ route('events.index') }}" class="text-sm font-medium text-white/90 hover:text-white transition-colors {{ request()->routeIs('events.*') ? 'text-white' : '' }}">Events</a>
                        <a href="{{ route('teams.browse') }}" class="text-sm font-medium text-white/90 hover:text-white transition-colors">Teams</a>

                        @auth
                            <a href="{{ route('dashboard') }}" class="px-4 py-2 bg-white/20 text-white rounded-lg hover:bg-white/30 text-sm font-medium transition-colors">
                                Dashboard
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="text-sm font-medium text-white/90 hover:text-white transition-colors">Log in</a>
                            <a href="{{ route('register') }}" class="px-4 py-2 bg-white text-[#C12E26] rounded-lg hover:bg-white/90 text-sm font-medium transition-colors">
                                Sign up
                            </a>
                        @endauth
                    </div>

                    {{-- Mobile menu button --}}
                    <button class="sm:hidden text-white p-2" x-data="{ open: false }" @click="open = !open">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                </div>
            </div>
        </nav>

        {{-- Main Content --}}
        <main class="flex-1">
            {{ $slot }}
        </main>

        {{-- Footer --}}
        <footer class="bg-gray-900 dark:bg-gray-950 text-white">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 py-10">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-8">
                    <div>
                        <span class="text-lg font-heading font-bold uppercase">Roundup<span class="text-white/70">Games</span></span>
                        <p class="mt-2 text-sm text-gray-400">Organize. Compete. Connect.</p>
                    </div>
                    <div>
                        <h4 class="font-heading font-semibold uppercase text-sm tracking-wide mb-3">Quick Links</h4>
                        <ul class="space-y-2 text-sm text-gray-400">
                            <li><a href="{{ route('events.index') }}" class="hover:text-white transition-colors">Events</a></li>
                            <li><a href="{{ route('teams.browse') }}" class="hover:text-white transition-colors">Teams</a></li>
                            <li><a href="{{ route('about') }}" class="hover:text-white transition-colors">About</a></li>
                        </ul>
                    </div>
                    <div>
                        <h4 class="font-heading font-semibold uppercase text-sm tracking-wide mb-3">Contact</h4>
                        <ul class="space-y-2 text-sm text-gray-400">
                            <li><a href="{{ route('contact') }}" class="hover:text-white transition-colors">Contact Us</a></li>
                        </ul>
                    </div>
                </div>
                <div class="mt-8 pt-6 border-t border-gray-800 text-center text-xs text-gray-500">
                    &copy; {{ date('Y') }} Roundup Games. All rights reserved.
                </div>
            </div>
        </footer>
    </div>
</body>
</html>

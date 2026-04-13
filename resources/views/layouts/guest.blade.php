<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Roundup Games') }}</title>

        <!-- Dark mode: apply class before paint to prevent flash -->
        <script>
            if (localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        </script>

        <!-- Fonts: Oswald for headers, Montserrat for body -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=montserrat:400,500,600,700&family=oswald:500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 dark:text-gray-100 antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-100 dark:bg-gray-900">
            <!-- Brand Header -->
            <div class="mb-2">
                <a href="/" wire:navigate class="flex items-center gap-2">
                    <span class="text-3xl font-heading font-bold uppercase text-brand">Roundup<span class="text-gray-800 dark:text-gray-200">Games</span></span>
                </a>
            </div>

            <!-- Auth Card -->
            <div class="w-full sm:max-w-md mt-4 px-8 py-6 bg-white dark:bg-gray-800 shadow-lg overflow-hidden sm:rounded-2xl border border-gray-100 dark:border-gray-700">
                {{ $slot }}
            </div>

            <!-- Footer -->
            <p class="mt-6 text-xs text-gray-400 dark:text-gray-600">&copy; {{ date('Y') }} Roundup Games. All rights reserved.</p>
        </div>
    </body>
</html>

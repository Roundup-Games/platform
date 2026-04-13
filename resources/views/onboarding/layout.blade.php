<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Roundup Games') }} — Complete Your Profile</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=montserrat:400,500,600,700&family=oswald:500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-['Montserrat'] text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col items-center justify-center bg-gray-50 dark:bg-gray-900 px-4">
            <div class="w-full max-w-lg">
                <div class="text-center mb-8">
                    <a href="/" wire:navigate class="inline-flex items-center gap-2">
                        <span class="text-2xl font-heading font-bold uppercase text-brand-dark">Roundup Games</span>
                    </a>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Complete your profile to get started</p>
                </div>

                {{ $slot }}
            </div>
        </div>
    </body>
</html>

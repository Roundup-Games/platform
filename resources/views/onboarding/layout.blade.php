<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Roundup Games') }} — {{ __('Complete Your Profile') }}</title>

        {{-- Dark mode: apply class before paint to prevent flash --}}
        <script>
            if (localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        </script>

        {{-- Fonts: Noto Serif for headings, Inter for body, Material Symbols for icons --}}
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Noto+Serif:ital,wght@0,400;0,600;0,700;0,800;1,400;1,700&display=swap" rel="stylesheet" />
        <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />

        {{-- Scripts --}}
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-on-surface antialiased">
        <div class="min-h-screen flex flex-col items-center justify-center bg-surface dark:bg-[#1b1c17] px-4">
            <div class="w-full max-w-lg">
                {{-- Logo --}}
                <div class="text-center mb-8">
                    <a href="/" wire:navigate class="inline-flex items-center gap-2">
                        <span class="text-2xl font-heading font-bold text-primary tracking-tight">Roundup<span class="text-on-surface dark:text-[#eae8e0]">Games</span></span>
                    </a>
                    <p class="mt-2 text-sm text-on-surface-variant">{{ __('Complete your profile to get started') }}</p>
                </div>

                {{-- Content --}}
                {{ $slot }}
            </div>
        </div>
    </body>
</html>

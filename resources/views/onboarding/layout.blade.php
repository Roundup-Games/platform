<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', config('company.display_name')) }} — {{ __('profile.content_complete_your_profile') }}</title>

        {{-- Dark mode: apply class + critical background before paint to prevent flash --}}
        <script>
            (function() {
                var t = localStorage.getItem('theme');
                var dark = t === 'dark' || ((t === 'system' || t === null) && window.matchMedia('(prefers-color-scheme: dark)').matches);
                if (dark) document.documentElement.classList.add('dark');
            })();
        </script>
        <meta name="color-scheme" content="dark light">
        <style>
            body { background-color: #fbf9f1; }
            .dark body { background-color: #1b1c17; }
        </style>

        {{-- Fonts: self-hosted Inter (body) + Noto Serif (headings) + Material Symbols (icons) via @font-face in app.css --}}

        {{-- Scripts --}}
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-on-surface antialiased">
        <div class="min-h-screen flex flex-col items-center justify-center bg-surface px-4">
            <div class="w-full max-w-lg">
                {{-- Logo --}}
                <div class="text-center mb-8">
                    <a href="/" wire:navigate class="inline-flex items-center gap-2">
                        <span class="text-2xl font-heading font-bold text-primary tracking-tight">Roundup<span class="text-on-surface">Games</span></span>
                    </a>
                    <p class="mt-2 text-sm text-on-surface-variant">{{ __('profile.field_complete_your_profile_to_get_started') }}</p>
                </div>

                {{-- Content --}}
                {{ $slot }}
            </div>
        </div>
    </body>
</html>

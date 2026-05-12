<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Roundup Games') }}</title>

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

        {{-- Fonts: Noto Serif for headings, Inter for body, Material Symbols for icons --}}
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Noto+Serif:ital,wght@0,400;0,600;0,700;0,800;1,400;1,700&display=swap" rel="stylesheet" />
        <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />

        {{-- Scripts --}}
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-body text-on-surface antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-surface">
            {{-- Brand Header --}}
            <div class="mb-2">
                <a href="/" wire:navigate class="flex items-center gap-2">
                    @include('partials.logo', ['class' => 'h-14 w-auto'])
                </a>
            </div>

            {{-- Auth Card — surface_container_lowest bg, editorial shadow, xl+ rounded --}}
            <div class="w-full sm:max-w-md mt-4 px-8 py-6 bg-surface-container-lowest editorial-shadow overflow-hidden sm:rounded-2xl border border-outline-variant/15">
                {{ $slot }}
            </div>

            {{-- Footer --}}
            <p class="mt-6 text-xs text-on-surface-variant">&copy; {{ date('Y') }} Roundup Games. {{ __('pages.content_all_rights_reserved') }}</p>
        </div>
    </body>
</html>

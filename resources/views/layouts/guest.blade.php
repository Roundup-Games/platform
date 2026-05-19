<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        {{-- CSRF Token --}}
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- PostHog Analytics meta tags (shared partial for consistent config/exclusion logic) --}}
        @include('partials.posthog-meta')

        <title>{{ config('app.name', config('company.display_name')) }}</title>

        {{-- Favicons --}}
        <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32x32.png">
        <link rel="icon" type="image/png" sizes="16x16" href="/icons/favicon-16x16.png">

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
            /* Critical background fallback — prevents white flash before CSS loads */
            body { background-color: #fbf9f1; }
            .dark body { background-color: #1b1c17; }
        </style>

        {{-- Fonts: self-hosted Inter (body) + Noto Serif (headings) + Material Symbols (icons) via @font-face in app.css --}}
        {{-- Material Symbols is subset to project icons — rebuild with build-tools/subset-icons.sh --}}

        {{-- Scripts --}}
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @include('partials.posthog-script')
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
            <p class="mt-6 text-xs text-on-surface-variant">&copy; {{ date('Y') }} {{ config('company.display_name') }}. {{ __('pages.content_all_rights_reserved') }}</p>
        </div>
    </body>
</html>

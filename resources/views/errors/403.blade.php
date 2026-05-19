<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('common.status_not_authorized') }} — {{ config('company.display_name') }}</title>

    <script>
        (function() {
            var t = localStorage.getItem('theme');
            var dark = t === 'dark' || ((t === 'system' || t === null) && window.matchMedia('(prefers-color-scheme: dark)').matches);
            if (dark) document.documentElement.classList.add('dark');
        })();
    </script>

    {{-- Fonts: self-hosted Inter (body) + Noto Serif (headings) + Material Symbols (icons) via @font-face in app.css --}}

    @vite(['resources/css/app.css'])
</head>
<body class="font-body text-on-surface antialiased">
    <div class="min-h-screen flex flex-col items-center justify-center bg-surface px-6">
        {{-- Brand --}}
        <a href="/" wire:navigate class="mb-8">
            <span class="text-3xl font-heading font-bold text-primary tracking-tight">Roundup<span class="text-on-surface">Games</span></span>
        </a>

        {{-- Card --}}
        <div class="w-full max-w-md bg-surface-container-lowest editorial-shadow rounded-2xl border border-outline-variant/15 px-8 py-10 text-center">
            <span class="material-symbols-outlined text-5xl text-error mb-4 block" aria-hidden="true">lock</span>
            <h1 class="font-heading text-2xl font-bold text-on-surface mb-2">{{ __('common.status_not_authorized') }}</h1>
            <p class="text-on-surface-variant text-sm mb-8">{{ __('admin.content_no_admin_privileges') }}</p>
            <a href="{{ route('dashboard', ['locale' => app()->getLocale()]) }}" wire:navigate
               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-primary text-on-primary font-medium text-sm hover:bg-primary-fixed-dim transition-colors">
                <span class="material-symbols-outlined text-lg" aria-hidden="true">arrow_back</span>
                {{ __('admin.action_return_to_dashboard') }}
            </a>
        </div>

        <p class="mt-6 text-xs text-on-surface-variant">&copy; {{ date('Y') }} {{ config('company.display_name') }}.</p>
    </div>
</body>
</html>

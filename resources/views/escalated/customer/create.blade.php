<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('New Ticket') }} — {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/icons/favicon-16x16.png">
    <script>
        (function() {
            var t = localStorage.getItem('theme');
            var dark = t === 'dark' || ((t === 'system' || t === null) && window.matchMedia('(prefers-color-scheme: dark)').matches);
            if (dark) document.documentElement.classList.add('dark');
        })();
    </script>
    <meta name="color-scheme" content="dark light">
    @vite(['resources/css/app.css'])
</head>
<body class="font-body text-on-surface antialiased bg-surface min-h-screen">
    <nav class="bg-surface-container border-b border-outline-variant">
        <div class="max-w-5xl mx-auto px-4 h-14 flex items-center justify-between">
            <a href="{{ url('/') }}" class="text-lg font-bold text-primary">{{ config('app.name') }}</a>
            <div class="flex items-center gap-4">
                <a href="{{ route('escalated.customer.tickets.index') }}" class="text-sm font-medium text-on-surface hover:text-primary transition-colors">{{ __('My Tickets') }}</a>
                <a href="{{ route('escalated.customer.tickets.create') }}" class="text-sm font-medium text-primary hover:brightness-110 transition">{{ __('New Ticket') }}</a>
                <a href="{{ url('/dashboard') }}" class="text-sm text-on-surface-variant hover:text-on-surface transition-colors">{{ __('Dashboard') }}</a>
            </div>
        </div>
    </nav>

    <main class="max-w-2xl mx-auto px-4 py-8">
        <div class="mb-6">
            <a href="{{ route('escalated.customer.tickets.index') }}"
               class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary transition-colors">
                <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span>
                {{ __('All Tickets') }}
            </a>
        </div>

        <h1 class="text-2xl font-bold text-on-surface mb-6">{{ __('New Support Ticket') }}</h1>

        @if($errors->any())
            <div class="mb-4 p-3 bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 rounded-lg text-sm">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('escalated.customer.tickets.store') }}" class="space-y-5">
            @csrf

            <div>
                <label for="subject" class="block text-sm font-medium text-on-surface mb-1">{{ __('Subject') }}</label>
                <input type="text" name="subject" id="subject"
                       value="{{ old('subject') }}"
                       class="w-full rounded-lg border border-outline-variant bg-surface-container px-3 py-2 text-on-surface focus:ring-2 focus:ring-primary focus:border-primary"
                       required />
            </div>

            @if($departments->isNotEmpty())
            <div>
                <label for="department_id" class="block text-sm font-medium text-on-surface mb-1">{{ __('Department') }}</label>
                <select name="department_id" id="department_id"
                        class="w-full rounded-lg border border-outline-variant bg-surface-container px-3 py-2 text-on-surface focus:ring-2 focus:ring-primary focus:border-primary">
                    <option value="">{{ __('Select department') }}</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept->id }}" {{ old('department_id') == $dept->id ? 'selected' : '' }}>
                            {{ $dept->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            @endif

            <div>
                <label for="body" class="block text-sm font-medium text-on-surface mb-1">{{ __('Description') }}</label>
                <textarea name="body" id="body" rows="6"
                          class="w-full rounded-lg border border-outline-variant bg-surface-container px-3 py-2 text-on-surface focus:ring-2 focus:ring-primary focus:border-primary"
                          required>{{ old('body') }}</textarea>
            </div>

            <div>
                <label for="priority" class="block text-sm font-medium text-on-surface mb-1">{{ __('Priority') }}</label>
                <select name="priority" id="priority"
                        class="w-full rounded-lg border border-outline-variant bg-surface-container px-3 py-2 text-on-surface focus:ring-2 focus:ring-primary focus:border-primary">
                    @foreach(($priorities ?? ['low', 'medium', 'high', 'urgent']) as $p)
                        <option value="{{ is_array($p) ? $p['value'] ?? $p : $p }}" {{ old('priority') == $p ? 'selected' : '' }}>
                            {{ ucfirst(is_array($p) ? $p['label'] ?? $p['value'] ?? $p : $p) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-center justify-end gap-3 pt-2">
                <a href="{{ route('escalated.customer.tickets.index') }}"
                   class="px-4 py-2 text-sm text-on-surface-variant hover:text-on-surface transition-colors">
                    {{ __('Cancel') }}
                </a>
                <button type="submit"
                        class="px-5 py-2 bg-primary text-on-primary rounded-lg font-medium hover:brightness-110 active:scale-[0.96] transition-all">
                    {{ __('Submit Ticket') }}
                </button>
            </div>
        </form>
    </main>
</body>
</html>

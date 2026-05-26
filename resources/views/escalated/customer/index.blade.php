<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('Support Tickets') }} — {{ config('app.name') }}</title>
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
    {{-- Nav bar --}}
    <nav class="bg-surface-container border-b border-outline-variant">
        <div class="max-w-5xl mx-auto px-4 h-14 flex items-center justify-between">
            <a href="{{ url('/') }}" class="text-lg font-bold text-primary">
                {{ config('app.name') }}
            </a>
            <div class="flex items-center gap-4">
                <a href="{{ route('escalated.customer.tickets.index') }}" class="text-sm font-medium text-on-surface hover:text-primary transition-colors">{{ __('My Tickets') }}</a>
                <a href="{{ route('escalated.customer.tickets.create') }}" class="text-sm font-medium text-primary hover:brightness-110 transition">{{ __('New Ticket') }}</a>
                <a href="{{ url('/dashboard') }}" class="text-sm text-on-surface-variant hover:text-on-surface transition-colors">{{ __('Dashboard') }}</a>
            </div>
        </div>
    </nav>

    <main class="max-w-4xl mx-auto px-4 py-8">
        @if(session('success'))
            <div class="mb-4 p-3 bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-300 rounded-lg text-sm">
                {{ session('success') }}
            </div>
        @endif

        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold text-on-surface">{{ __('Support Tickets') }}</h1>
            <a href="{{ route('escalated.customer.tickets.create') }}"
               class="inline-flex items-center gap-1.5 px-4 py-2 bg-primary text-on-primary rounded-lg font-medium hover:brightness-110 active:scale-[0.96] transition-all text-sm">
                <span class="material-symbols-outlined text-lg" aria-hidden="true">add</span>
                {{ __('New Ticket') }}
            </a>
        </div>

        @forelse($tickets as $ticket)
            <div class="bg-surface-container rounded-xl border border-outline-variant p-4 mb-3 hover:shadow-md transition-shadow">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1 min-w-0">
                        <a href="{{ route('escalated.customer.tickets.show', $ticket->reference) }}"
                           class="text-base font-semibold text-on-surface hover:text-primary transition-colors">
                            {{ $ticket->subject }}
                        </a>
                        <div class="flex items-center gap-3 mt-1 text-sm text-on-surface-variant">
                            <span class="font-mono">{{ $ticket->reference }}</span>
                            <span>&middot;</span>
                            <span>{{ $ticket->created_at->diffForHumans() }}</span>
                        </div>
                    </div>
                    @php
                        $statusColor = match($ticket->status->value) {
                            'open', 'reopened' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
                            'in_progress' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
                            'waiting_on_customer' => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
                            'resolved', 'closed' => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
                            default => 'bg-gray-100 text-gray-700 dark:bg-gray-900/40 dark:text-gray-300',
                        };
                    @endphp
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $statusColor }}">
                        {{ $ticket->status->label() }}
                    </span>
                </div>
            </div>
        @empty
            <div class="text-center py-12 text-on-surface-variant">
                <span class="material-symbols-outlined text-4xl mb-2 block" aria-hidden="true">contact_support</span>
                <p class="text-lg font-medium">{{ __('No tickets yet') }}</p>
                <p class="text-sm mt-1">{{ __('Create a ticket if you need help with something.') }}</p>
            </div>
        @endforelse

        <div class="mt-6">
            {{ $tickets->withQueryString()->links() }}
        </div>
    </main>
</body>
</html>

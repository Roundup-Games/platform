<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $ticket->reference }} — {{ $ticket->subject }} — {{ config('app.name') }}</title>
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

    <main class="max-w-3xl mx-auto px-4 py-8">
        <div class="mb-6">
            <a href="{{ route('escalated.customer.tickets.index') }}"
               class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary transition-colors">
                <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span>
                {{ __('All Tickets') }}
            </a>
        </div>

        @if(session('success'))
            <div class="mb-4 p-3 bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-300 rounded-lg text-sm">
                {{ session('success') }}
            </div>
        @endif

        {{-- Ticket header --}}
        <div class="bg-surface-container rounded-xl border border-outline-variant p-5 mb-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-xl font-bold text-on-surface">{{ $ticket->subject }}</h1>
                    <div class="flex items-center gap-3 mt-1 text-sm text-on-surface-variant">
                        <span class="font-mono">{{ $ticket->reference }}</span>
                        <span>&middot;</span>
                        <span>{{ $ticket->created_at->format('M j, Y H:i') }}</span>
                        @if($ticket->department)
                            <span>&middot;</span>
                            <span>{{ $ticket->department->name }}</span>
                        @endif
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
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium flex-shrink-0 {{ $statusColor }}">
                    {{ $ticket->status->label() }}
                </span>
            </div>
            <div class="mt-4 text-on-surface text-sm whitespace-pre-wrap">{{ $ticket->body }}</div>
        </div>

        {{-- Replies --}}
        @foreach($ticket->replies as $reply)
            <div class="bg-surface-container rounded-xl border border-outline-variant p-4 mb-3">
                <div class="flex items-center gap-3 mb-2 text-sm text-on-surface-variant">
                    <span class="font-medium text-on-surface">
                        @if($reply->author_type === \App\Models\User::class && $reply->author_id === auth()->id())
                            {{ __('You') }}
                        @elseif($reply->author)
                            {{ $reply->author->name ?? __('Support Team') }}
                        @else
                            {{ __('Support Team') }}
                        @endif
                    </span>
                    <span>&middot;</span>
                    <span>{{ $reply->created_at->format('M j, Y H:i') }}</span>
                </div>
                <div class="text-on-surface text-sm whitespace-pre-wrap">{{ $reply->body }}</div>
            </div>
        @endforeach

        {{-- Reply form (open tickets) --}}
        @if($ticket->isOpen())
            <form method="POST" action="{{ route('escalated.customer.tickets.reply', $ticket->reference) }}"
                  class="bg-surface-container rounded-xl border border-outline-variant p-5 mt-4">
                @csrf
                <label for="body" class="block text-sm font-medium text-on-surface mb-2">{{ __('Reply') }}</label>
                <textarea name="body" id="body" rows="4"
                          class="w-full rounded-lg border border-outline-variant bg-surface px-3 py-2 text-on-surface focus:ring-2 focus:ring-primary focus:border-primary"
                          required></textarea>

                @if($errors->has('body'))
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $errors->first('body') }}</p>
                @endif

                <div class="flex items-center justify-between mt-4">
                    @if(config('escalated.tickets.allow_customer_close', true) && $ticket->status->value !== 'closed')
                        <button type="submit" formaction="{{ route('escalated.customer.tickets.close', $ticket->reference) }}"
                                formmethod="POST"
                                class="text-sm text-on-surface-variant hover:text-red-600 dark:hover:text-red-400 transition-colors">
                            {{ __('Close Ticket') }}
                        </button>
                    @else
                        <span></span>
                    @endif
                    <button type="submit"
                            class="px-5 py-2 bg-primary text-on-primary rounded-lg font-medium hover:brightness-110 active:scale-[0.96] transition-all">
                        {{ __('Send Reply') }}
                    </button>
                </div>
            </form>
        @elseif($ticket->status->value === 'resolved')
            <div class="flex items-center justify-center gap-4 mt-4">
                <form method="POST" action="{{ route('escalated.customer.tickets.reopen', $ticket->reference) }}">
                    @csrf
                    <button type="submit"
                            class="px-4 py-2 text-sm border border-outline-variant text-on-surface rounded-lg hover:bg-surface-container-highest transition-colors">
                        {{ __('Reopen Ticket') }}
                    </button>
                </form>
            </div>
        @endif
    </main>
</body>
</html>

<div class="py-6 sm:py-8">
    <div class="max-w-3xl mx-auto">
        {{-- Page Header --}}
        <div class="mb-6 sm:mb-8">
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('game-systems') }}" wire:navigate class="text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">arrow_back</span>
                </a>
                <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('games.heading_my_requests') }}</h1>
            </div>
            <p class="ml-8 sm:ml-9 text-sm text-on-surface-variant">{{ __('games.content_my_requests_subtitle') }}</p>
        </div>

        {{-- Request List --}}
        @if($requests->count() > 0)
            <div class="space-y-3">
                @foreach($requests as $request)
                    <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-4 sm:p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex-1 min-w-0">
                                {{-- Name --}}
                                @if($request->status === 'approved' && $request->gameSystem)
                                    <a href="{{ route('game-systems.show', $request->gameSystem->slug) }}"
                                       wire:navigate
                                       class="text-base font-semibold text-on-surface hover:text-primary transition-colors truncate block">
                                        {{ $request->name }}
                                    </a>
                                @else
                                    <h3 class="text-base font-semibold text-on-surface truncate">{{ $request->name }}</h3>
                                @endif

                                {{-- Meta row: type + date --}}
                                <div class="flex items-center gap-2 mt-1 text-xs text-on-surface-variant">
                                    <span>{{ $this->getTypeLabel($request->type) }}</span>
                                    <span aria-hidden="true">·</span>
                                    <time datetime="{{ $request->created_at->toIso8601String() }}">{{ $request->created_at->format('M j, Y') }}</time>
                                </div>

                                {{-- Rejection reason --}}
                                @if($request->status === 'rejected' && $request->rejection_reason)
                                    <p class="mt-2 text-sm text-error">{{ $request->rejection_reason }}</p>
                                @endif
                            </div>

                            {{-- Status badge --}}
                            @php
                                $color = $this->getStatusColor($request->status);
                                $badgeClasses = match($color) {
                                    'yellow' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300',
                                    'green' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
                                    'red' => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
                                    'blue' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
                                    default => 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-300',
                                };
                            @endphp
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium whitespace-nowrap {{ $badgeClasses }}">
                                {{ $this->getStatusLabel($request->status) }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Pagination --}}
            <div class="mt-6">
                {{ $requests->links() }}
            </div>
        @else
            <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-8 text-center">
                <span class="material-symbols-outlined text-4xl text-on-surface-variant mb-3 block" aria-hidden="true">inbox</span>
                <p class="text-sm text-on-surface-variant">{{ __('games.content_no_requests_yet') }}</p>
                <a href="{{ route('game-systems.request') }}"
                   wire:navigate
                   class="inline-flex items-center gap-1.5 mt-4 text-sm text-primary hover:underline">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">add</span>
                    {{ __('games.action_submit_first_request') }}
                </a>
            </div>
        @endif
    </div>
</div>

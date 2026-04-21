@php
    $notifications = $this->notifications;
    $unreadCount = $this->unreadCount;
@endphp

<div class="py-8" wire:poll.60s="refreshNotifications">
    <div class="max-w-3xl mx-auto space-y-6">

        {{-- Page Header --}}
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">
                <span class="material-symbols-outlined text-2xl align-middle mr-1" style="font-variation-settings: 'FILL' 1">notifications</span>
                {{ __('notifications.page_title') }}
            </h1>
            @if($unreadCount > 0)
                <button
                    wire:click="markAllRead"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium bg-primary/10 text-primary hover:bg-primary/20 transition-colors"
                >
                    <span class="material-symbols-outlined text-base" wire:loading.remove>done_all</span>
                    <span wire:loading.remove>{{ __('notifications.action_mark_all_read') }}</span>
                    <span wire:loading class="flex items-center gap-1.5">
                        <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        {{ __('common.action_loading') }}
                    </span>
                </button>
            @endif
        </div>

        {{-- Notification Groups --}}
        <div class="space-y-3">
            @forelse($notifications as $group)
                <div class="bg-surface-container-lowest rounded-xl shadow-ambient overflow-hidden {{ ! $group->is_read ? 'border-l-4 border-primary' : '' }}" wire:key="group-{{ $group->group_key }}-{{ $group->latest->id }}">
                    <div class="flex items-start gap-4 p-4">
                        {{-- Unread indicator --}}
                        <div class="flex-shrink-0 mt-1">
                            @if(! $group->is_read)
                                <span class="block w-2.5 h-2.5 rounded-full bg-primary"></span>
                            @else
                                <span class="block w-2.5 h-2.5 rounded-full bg-outline-variant/30"></span>
                            @endif
                        </div>

                        {{-- Content --}}
                        <div class="flex-1 min-w-0">
                            {{-- Display string --}}
                            <p class="text-sm {{ $group->is_read ? 'text-on-surface-variant' : 'text-on-surface font-medium' }} leading-snug">
                                {{ $group->display_string }}
                            </p>

                            {{-- Meta row --}}
                            <div class="flex items-center gap-3 mt-1.5">
                                <span class="text-xs text-on-surface-variant">{{ $group->latest->created_at->diffForHumans() }}</span>
                                @if($group->count > 1)
                                    <span class="text-xs text-on-surface-variant/60">&middot; {{ $group->count }} {{ __('notifications.label_notifications') }}</span>
                                @endif
                                @if(! $group->is_read)
                                    <button
                                        wire:click="markAsRead('{{ $group->group_key }}')"
                                        wire:loading.attr="disabled"
                                        class="text-xs font-medium text-primary hover:text-primary/80 transition-colors"
                                    >
                                        {{ __('notifications.action_mark_read') }}
                                    </button>
                                @endif
                            </div>
                        </div>

                        {{-- Expand/collapse toggle --}}
                        @if($group->count > 1)
                            <button
                                wire:click="toggleGroup('{{ $group->group_key }}')"
                                class="flex-shrink-0 p-1 rounded-lg text-on-surface-variant hover:bg-surface-container-high hover:text-primary transition-colors"
                                aria-label="{{ isset($expandedGroups[$group->group_key]) ? 'Collapse' : 'Expand' }} notification group"
                            >
                                <span class="material-symbols-outlined text-lg transition-transform {{ isset($expandedGroups[$group->group_key]) ? 'rotate-180' : '' }}">expand_more</span>
                            </button>
                        @endif
                    </div>

                    {{-- Expanded individual items --}}
                    @if($group->count > 1 && isset($expandedGroups[$group->group_key]))
                        <div class="border-t border-outline-variant/10 bg-surface-container-low/50">
                            @php
                                // Fetch individual notifications for this group
                                $individuals = $this->authUser->notifications()
                                    ->where('type', $group->full_type)
                                    ->whereDate('created_at', \Carbon\Carbon::parse($group->created_at->toDateString()))
                                    ->orderBy('created_at', 'desc')
                                    ->get();
                            @endphp
                            @foreach($individuals as $notification)
                                <div class="flex items-start gap-3 px-4 py-2.5 {{ ! $loop->last ? 'border-b border-outline-variant/5' : '' }} {{ $notification->read_at ? 'opacity-60' : '' }}">
                                    <span class="mt-1 flex-shrink-0 w-1.5 h-1.5 rounded-full {{ $notification->read_at ? 'bg-transparent' : 'bg-primary' }}"></span>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-xs text-on-surface-variant">{{ $notification->created_at->diffForHumans() }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <div class="flex flex-col items-center justify-center py-16 text-center">
                    <span class="material-symbols-outlined text-5xl text-on-surface-variant/30 mb-3">notifications_off</span>
                    <p class="text-sm text-on-surface-variant">{{ __('notifications.empty_state') }}</p>
                </div>
            @endforelse
        </div>

        {{-- Pagination --}}
        @if($notifications->hasMorePages())
            <div class="flex justify-center pt-4">
                {{ $notifications->links() }}
            </div>
        @endif
    </div>
</div>

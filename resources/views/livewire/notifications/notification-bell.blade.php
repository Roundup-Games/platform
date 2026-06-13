@php
    $notifications = $this->recentNotifications;
@endphp

<div
    x-data="{
        open: false,
        dropdownStyle: {},
        updatePosition() {
            const btn = this.$refs.button;
            if (!btn) return;
            const rect = btn.getBoundingClientRect();
            this.dropdownStyle = {
                position: 'fixed',
                top: (rect.bottom + 8) + 'px',
                left: Math.max(8, rect.left - 40) + 'px',
                width: '320px',
            };
        }
    }"
    @click.away="open = false"
    @keydown.escape="open = false"
    class="relative"
    wire:poll.30s="refreshUnreadCount"
>
    {{-- Bell Button --}}
    <button
        x-ref="button"
        @click="open = !open; if (open) updatePosition()"
        class="relative flex items-center gap-3 w-full px-4 py-3 rounded-xl text-sm transition-all duration-200 text-on-surface-variant hover:bg-surface-container-high hover:text-primary font-medium"
        aria-label="{{ __('notifications.bell_label', ['count' => $unreadCount]) }}"
        aria-haspopup="true"
        :aria-expanded="open.toString()"
    >
        <span class="material-symbols-outlined text-lg">notifications</span>
        <span class="hidden xl:inline">{{ __('notifications.nav_label') }}</span>
        @if($unreadCount > 0)
            <span class="{{ request()->routeIs('notifications.*') ? 'hidden' : '' }} absolute top-1 {{ auth()->user() ? 'left-7' : 'left-7' }} bg-error text-on-surface-container-lowest text-xs rounded-full min-w-5 h-5 flex items-center justify-center px-1 font-semibold leading-none">
                {{ $unreadCount > 99 ? '99+' : $unreadCount }}
            </span>
        @endif
    </button>

    {{-- Dropdown Panel --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        x-cloak
        :style="dropdownStyle"
        class="bg-surface-container-lowest rounded-xl shadow-lg border border-outline-variant/15 z-50 overflow-hidden"
        role="menu"
    >
        {{-- Header --}}
        <div class="flex items-center justify-between px-4 py-3 border-b border-outline-variant/15">
            <h3 class="text-sm font-bold text-on-surface">{{ __('notifications.dropdown_heading') }}</h3>
            @if($unreadCount > 0)
                <button
                    wire:click="markAllRead"
                    wire:loading.attr="disabled"
                    class="text-xs font-medium text-primary hover:text-primary/80 transition-colors"
                >
                    {{ __('notifications.action_mark_all_read') }}
                </button>
            @endif
        </div>

        {{-- Notification List --}}
        <div class="max-h-96 overflow-y-auto divide-y divide-outline-variant/10">
            @forelse($notifications as $group)
                <button
                    wire:click="markAsRead('{{ $group->groupKey }}')"
                    class="w-full text-left px-4 py-3 hover:bg-surface-container-high transition-colors {{ $group->isRead ? 'opacity-60' : '' }}"
                    role="menuitem"
                >
                    <div class="flex items-start gap-3">
                        {{-- Unread indicator --}}
                        <span class="mt-1.5 shrink-0 w-2 h-2 rounded-full {{ $group->isRead ? 'bg-transparent' : 'bg-primary' }}"></span>

                        <div class="flex-1 min-w-0">
                            {{-- Display string --}}
                            <p class="text-sm text-on-surface leading-snug line-clamp-2">{!! $group->displayHtml !!}</p>

                            {{-- Meta row: time ago + count --}}
                            <div class="flex items-center gap-2 mt-1">
                                <span class="text-xs text-on-surface-variant">{{ $group->latest->created_at->diffForHumans() }}</span>
                                @if($group->count > 1)
                                    <span class="text-xs text-on-surface-variant/60">&middot; {{ $group->count }} {{ __('notifications.label_notifications') }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </button>
            @empty
                <div class="px-4 py-8 text-center">
                    <span class="material-symbols-outlined text-3xl text-on-surface-variant/40 mb-2 block">notifications_off</span>
                    <p class="text-sm text-on-surface-variant">{{ __('notifications.empty_state') }}</p>
                </div>
            @endforelse
        </div>

        {{-- Footer --}}
        <div class="border-t border-outline-variant/15 px-4 py-3">
            <a
                href="{{ route('notifications.index') }}"
                wire:navigate
                @click="open = false"
                class="block text-center text-sm font-medium text-primary hover:text-primary/80 transition-colors"
            >
                {{ __('notifications.action_view_all') }}
            </a>
        </div>
    </div>
</div>

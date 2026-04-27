@props([])

@php
    // Pre-Alpine fallback: if rendered while offline (SSR can't know, but JS bridge sets data-network)
    // The [data-network="offline"] selector provides immediate visibility before Alpine init.
@endphp
<style>
    /* Pre-Alpine: hide online-state banners by default */
    [x-data="offlineIndicator()"] [data-offline-banner] { display: none; }
    [x-data="offlineIndicator()"] [data-online-flash]  { display: none; }
    /* Pre-Alpine: show offline banner immediately if bridge set data-network=offline */
    [x-data="offlineIndicator()"][data-network="offline"] [data-offline-banner] {
        display: flex !important;
    }
</style>

<div
    x-data="offlineIndicator()"
    x-init="init()"
    wire:ignore
    class="fixed bottom-0 inset-x-0 z-50 pointer-events-none"
    role="status"
    aria-live="polite"
>
    {{-- Offline banner --}}
    <div
        data-offline-banner
        x-show="!isOnline && !flashOnline"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 translate-y-full"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-full"
        class="bg-surface-container-highest border-t border-outline-variant/20 px-4 py-2.5 flex items-center justify-center gap-2 pointer-events-auto"
    >
        <span class="material-symbols-outlined text-lg text-on-surface-variant" aria-hidden="true">cloud_off</span>
        <span class="text-sm text-on-surface-variant">{{ __('pwa.offline_indicator') }}</span>
    </div>

    {{-- Back-online flash --}}
    <div
        data-online-flash
        x-show="flashOnline"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 translate-y-full"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-500"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-full"
        class="bg-primary/10 border-t border-primary/20 px-4 py-2.5 flex items-center justify-center gap-2 pointer-events-auto"
    >
        <span class="material-symbols-outlined text-lg text-primary" aria-hidden="true">cloud_done</span>
        <span class="text-sm font-medium text-primary">{{ __('pwa.back_online') }}</span>
    </div>
</div>

<script>
function offlineIndicator() {
    return {
        isOnline: navigator.onLine,
        flashOnline: false,

        init() {
            // Clear pre-Alpine fallback state once Alpine takes over
            const el = this.$el;
            if (el) el.removeAttribute('data-network');

            window.addEventListener('offline', () => {
                this.isOnline = false;
                this.flashOnline = false;
            });

            window.addEventListener('online', () => {
                this.isOnline = true;
                this.flashOnline = true;

                // Auto-hide the "back online" flash after 2.5s
                setTimeout(() => {
                    this.flashOnline = false;
                }, 2500);
            });
        },
    };
}
</script>

<div x-data="{ visible: @js($showNotice) }"
     x-show="visible"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-y-4"
     x-transition:enter-end="opacity-100 translate-y-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100 translate-y-0"
     x-transition:leave-end="opacity-0 translate-y-4"
     class="fixed bottom-4 left-1/2 -translate-x-1/2 z-50 w-full max-w-lg"
     x-cloak>
    <div class="bg-surface-container-high border border-outline-variant/20 rounded-2xl shadow-xl p-5 mx-4">
        <div class="flex items-start gap-3">
            <span class="material-symbols-outlined text-primary text-xl mt-0.5 shrink-0" aria-hidden="true">gavel</span>
            <div class="flex-1 min-w-0">
                <h3 class="font-heading text-sm font-semibold text-on-surface">{{ __('common.heading_policy_update') }}</h3>
                <p class="mt-1 text-sm text-on-surface-variant">{{ __('common.content_policy_update_body') }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                    <a href="{{ route('privacy') }}" target="_blank" rel="noopener noreferrer" class="text-primary hover:underline font-medium">{{ __('common.content_policy_update_privacy_link') }}</a>
                    <span class="text-on-surface-variant/40" aria-hidden="true">·</span>
                    <a href="{{ route('terms') }}" target="_blank" rel="noopener noreferrer" class="text-primary hover:underline font-medium">{{ __('common.content_policy_update_terms_link') }}</a>
                </div>
                <div class="mt-3 flex items-center gap-3">
                    <button
                        type="button"
                        wire:click="accept"
                        x-on:click="visible = false"
                        class="px-4 py-2 text-sm font-semibold rounded-xl bg-primary text-on-primary hover:bg-primary/90 transition-colors focus:outline-none focus:ring-2 focus:ring-primary/50"
                    >
                        {{ __('common.action_accept') }}
                    </button>
                    <button
                        type="button"
                        wire:click="dismiss"
                        x-on:click="visible = false"
                        class="px-4 py-2 text-sm font-medium rounded-xl text-on-surface-variant hover:bg-surface-container-highest transition-colors focus:outline-none focus:ring-2 focus:ring-primary/50"
                    >
                        {{ __('common.action_dismiss') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

@props(['hasShareLink', 'shareLinkUrl'])

<div x-data="{
    copied: false,
    copyToClipboard() {
        if (! this.$refs.shareUrl) return;
        const url = this.$refs.shareUrl.textContent.trim();
        window.navigator.clipboard.writeText(url).then(() => {
            this.copied = true;
            setTimeout(() => { this.copied = false; }, 2000);
        });
    }
}">
    {{-- Header --}}
    <h3 class="text-base font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
        <span class="material-symbols-outlined text-lg" aria-hidden="true">link</span>
        {{ __('common.title_share_link') }}
    </h3>

    @if($hasShareLink && $shareLinkUrl)
        {{-- Active link state --}}
        <div class="space-y-3">
            <div class="flex items-center gap-2 p-3 bg-surface-container-high rounded-lg">
                <span class="material-symbols-outlined text-lg text-primary shrink-0" aria-hidden="true">link</span>
                <span x-ref="shareUrl" class="font-mono text-xs break-all text-on-surface flex-1 min-w-0 truncate">{{ $shareLinkUrl }}</span>
            </div>

            <div class="flex flex-wrap gap-2">
                {{-- Copy button --}}
                <button @click="copyToClipboard()"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-primary text-on-primary hover:opacity-90 transition-opacity"
                    :class="{ 'bg-secondary-container text-on-secondary-container': copied }">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true" x-show="!copied">content_copy</span>
                    <span class="material-symbols-outlined text-sm" aria-hidden="true" x-show="copied">check</span>
                    <span x-text="copied ? '{{ __('common.status_copied') }}' : '{{ __('common.action_copy_link') }}'"></span>
                </button>

                {{-- Regenerate button --}}
                <x-confirm-action
                    action="regenerateShareLink"
                    id="regenerate-share-link"
                    :icon="'refresh'"
                    :trigger-label="__('common.action_regenerate_link')"
                    trigger-class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-surface-container-high text-on-surface-variant hover:text-on-surface transition-colors"
                    :confirm-label="__('common.action_regenerate_link')"
                    :cancel-label="__('common.action_cancel')"
                    :message="__('common.confirmation_regenerate_link')"
                    variant="inline"
                    severity="caution"
                    confirm-icon="refresh"
                />

                {{-- Revoke button --}}
                <x-confirm-action
                    action="revokeShareLink"
                    id="revoke-share-link"
                    :icon="'link_off'"
                    :trigger-label="__('common.action_revoke_link')"
                    trigger-class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-error-container text-on-error-container hover:opacity-90 transition-opacity"
                    :confirm-label="__('common.action_revoke_link')"
                    :cancel-label="__('common.action_keep')"
                    :message="__('common.confirmation_revoke_link')"
                    variant="inline"
                    severity="destructive"
                    confirm-icon="link_off"
                />
            </div>
        </div>
    @else
        {{-- No active link state --}}
        <p class="text-sm text-on-surface-variant mb-3">{{ __('common.description_share_link') }}</p>
        <button wire:click="generateShareLink"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-primary text-on-primary hover:opacity-90 transition-opacity">
            <span class="material-symbols-outlined text-sm" aria-hidden="true">add_link</span>
            {{ __('common.action_generate_link') }}
        </button>
    @endif
</div>

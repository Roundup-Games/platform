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
        {{ __('common.share_link_title') }}
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
                    <span x-text="copied ? '{{ __('common.share_link_copied') }}' : '{{ __('common.share_link_copy') }}'"></span>
                </button>

                {{-- Regenerate button --}}
                <button wire:click="regenerateShareLink"
                    wire:confirm="{{ __('common.share_link_confirm_regenerate') }}"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-surface-container-high text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">refresh</span>
                    {{ __('common.share_link_regenerate') }}
                </button>

                {{-- Revoke button --}}
                <button wire:click="revokeShareLink"
                    wire:confirm="{{ __('common.share_link_confirm_revoke') }}"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-error-container text-on-error-container hover:opacity-90 transition-opacity">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">link_off</span>
                    {{ __('common.share_link_revoke') }}
                </button>
            </div>
        </div>
    @else
        {{-- No active link state --}}
        <p class="text-sm text-on-surface-variant mb-3">{{ __('common.share_link_description') }}</p>
        <button wire:click="generateShareLink"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-primary text-on-primary hover:opacity-90 transition-opacity">
            <span class="material-symbols-outlined text-sm" aria-hidden="true">add_link</span>
            {{ __('common.share_link_generate') }}
        </button>
    @endif
</div>

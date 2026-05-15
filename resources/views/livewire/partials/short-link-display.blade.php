@props(['shortLinks', 'canCreateMoreShortLinks'])

@php
    $shortLinkBaseUrl = url('/link');
@endphp

<div x-data="{
    copiedId: null,
    showCreateForm: false,
    newLabel: '',
    copying: false,
    copyToClipboard(linkId, url) {
        if (this.copying) return;
        this.copying = true;
        window.navigator.clipboard.writeText(url).then(() => {
            this.copiedId = linkId;
            setTimeout(() => { this.copiedId = null; this.copying = false; }, 2000);
        }).catch(() => { this.copying = false; });
    }
}">
    {{-- Header --}}
    <h3 class="text-base font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
        <span class="material-symbols-outlined text-lg" aria-hidden="true">link</span>
        {{ __('common.title_share_link') }}
    </h3>

    @if($shortLinks->count())
        {{-- Active short links list --}}
        <div class="space-y-3">
            @foreach($shortLinks as $link)
                @php
                    $fullUrl = $shortLinkBaseUrl . '/' . $link->code;
                    $maskedCode = substr($link->code, 0, 3) . '••••';
                @endphp
                <div class="flex items-center gap-3 p-3 bg-surface-container-high rounded-lg">
                    <span class="material-symbols-outlined text-lg text-primary shrink-0" aria-hidden="true">link</span>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="font-mono text-xs text-on-surface-variant">{{ $maskedCode }}</span>
                            @if($link->label)
                                <span class="text-xs text-on-surface-variant">· {{ $link->label }}</span>
                            @endif
                        </div>
                        <div class="flex items-center gap-2 mt-0.5">
                            <span class="font-mono text-xs break-all text-on-surface truncate">{{ $fullUrl }}</span>
                        </div>
                        <div class="flex items-center gap-3 mt-1 text-xs text-on-surface-variant">
                            <span>{{ $link->hit_count }} {{ trans_choice('common.content_hits', $link->hit_count) }}</span>
                            @if($link->created_at)
                                <span>{{ $link->created_at->isoFormat('MMM D') }}</span>
                            @endif
                        </div>
                    </div>

                    {{-- Copy button --}}
                    <button @click="copyToClipboard('{{ $link->id }}', '{{ $fullUrl }}')"
                        class="shrink-0 inline-flex items-center justify-center w-8 h-8 rounded-lg transition-colors"
                        :class="copiedId === '{{ $link->id }}' ? 'bg-secondary-container text-on-secondary-container' : 'text-on-surface-variant hover:bg-surface-container-high'"
                        :title="copiedId === '{{ $link->id }}' ? '{{ __('common.status_copied') }}' : '{{ __('common.action_copy_link') }}'">
                        <span class="material-symbols-outlined text-sm" aria-hidden="true" x-show="copiedId !== '{{ $link->id }}'">content_copy</span>
                        <span class="material-symbols-outlined text-sm" aria-hidden="true" x-show="copiedId === '{{ $link->id }}'">check</span>
                    </button>

                    {{-- Revoke button --}}
                    <button wire:click="revokeShortLink({{ $link->id }})"
                        wire:confirm="{{ __('common.confirmation_revoke_link') }}"
                        class="shrink-0 inline-flex items-center justify-center w-8 h-8 rounded-lg text-on-surface-variant hover:bg-error-container hover:text-on-error-container transition-colors"
                        title="{{ __('common.action_revoke_link') }}">
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">link_off</span>
                    </button>
                </div>
            @endforeach
        </div>
    @else
        {{-- No active links --}}
        <p class="text-sm text-on-surface-variant mb-3">{{ __('common.description_share_link') }}</p>
    @endif

    {{-- Create new link --}}
    @if($canCreateMoreShortLinks)
        <div class="mt-4 space-y-2">
            <div x-show="!showCreateForm" x-transition>
                <button @click="showCreateForm = true; $nextTick(() => $refs.labelInput?.focus())"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-primary text-on-primary hover:opacity-90 transition-opacity">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">add_link</span>
                    {{ __('common.action_generate_link') }}
                </button>
            </div>

            <div x-show="showCreateForm" x-transition class="flex items-center gap-2">
                <input type="text"
                    x-ref="labelInput"
                    x-model="newLabel"
                    placeholder="{{ __('common.placeholder_link_label') }}"
                    class="flex-1 min-w-0 px-3 py-1.5 text-sm rounded-lg bg-surface-container-high text-on-surface border border-outline-variant focus:border-primary focus:outline-none"
                    @keydown.enter="showCreateForm = false; $wire.createShortLink(newLabel || null).then(() => { newLabel = '' })"
                    @keydown.escape="showCreateForm = false; newLabel = ''" />
                <button @click="showCreateForm = false; $wire.createShortLink(newLabel || null).then(() => { newLabel = '' })"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-primary text-on-primary hover:opacity-90 transition-opacity">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">check</span>
                    {{ __('common.action_create') }}
                </button>
                <button @click="showCreateForm = false; newLabel = ''"
                    class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-on-surface-variant hover:bg-surface-container-high transition-colors">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">close</span>
                </button>
            </div>
        </div>
    @elseif($shortLinks->count())
        {{-- At limit --}}
        <p class="mt-3 text-xs text-on-surface-variant italic">{{ __('common.error_max_links_reached') }}</p>
    @endif
</div>

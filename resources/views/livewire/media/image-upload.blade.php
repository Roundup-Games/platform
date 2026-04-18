@php
    $currentMedia = $this->currentMedia;
    $hasMedia = $this->hasMedia;
@endphp

<div x-data="{
    dragging: false,
    file: null,
    preview: null,

    handleDrop(event) {
        this.dragging = false;
        const files = event.dataTransfer.files;
        if (files.length > 0) {
            $wire.image = files[0];
            this.preview = URL.createObjectURL(files[0]);
        }
    },

    handleFileSelect(event) {
        const files = event.target.files;
        if (files.length > 0) {
            this.preview = URL.createObjectURL(files[0]);
        }
    }
}"
     x-on:dragover.prevent="dragging = true"
     x-on:dragleave.prevent="dragging = false"
     x-on:drop.prevent="handleDrop($event)"
     class="space-y-3">

    {{-- Flash Message --}}
    @if($message)
        <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 4000)"
             class="rounded-lg @if($messageType === 'success') bg-secondary-container text-on-secondary-container @else bg-error-container text-on-error-container @endif p-3">
            <p class="text-sm">{{ $message }}</p>
        </div>
    @endif

    {{-- Label --}}
    <label class="block text-sm font-medium text-on-surface">
        {{ $label }}
    </label>

    {{-- Current Image --}}
    @if($hasMedia && $currentMedia)
        <div class="relative group">
            <img src="{{ $currentMedia->getUrl() }}"
                 alt="{{ $label }}"
                 class="w-full max-w-xs rounded-lg object-cover border border-outline-variant/30
                        @if($collection === 'banner') aspect-[2/1] @else aspect-square max-w-32 @endif" />

            {{-- Overlay with remove button --}}
            <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity rounded-lg flex items-center justify-center">
                <button wire:click="remove" wire:loading.attr="disabled"
                        class="px-3 py-1.5 bg-error text-on-error rounded-md text-sm font-medium hover:brightness-110 transition-colors">
                    {{ __('common.action_remove') }}
                </button>
            </div>
        </div>
    @endif

    {{-- Upload Area --}}
    <div class="relative"
         x-bind:class="{ 'border-primary bg-primary/5 dark:bg-primary/10': dragging,
                         'border-outline-variant border-dashed': !dragging }"
         class="border-2 rounded-lg p-6 text-center transition-colors">

        {{-- Hidden file input --}}
        <input type="file"
               wire:model="image"
               accept="{{ $accept }}"
               class="hidden"
               id="image-upload-{{ $collection }}-{{ $model_id }}"
               x-on:change="handleFileSelect($event)" />

        <div x-show="!preview && !$wire.image" class="space-y-2">
            {{-- Drag & drop prompt --}}
            <span class="material-symbols-outlined text-3xl text-on-surface-variant" aria-hidden="true">cloud_upload</span>
            <p class="text-sm text-on-surface-variant">
                {{ __('common.content_drag_and_drop_or') }}
                <label for="image-upload-{{ $collection }}-{{ $model_id }}"
                       class="text-primary hover:text-primary-container cursor-pointer font-medium transition-colors">
                    {{ __('discovery.action_browse') }}
                </label>
            </p>
            @if($dimensionHint)
                <p class="text-xs text-on-surface-variant/70">{{ $dimensionHint }}</p>
            @endif
            <p class="text-xs text-on-surface-variant/70">{{ __('common.content_jpg_png_gif_or_webp_max_sizemb', ['size' => number_format($maxSize / 1024, 0)]) }}</p>
        </div>

        {{-- Preview --}}
        <div x-show="preview || $wire.image" class="space-y-3">
            <template x-if="preview">
                <img x-bind:src="preview" alt="{{ __('common.content_preview') }}"
                     class="mx-auto max-h-48 rounded-lg object-contain" />
            </template>

            @if($image)
                @if($image->isPreviewable())
                    <img src="{{ $image->temporaryUrl() }}" alt="{{ __('common.content_preview') }}"
                         class="mx-auto max-h-48 rounded-lg object-contain" />
                @endif
                <p class="text-xs text-on-surface-variant">{{ $image->getFilename() }}</p>
            @endif
        </div>
    </div>

    {{-- Validation Errors --}}
    @error('image')
        <p class="text-sm text-error">{{ $message }}</p>
    @enderror

    {{-- Upload Button --}}
    @if($image)
        <div class="flex items-center gap-3">
            <button wire:click="upload" wire:loading.attr="disabled"
                    class="px-4 py-2 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-95 transition-all text-sm font-medium">
                <span wire:loading.remove>{{ __('common.action_upload_label', ['label' => $label]) }}</span>
                <span wire:loading>{{ __('common.content_uploading') }}</span>
            </button>
            <button wire:click="$set('image', null)"
                    class="px-4 py-2 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                {{ __('common.action_cancel') }}
            </button>
        </div>
    @endif
</div>

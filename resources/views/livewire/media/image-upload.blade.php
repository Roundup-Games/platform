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
             class="rounded-md @if($messageType === 'success') bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-300 @else bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 @endif p-3">
            <p class="text-sm">{{ $message }}</p>
        </div>
    @endif

    {{-- Label --}}
    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
        {{ $label }}
    </label>

    {{-- Current Image --}}
    @if($hasMedia && $currentMedia)
        <div class="relative group">
            <img src="{{ $currentMedia->getUrl() }}"
                 alt="{{ $label }}"
                 class="w-full max-w-xs rounded-lg object-cover border border-gray-200 dark:border-gray-600
                        @if($collection === 'banner') aspect-[2/1] @else aspect-square max-w-32 @endif" />

            {{-- Overlay with remove button --}}
            <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity rounded-lg flex items-center justify-center">
                <button wire:click="remove" wire:loading.attr="disabled"
                        class="px-3 py-1.5 bg-red-600 text-white rounded-md text-sm font-medium hover:bg-red-700 transition-colors">
                    Remove
                </button>
            </div>
        </div>
    @endif

    {{-- Upload Area --}}
    <div class="relative"
         x-bind:class="{ 'border-[#C12E26] bg-[#C12E26]/5 dark:bg-[#C12E26]/10': dragging,
                         'border-gray-300 dark:border-gray-600 border-dashed': !dragging }"
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
            <svg aria-hidden="true" class="mx-auto h-10 w-10 text-gray-400 dark:text-gray-500" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02"
                      stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Drag and drop or
                <label for="image-upload-{{ $collection }}-{{ $model_id }}"
                       class="text-[#C12E26] hover:text-[#9A231F] cursor-pointer font-medium transition-colors">
                    browse
                </label>
            </p>
            @if($dimensionHint)
                <p class="text-xs text-gray-400 dark:text-gray-500">{{ $dimensionHint }}</p>
            @endif
            <p class="text-xs text-gray-400 dark:text-gray-500">JPG, PNG, GIF, or WebP. Max {{ number_format($maxSize / 1024, 0) }}MB.</p>
        </div>

        {{-- Preview --}}
        <div x-show="preview || $wire.image" class="space-y-3">
            <template x-if="preview">
                <img x-bind:src="preview" alt="Preview"
                     class="mx-auto max-h-48 rounded-lg object-contain" />
            </template>

            @if($image)
                @if($image->isPreviewable())
                    <img src="{{ $image->temporaryUrl() }}" alt="Preview"
                         class="mx-auto max-h-48 rounded-lg object-contain" />
                @endif
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $image->getFilename() }}</p>
            @endif
        </div>
    </div>

    {{-- Validation Errors --}}
    @error('image')
        <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
    @enderror

    {{-- Upload Button --}}
    @if($image)
        <div class="flex items-center gap-3">
            <button wire:click="upload" wire:loading.attr="disabled"
                    class="px-4 py-2 bg-[#C12E26] text-white rounded-lg hover:bg-[#9A231F] transition-colors text-sm font-medium">
                <span wire:loading.remove>Upload {{ $label }}</span>
                <span wire:loading>Uploading...</span>
            </button>
            <button wire:click="$set('image', null)"
                    class="px-4 py-2 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 text-sm transition-colors">
                Cancel
            </button>
        </div>
    @endif
</div>

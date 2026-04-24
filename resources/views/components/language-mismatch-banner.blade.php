@props([
    'entityLanguage',
])

@php
$uiLocale = app()->getLocale();
$show = $entityLanguage && $entityLanguage !== $uiLocale;
@endphp

@if($show)
    <div
        x-data="{ visible: true }"
        x-show="visible"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        role="alert"
        class="flex items-center gap-3 px-4 py-3 bg-surface-container-high rounded-xl text-on-surface"
    >
        <span class="material-symbols-outlined text-primary text-xl shrink-0" aria-hidden="true">translate</span>
        <p class="text-sm flex-1">
            {{ __('common.content_language_mismatch_banner', ['language' => \App\Enums\ContentLanguage::from($entityLanguage)->label()]) }}
        </p>
        <button
            type="button"
            x-on:click="visible = false"
            class="shrink-0 p-1 rounded-lg text-on-surface-variant hover:bg-on-surface/5 transition-colors"
            aria-label="{{ __('common.action_close') }}"
        >
            <span class="material-symbols-outlined text-xl" aria-hidden="true">close</span>
        </button>
    </div>
@endif

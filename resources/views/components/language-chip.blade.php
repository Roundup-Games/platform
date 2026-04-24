@props([
    'language',
])

@php
$langEnum = App\Enums\ContentLanguage::tryFrom($language);
@endphp

@if($langEnum)
    <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container-high text-on-surface-variant">
        <span class="material-symbols-outlined text-xs" aria-hidden="true">translate</span>
        {{ strtoupper($language) }}
    </span>
@endif

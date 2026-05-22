@props([
    'field',          // string — the translatable field name (e.g. 'name', 'description')
    'baselineLocale', // string — the baseline locale code (e.g. 'en')
    'activeLocale' => '', // string — currently active locale, passed from parent Livewire component
])

@php
$currentLocale = $activeLocale ?: $baselineLocale;
$showCopyButton = $currentLocale !== $baselineLocale;
@endphp

@if ($showCopyButton)
    <button
        type="button"
        wire:click="copyFromBaseline('{{ $field }}')"
        aria-label="{{ __('common.action_copy_from_baseline', ['field' => ucfirst($field)]) }}"
        title="{{ __('common.action_copy_from_baseline', ['field' => ucfirst($field)]) }}"
        class="inline-flex items-center gap-1 text-xs text-on-surface-variant hover:text-primary transition-colors duration-150 mt-1"
    >
        <span class="material-symbols-outlined text-sm" aria-hidden="true">content_copy</span>
        <span>{{ __('common.action_copy_from_baseline_short') }}</span>
    </button>
@endif

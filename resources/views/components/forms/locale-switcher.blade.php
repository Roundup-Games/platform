@props([
    'locales',        // string[] — all locales (baseline first, then secondaries)
    'baselineLocale', // string  — the baseline locale code (e.g. 'en')
    'activeLocale',   // string  — currently active locale
])

@php
$currentLocale = $activeLocale ?: $baselineLocale;
@endphp

<div class="flex items-center gap-1.5 mb-4" role="tablist" aria-label="{{ __('common.content_translations') }}">
    @foreach ($locales as $locale)
        @php
            $isBaseline = $locale === $baselineLocale;
            $isActive = $locale === $currentLocale;
            $label = \App\Enums\ContentLanguage::tryFrom($locale)?->label() ?? strtoupper($locale);
        @endphp

        <button
            type="button"
            role="tab"
            aria-selected="{{ $isActive ? 'true' : 'false' }}"
            wire:click="switchLocale('{{ $locale }}')"
            {{ $isActive ? 'aria-current="true"' : '' }}
            class="px-3 py-1.5 rounded-lg text-sm font-medium transition-all duration-150 {{
                $isActive
                    ? ($isBaseline ? 'bg-primary text-on-primary shadow-xs' : 'bg-secondary text-on-secondary shadow-xs')
                    : 'bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest'
            }}"
        >
            {{ $label }}
        </button>
    @endforeach
</div>

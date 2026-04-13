@props(['active'])

@php
$classes = ($active ?? false)
            ? 'flex items-center gap-3 px-4 py-3 bg-surface-container-lowest dark:bg-[#2a2b24] text-primary rounded-xl font-bold text-sm transition-all duration-200'
            : 'flex items-center gap-3 px-4 py-3 text-on-surface-variant hover:bg-surface-container-high dark:hover:bg-[#2a2b24] hover:text-primary rounded-xl font-medium text-sm transition-all duration-200';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>

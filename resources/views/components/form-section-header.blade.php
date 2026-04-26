@props(['number', 'title', 'icon'])
<div class="flex items-center gap-2.5 mb-5">
    <span class="flex items-center justify-center w-7 h-7 rounded-full bg-primary text-on-primary text-xs font-bold">{{ $number }}</span>
    <span class="material-symbols-outlined text-lg text-on-surface-variant" aria-hidden="true">{{ $icon }}</span>
    <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface">{{ $title }}</h2>
</div>

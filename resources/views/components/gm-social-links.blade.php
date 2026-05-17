@props([
    'links',
])

@php
    $platforms = config('platforms', []);
@endphp

@if($links->isNotEmpty())
    <div class="mt-4">
        <p class="text-sm font-medium text-on-surface mb-2">{{ __('profile.content_find_me_on') }}</p>
        <div class="flex flex-wrap gap-2">
            @foreach($links as $link)
                @php
                    $platformConfig = $platforms[$link->platform] ?? null;
                    $icon = $platformConfig['icon'] ?? 'link';
                    $name = $platformConfig['name'] ?? $link->platform;
                    $url = $link->safe_url;
                @endphp
                @if($url)
                    <a href="{{ $url }}"
                       target="_blank"
                       rel="noopener noreferrer"
                       title="{{ $name }}"
                       class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-surface-container-high text-on-surface-variant hover:bg-primary/10 hover:text-primary transition-colors"
                       aria-label="{{ $name }}">
                        <span class="material-symbols-outlined text-lg" style="font-variation-settings: 'FILL' 0">{{ $icon }}</span>
                    </a>
                @endif
            @endforeach
        </div>
    </div>
@endif

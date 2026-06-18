@php
    // $label is resolved by App\View\Components\DistanceDisplay via
    // LocationDisclosureService::distanceDisplay(). Empty string ⇒ render
    // nothing (blocked viewer / unresolvable location — fail-closed).
@endphp

@if(filled($label))
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-0.5']) }}>
        @if($icon)
            <span class="material-symbols-outlined text-xs" aria-hidden="true">{{ $icon }}</span>
        @endif
        {{ $label }}
    </span>
@endif

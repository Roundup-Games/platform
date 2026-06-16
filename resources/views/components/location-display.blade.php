@php
    // $addressLine is resolved by App\View\Components\LocationDisplay via
    // LocationDisclosureService::addressLevel() (graduated path) or by
    // composing the raw-city fields (events/teams, T06).
    // null ⇒ render nothing (blocked viewer / unresolvable location — fail-closed).
@endphp

@if(filled($addressLine))
    <span {{ $attributes->merge(['class' => 'flex items-center gap-2']) }}>
        @unless($withoutIcon)
            <span class="material-symbols-outlined {{ $iconClass }}" aria-hidden="true">location_on</span>
        @endunless
        {{ $addressLine }}
    </span>
@endif

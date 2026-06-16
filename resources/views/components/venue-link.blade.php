@php
    // $isLinkable / $name / $url are resolved by App\View\Components\VenueLink via
    // LocationDisclosureService::isPublicVenuePage() (the single authority).
    // false ⇒ render nothing (private / unverified / `other` / missing name or
    // slug) — no orphan chip, no name leak (M053/S02/T03).
@endphp

@if($isLinkable)
    <a href="{{ $url }}" wire:navigate
       aria-label="{{ __('venue.action_view_venue', ['name' => $name]) }}"
       class="{{ $class ?? 'hover:underline text-primary' }}">{{ $name }}</a>
@endif

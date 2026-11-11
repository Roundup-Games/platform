@props(['chip'])

@php
    // Game-listing author chip (D237, spec §2.4): the shared x-badge-chip
    // in its listing-card form — size sm, wrapped with a host-anchor title.
    // Listing cards may render far from any host byline, so hover and
    // screen-reader copy must name whose badge this is; name/tier resolve
    // through the same BadgeCopy chain the chip itself renders (never a
    // second convention).
    $definition = $chip->badgeDefinition;
    $name = \App\Services\Badges\BadgeCopy::name($definition->name_key, $definition->key);
    $tierLabel = \App\Services\Badges\BadgeCopy::tierLabel($chip->tier);
@endphp

<span title="{{ __('badges.content_badge_author_chip_title', [
    'badge' => $tierLabel !== null ? $name.' ('.$tierLabel.')' : $name,
]) }}">
    <x-badge-chip :definition="$definition" :tier="$chip->tier" size="sm" />
</span>

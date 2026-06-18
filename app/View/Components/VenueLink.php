<?php

namespace App\View\Components;

use App\Models\Location;
use App\Services\LocationDisclosureService;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * The single venue-name → venue-page link affordance (M053/S02/T03).
 *
 * Renders a location's name as a link to its public venue page — but ONLY when
 * the location is a verified commercial venue (the MEM717 invariant) AND carries
 * a non-empty name AND a non-null slug. Every other location (private home,
 * unverified, `other`, or a verified venue missing its name/slug) renders
 * NOTHING: no orphan element, no chip, no name leak. Because the component emits
 * no markup at all when ineligible, callers may place it inline without an
 * external wrapper — there is never a dangling icon or empty gap to clean up.
 *
 * Eligibility is delegated to
 * {@see LocationDisclosureService::isPublicVenuePage()} — the single named
 * authority — so the "whose name is a link" rule can never drift from the "who
 * gets a public page" rule consumed by the VenueDetail 404 gate and the venues
 * sitemap. Editing that one method (S04 broadens it to admin-managed venues)
 * automatically widens every link affordance with no caller changes.
 *
 * Safety: the venue NAME is brand data, never a private address, so linking it
 * never discloses a private home. Private-location address disclosure stays
 * governed by <x-location-display> (D079); this component only adds the
 * venue-page link where a public page already exists.
 */
class VenueLink extends Component
{
    public readonly bool $isLinkable;

    public readonly ?string $name;

    public readonly ?string $url;

    public function __construct(
        ?Location $location = null,
        public ?string $class = null,
    ) {
        // Order the guards cheapest-first: cheap attribute checks short-circuit
        // before the (container-resolved) authority call. filled() rejects null,
        // '', and whitespace-only names/slugs — a verified venue missing either
        // renders no link rather than a broken/empty anchor.
        $eligible = $location !== null
            && filled($location->name)
            && filled($location->slug)
            && app(LocationDisclosureService::class)->isPublicVenuePage($location);

        $this->isLinkable = $eligible;
        $this->name = $eligible ? $location->name : null;
        $this->url = $eligible
            ? route('venues.detail', ['locale' => app()->getLocale(), 'slug' => $location->slug])
            : null;
    }

    public function render(): View
    {
        return view('components.venue-link');
    }
}

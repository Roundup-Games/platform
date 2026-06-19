<?php

namespace App\View\Components;

use App\Enums\DisclosureLevel;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\Location;
use App\Models\User;
use App\Services\LocationDisclosureService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * The only address-rendering surface in the app (D079).
 *
 * A thin Blade wrapper around {@see LocationDisclosureService::addressLevel()}:
 * no view should ever read a raw address attribute. It supports two paths:
 *
 *  - Graduated path (games/campaigns): pass `$entity` (Game|Campaign) and
 *    `$location`. The service resolves the viewer from the session and renders
 *    the disclosure-governed address line:
 *      None  → renders nothing (blocked viewer / unresolvable location)
 *      Area  → "In your area"
 *      City  → the locality name only
 *      Exact → the full street address
 *
 *  - Raw-city path (events/teams, T06): pass the denormalized city-level
 *    fields (`$venueName`, `$address`, `$city`, `$postalCode`, `$country`).
 *    These entities carry their own locality fields rather than a Location
 *    model with an owner relationship, so there is no relationship graph to
 *    graduate against — the composed locality renders as-is at the City rung.
 *    Centralizing the render here means any future tightening (e.g. verified
 *    event venue handling) lands in ONE authority, with no orphan views.
 *
 * Fail-closed by construction: a null location or an all-empty raw-city set
 * never yields an address here, and the legacy `games.location` JSON column is
 * never read (HIGH-2). ProximityQuery keeps precise distances for retrieval;
 * this component governs only the *displayed* address granularity.
 */
class LocationDisplay extends Component
{
    /**
     * The disclosure-governed address string, or null when nothing should render.
     */
    public readonly ?string $addressLine;

    public function __construct(
        Game|Campaign|null $entity = null,
        ?Location $location = null,
        ?User $viewer = null,
        ?string $venueName = null,
        ?string $address = null,
        ?string $city = null,
        ?string $postalCode = null,
        ?string $country = null,
        public bool $withoutIcon = false,
        public string $iconClass = 'text-lg',
    ) {
        // Resolve the viewer: explicit prop wins, else the authenticated user,
        // else null (guest). The service treats a null viewer as a guest and
        // graduates disclosure down to the Area rung for private locations.
        $resolvedViewer = $viewer ?? Auth::user();

        // Graduated path: a Game|Campaign entity drives relationship-based
        // disclosure via the service. The service handles a null location as
        // fail-closed (None). Events/teams never pass an entity, so they can
        // never accidentally trigger the graduated path — the Game|Campaign
        // union type rejects them by construction.
        if ($entity !== null) {
            $level = app(LocationDisclosureService::class)
                ->addressLevel($location, $resolvedViewer, $entity);

            $this->addressLine = $this->resolveGraduatedLine($level, $location);

            return;
        }

        // Venue-direct path (M053/S02): the public venue page passes a bare
        // Location with no Game|Campaign entity — a venue page is inherently a
        // stranger/public directory view, so disclosure resolves through
        // strangerPreviewLevel(). That yields Exact (fullAddress) for verified
        // commercial venues — the only locations that ever reach this path, since
        // VenueDetail::mount() 404s everything else — and Area (or None) for any
        // anomaly, which renders nothing via resolveGraduatedLine (fail-closed).
        // Keeps the venue page out of raw address attributes entirely.
        if ($location !== null) {
            $level = app(LocationDisclosureService::class)
                ->strangerPreviewLevel($location);

            $this->addressLine = $this->resolveGraduatedLine($level, $location);

            return;
        }

        // Raw-city path (T06): compose the provided city-level fields. A caller
        // may pass a single pre-composed `$city` string (verbatim) or multiple
        // parts (composed here with ", "). filled() drops nulls/empties so a
        // guest with no city data renders nothing (fail-closed).
        $composed = collect([$venueName, $address, $city, $postalCode, $country])
            ->filter(fn ($part) => filled($part))
            ->implode(', ');

        $this->addressLine = $composed !== '' ? $composed : null;
    }

    public function render(): View
    {
        return view('components.location-display');
    }

    private function resolveGraduatedLine(DisclosureLevel $level, ?Location $location): ?string
    {
        return match ($level) {
            DisclosureLevel::Exact => $location?->fullAddress(),
            DisclosureLevel::City => $location?->city,
            // __() is typed string|array|null in Laravel; a found key returns a string,
            // so cast to satisfy the strict string|null return type.
            DisclosureLevel::Area => (string) __('people.label_disclosure_level_area'),
            DisclosureLevel::None => null,
        };
    }
}

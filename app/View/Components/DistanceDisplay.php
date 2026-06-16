<?php

namespace App\View\Components;

use App\Models\Campaign;
use App\Models\Game;
use App\Models\Location;
use App\Models\User;
use App\Services\LocationDisclosureService;
use App\Values\DistanceDisplay as DistanceValue;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * The only distance-rendering surface in the app (D060 / D079).
 *
 * A thin Blade wrapper around {@see LocationDisclosureService::distanceDisplay()}
 * that turns a precise proximity distance into a disclosure-governed display
 * string. No view should ever render a raw `round(...)` distance attribute.
 *
 * Three mutually-exclusive modes (the rule lives in {@see DistanceValue}):
 *
 *  - Default (location + entity): full disclosure decision via the service.
 *      Verified commercial venue → precise km; everything else → D060 grid-snap
 *      ("In your area" when < 5km, else "Nearby — ~N km"); blocked viewer → hidden.
 *  - gridSnap: pure D060 grid-snap without a Location model. Used by cached
 *      dashboard widgets whose serialized arrays cannot carry an Eloquent
 *      Location. Mirrors the service's gridSnap() exactly (people-page parity).
 *  - precise: force a precise km figure. Used ONLY by organizer-facing surfaces
 *      that list verified venues (VenueSearchService), where precise is safe by
 *      construction and the VenueSearchResult DTO carries no Location model.
 *
 * Fail-closed by construction: the service never yields a precise figure for a
 * private/unverified location, so a stranger can never read a sub-5km distance
 * to another user's home here. ProximityQuery keeps precise distances for
 * retrieval/sorting; this component governs only the *displayed* value.
 */
class DistanceDisplay extends Component
{
    public readonly string $label;

    public function __construct(
        public readonly float $preciseKm = 0.0,
        public readonly ?Location $location = null,
        public readonly ?User $viewer = null,
        Game|Campaign|null $entity = null,
        public readonly bool $gridSnap = false,
        public readonly bool $precise = false,
        public readonly string $icon = 'location_on',
    ) {
        $resolvedViewer = $viewer ?? Auth::user();

        $display = $this->precise
            ? DistanceValue::precise($this->preciseKm)
            : ($this->gridSnap
                ? $this->pureGridSnap($this->preciseKm)
                : app(LocationDisclosureService::class)
                    ->distanceDisplay($this->preciseKm, $this->location, $resolvedViewer, $entity));

        $this->label = $display->display();
    }

    public function render(): View
    {
        return view('components.distance-display');
    }

    /**
     * D060 grid-snap without a Location model (cached-widget parity).
     *
     * Mirrors LocationDisclosureService::gridSnap() exactly: round to the
     * nearest 5km with a 5km floor, and flag "In your area" when the viewer is
     * within 5km. The tile-share check is unavailable without a Location, so it
     * is intentionally omitted — this is strictly safer, never less safe.
     */
    private function pureGridSnap(float $preciseKm): DistanceValue
    {
        $bucket = (int) max(5, round($preciseKm / 5) * 5);
        $inArea = $preciseKm < 5;

        return DistanceValue::gridSnapped($bucket, $inArea);
    }
}

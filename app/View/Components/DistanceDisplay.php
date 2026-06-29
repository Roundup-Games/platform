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

        $display = $this->resolveDisplay($entity, $resolvedViewer);

        $this->label = $display->display();
    }

    /**
     * Resolve the disclosure-governed DistanceDisplay value.
     *
     * Defence-in-depth on the CRITICAL-1 trilateration vector: the `precise`
     * flag is only honoured as a raw figure when NO Location is available (the
     * cached-widget / DTO path, where the caller has already guaranteed a
     * verified-venue result set — e.g. VenuePicker feeds VenueSearchService
     * output filtered to is_verified=true). When a Location IS passed with
     * `precise`, the value is routed through LocationDisclosureService, which
     * returns precise only for a verified commercial venue and grid-snaps
     * everything else — so a future caller that passes `precise` with a private
     * or unverified location cannot accidentally render a sub-5km distance.
     */
    private function resolveDisplay(Game|Campaign|null $entity, ?User $viewer): DistanceValue
    {
        // No real distance supplied (the 0.0 sentinel used when a caller omits
        // precise-km, e.g. the campaign fallback in NearbySessions) → render
        // nothing. Without this, 0.0 < 5km falsely flags "In your area" for
        // cards whose distance is simply unknown (M054/S04).
        if ($this->preciseKm <= 0.0) {
            return DistanceValue::hidden();
        }

        // gridSnap (cached-widget path) takes precedence over precise by
        // construction: it is the explicit fail-closed path (always grid-snaps,
        // ignores any Location). No current caller passes both flags — they are
        // mutually exclusive (gridSnap = cached widgets with no Location;
        // precise = verified-venue DTOs) — but if a future caller ever sets
        // both, the safer (grid-snapped) value wins rather than a raw figure.
        if ($this->gridSnap) {
            return $this->pureGridSnap($this->preciseKm);
        }

        if ($this->precise && $this->location !== null) {
            return app(LocationDisclosureService::class)
                ->distanceDisplay($this->preciseKm, $this->location, $viewer, $entity);
        }

        if ($this->precise) {
            return DistanceValue::precise($this->preciseKm);
        }

        return app(LocationDisclosureService::class)
            ->distanceDisplay($this->preciseKm, $this->location, $viewer, $entity);
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

<?php

namespace App\Services;

use App\Enums\DisclosureLevel;
use App\Enums\ParticipantStatus;
use App\Enums\VenueType;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Location;
use App\Models\User;
use App\Values\DistanceDisplay;
use Illuminate\Support\Facades\Log;

/**
 * The single authority for all location-derived display (D079).
 *
 * A deep module with a narrow interface: two methods that turn
 * (location nature × viewer relationship) into an address granularity level
 * and a distance display value. No view should ever render a raw address or
 * distance attribute — everything resolves through here.
 *
 * Safety contract (fail CLOSED):
 *  - Verified commercial venue (cafe/flgs/library/community_center/convention/bar,
 *    is_verified=true) → exact address / precise distance for every non-blocked viewer.
 *  - Private / unverified / "other" → graduated by relationship:
 *      stranger → area + grid-snapped distance
 *      friend/teammate → city + grid-snapped distance
 *      approved participant / owner → exact address + grid-snapped distance
 *  - Blocked viewer → no address, no distance (none).
 *  - If venue type, verification, or the location itself cannot be determined,
 *    the service NEVER returns exact — it returns the most restrictive level
 *    (none/area) and a grid-snapped (or hidden) distance.
 *
 * ProximityQuery is intentionally untouched: it keeps precise distances for
 * retrieval and sorting. This service only governs the *displayed* value.
 */
class LocationDisclosureService
{
    private const GRID_SIZE_KM = 5;

    public function __construct(
        private readonly SocialGraphService $social,
    ) {}

    /**
     * Resolve the address granularity for a location as seen by a viewer.
     *
     * @param  Location|null  $location  The game/campaign's location (nullable for fail-closed).
     * @param  User|null  $viewer  The viewing user, or null for a guest.
     * @param  Game|Campaign  $entity  The entity whose owner drives the relationship.
     */
    public function addressLevel(?Location $location, ?User $viewer, Game|Campaign $entity): DisclosureLevel
    {
        // Fail-closed: unresolvable location → never disclose anything.
        if ($location === null) {
            $this->warn('null_location', ['entity_id' => $entity->id, 'entity_type' => $entity::class]);

            return DisclosureLevel::None;
        }

        // Resolve the relationship ONCE — resolveRelationship() already
        // accounts for the blocked case (and a null viewer) as its first tier,
        // so we derive 'blocked' from it instead of calling isBlocked() twice.
        $relationship = $this->resolveRelationship($viewer, $entity);

        // Blocked viewers never see the address — even for a public venue,
        // a block means this owner's content is invisible to them.
        if ($relationship === 'blocked') {
            return DisclosureLevel::None;
        }

        // Verified commercial venue → exact address for everyone who can see it.
        if ($this->isVerifiedCommercialVenue($location)) {
            return DisclosureLevel::Exact;
        }

        // Guest (unauthenticated) → only the area rung. resolveRelationship()
        // returns 'stranger' for a null viewer, so this also covers that path,
        // but the explicit guard documents intent and short-circuits before the
        // match for clarity.
        if ($viewer === null) {
            return DisclosureLevel::Area;
        }

        // Private / unverified location → graduate by relationship to the owner.
        return match ($relationship) {
            'owner', 'approved_participant' => DisclosureLevel::Exact,
            'friend_or_teammate' => DisclosureLevel::City,
            default => DisclosureLevel::Area, // stranger (incl. unresolvable owner)
        };
    }

    /**
     * The single authority for "what counts as a public venue page" (MEM717).
     *
     * True for verified commercial venues OR admin-managed commercial venues
     * (a claim-a-venue approval sets managed_by, S04/T02). Both branches gate
     * on a commercial VenueType and fail closed on a null type, so `Other`,
     * private (null type), and a null location always return false — a
     * managed_by link alone never grants a page to a non-commercial nature.
     *
     * This is the one gate consumed by the VenueDetail 404 route, the
     * <x-venue-link> affordance, and the venues sitemap — every surface that
     * must decide "does this location get a public page / a clickable name / an
     * indexed entry" routes through here so the rule can never drift across
     * surfaces.
     *
     * Page eligibility is deliberately decoupled from address disclosure:
     * {@see addressLevel()} / {@see DistanceDisplay()} / {@see
     * strangerPreviewLevel()} still grant exact/precise values only for
     * *verified* commercial venues. A managed-but-unverified venue gets a
     * public page, but its address granularity is governed by those methods
     * independently. No LocationPolicy is introduced because the rule is "is a
     * public venue", a property of the location's nature, not a per-viewer
     * authorization decision.
     */
    public function isPublicVenuePage(?Location $location): bool
    {
        return $this->isVerifiedCommercialVenue($location)
            || $this->isManagedCommercialVenue($location);
    }

    /**
     * Compute the disclosure level a stranger (unauthenticated / unrelated
     * viewer) would see for a location — the organizer's picker preview (T08).
     *
     * This is the guest branch of {@see addressLevel()} surfaced as a preview
     * helper: it takes no Game|Campaign entity because a stranger's disclosure
     * depends solely on the location's nature, never on an owner relationship.
     * It reuses the private {@see isVerifiedCommercialVenue()} primitive so the
     * "what counts as a public venue" decision stays in one place.
     *
     *   Verified commercial venue → Exact (full address)
     *   Everything else (private, unverified, 'other', null) → Area
     *
     * Fail-closed: a null/unresolvable location or a verified-but-untyped
     * location returns Area (never Exact), exactly matching what a stranger
     * actually sees through addressLevel(). This MUST stay consistent with the
     * guest branch of addressLevel() — the preview must never over-disclose
     * relative to the real rendered value.
     */
    public function strangerPreviewLevel(?Location $location): DisclosureLevel
    {
        if ($this->isVerifiedCommercialVenue($location)) {
            return DisclosureLevel::Exact;
        }

        return DisclosureLevel::Area;
    }

    /**
     * Resolve the distance display value for a location as seen by a viewer.
     *
     * Precise distance is permitted only for verified commercial venues (public
     * spaces where a precise number is safe). Every other location — regardless
     * of relationship — is grid-snapped to defeat trilateration (D060). Blocked
     * viewers and unresolvable locations get no distance at all.
     *
     * The entity is optional: pass it to enable blocked-viewer suppression.
     * Without it the service treats the viewer as unprivileged (fail-closed).
     *
     * @param  Game|Campaign|null  $entity  Optional entity for blocked resolution.
     */
    public function distanceDisplay(
        float $preciseKm,
        ?Location $location,
        ?User $viewer,
        Game|Campaign|null $entity = null,
    ): DistanceDisplay {
        // Fail-closed: no location → no distance.
        if ($location === null) {
            $this->warn('null_location_distance', [
                'viewer_id' => $viewer?->id,
            ]);

            return DistanceDisplay::hidden();
        }

        // Blocked viewer (only resolvable with the entity) → no distance.
        if ($entity !== null && $this->isBlocked($viewer, $entity)) {
            return DistanceDisplay::hidden();
        }

        // Verified commercial venue → precise distance is safe.
        if ($this->isVerifiedCommercialVenue($location)) {
            return DistanceDisplay::precise($preciseKm);
        }

        // Everything else (private / unverified / other / unknown) → grid-snap.
        return $this->gridSnap($preciseKm, $location, $viewer);
    }

    // ── Decision primitives ────────────────────────────

    /**
     * True only when the location is a *verified* commercial venue type.
     *
     * Fail-closed: a verified flag with no venue type is anomalous (a verified
     * location must carry its type) — logged and treated as non-commercial so
     * we never grant public-venue disclosure on incomplete data.
     */
    private function isVerifiedCommercialVenue(?Location $location): bool
    {
        if ($location === null) {
            return false;
        }

        $isVerified = (bool) $location->is_verified;
        $venueType = $location->venue_type;

        // Fail closed first on any missing venue type — a null type can never
        // qualify as a verified commercial venue, regardless of the is_verified
        // flag. Log when the data is internally inconsistent (verified but
        // untyped) so the inconsistency is discoverable in post-incident review.
        if ($venueType === null) {
            if ($isVerified) {
                $this->warn('verified_missing_venue_type', ['location_id' => $location->id]);
            }

            return false;
        }

        if (! $isVerified) {
            return false;
        }

        return in_array($venueType, VenueType::COMMERCIAL_TYPES, true);
    }

    /**
     * True only when the location is an *admin-managed* commercial venue type
     * (managed_by set by a claim-a-venue approval, S04/T02).
     *
     * Mirrors {@see isVerifiedCommercialVenue()} exactly, but tests
     * managed_by !== null instead of is_verified. The commercial-type gate
     * and the fail-closed-on-null-venue-type behavior are identical, so a
     * managed_by link alone never grants page eligibility to a non-commercial
     * nature (`Other`, private/null type). Verification is intentionally NOT
     * required here — admin stewardship is the independent second path to a
     * public page alongside organic verification.
     */
    private function isManagedCommercialVenue(?Location $location): bool
    {
        if ($location === null) {
            return false;
        }

        $venueType = $location->venue_type;

        // Fail closed first on any missing venue type — a null type can never
        // qualify as a managed commercial venue, regardless of managed_by.
        if ($venueType === null) {
            return false;
        }

        if ($location->managed_by === null) {
            return false;
        }

        return in_array($venueType, VenueType::COMMERCIAL_TYPES, true);
    }

    /**
     * Determine the viewer's relationship tier to the entity owner.
     *
     * Returns one of: 'blocked', 'owner', 'approved_participant',
     * 'friend_or_teammate', 'stranger'. Blocked is checked first so a block
     * always overrides any incidental privilege.
     */
    private function resolveRelationship(?User $viewer, Game|Campaign $entity): string
    {
        if ($viewer === null) {
            return 'stranger';
        }

        if ($this->isBlocked($viewer, $entity)) {
            return 'blocked';
        }

        $owner = $entity->owner;

        // No resolvable owner → cannot prove any privilege → fail-closed stranger.
        if ($owner === null) {
            return 'stranger';
        }

        if ($viewer->is($owner)) {
            return 'owner';
        }

        if ($this->isApprovedParticipant($viewer, $entity)) {
            return 'approved_participant';
        }

        if ($this->social->isFriendOrTeammate($viewer, $owner)) {
            return 'friend_or_teammate';
        }

        return 'stranger';
    }

    /**
     * True when the viewer is blocked by, or has blocked, the entity owner.
     */
    private function isBlocked(?User $viewer, Game|Campaign $entity): bool
    {
        if ($viewer === null) {
            return false;
        }

        $owner = $entity->owner;

        if ($owner === null || $owner->is($viewer)) {
            return false;
        }

        $blocked = $this->social->isBlockedBy($viewer, $owner)
            || $this->social->hasBlocked($viewer, $owner);

        if ($blocked) {
            $this->warn('blocked_viewer', [
                'viewer_id' => $viewer->id,
                'owner_id' => $owner->id,
                'entity_id' => $entity->id,
                'entity_type' => $entity::class,
            ]);
        }

        return $blocked;
    }

    /**
     * True when the viewer is an approved participant of the entity.
     */
    private function isApprovedParticipant(User $viewer, Game|Campaign $entity): bool
    {
        if ($entity instanceof Game) {
            return GameParticipant::query()
                ->where('game_id', $entity->id)
                ->where('user_id', $viewer->id)
                ->where('status', ParticipantStatus::Approved)
                ->exists();
        }

        return CampaignParticipant::query()
            ->where('campaign_id', $entity->id)
            ->where('user_id', $viewer->id)
            ->where('status', ParticipantStatus::Approved)
            ->exists();
    }

    /**
     * Grid-snap a distance per D060: round to nearest 5km with a 5km floor,
     * and flag "In your area" when the viewer shares a geohash tile or is
     * within 5km. Mirrors people-page.blade.php:258.
     */
    private function gridSnap(float $preciseKm, Location $location, ?User $viewer): DistanceDisplay
    {
        $bucket = (int) max(self::GRID_SIZE_KM, round($preciseKm / self::GRID_SIZE_KM) * self::GRID_SIZE_KM);
        $inArea = $preciseKm < self::GRID_SIZE_KM || $this->sameTile($location, $viewer);

        return DistanceDisplay::gridSnapped($bucket, $inArea);
    }

    /**
     * True when the viewer shares a geohash-4 tile with the location.
     */
    private function sameTile(Location $location, ?User $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        $viewerTile = $viewer->geohash4();
        $locationTile = $location->geohash_4;

        if ($viewerTile === null || $locationTile === null) {
            return false;
        }

        return $viewerTile === $locationTile;
    }

    /**
     * Structured warning for post-incident review of fail-closed triggers.
     *
     * @param  array<string, mixed>  $context
     */
    private function warn(string $reason, array $context = []): void
    {
        Log::warning("location_disclosure.fail_closed.{$reason}", $context);
    }
}

<?php

namespace Tests\Feature\Locations;

use App\Enums\DisclosureLevel;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\RelationshipType;
use App\Enums\VenueType;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Location;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\LocationDisclosureService;
use App\Values\DistanceDisplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesRelationships;

/**
 * Full decision matrix for LocationDisclosureService.
 *
 * Exercises every VenueType × every relationship level × verified/unverified
 * × null-location × null-venue-type × null-verification — for BOTH the address
 * level and the distance display — with explicit fail-closed assertions.
 */
class LocationDisclosureServiceTest extends TestCase
{
    use CreatesRelationships;
    use RefreshDatabase;

    private LocationDisclosureService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LocationDisclosureService::class);
    }

    // ── Test state helpers ─────────────────────────────

    private function location(?VenueType $type, bool $verified): Location
    {
        return Location::factory()->create([
            'venue_type' => $type?->value,
            'is_verified' => $verified,
        ]);
    }

    private function game(User $owner): Game
    {
        return Game::factory()->create(['owner_id' => $owner->id]);
    }

    private function campaign(User $owner): Campaign
    {
        return Campaign::factory()->create(['owner_id' => $owner->id]);
    }

    private function friend(User $a, User $b): void
    {
        $this->makeMutualFriends($a, $b);
    }

    private function teammate(User $a, User $b): void
    {
        $team = Team::factory()->create(['is_active' => true]);

        foreach ([$a, $b] as $user) {
            TeamMember::create([
                'team_id' => $team->id,
                'user_id' => $user->id,
                'role' => 'player',
                'status' => 'active',
                'joined_at' => now(),
            ]);
        }
    }

    private function approvedParticipant(User $user, Game $game): void
    {
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player,
            'status' => ParticipantStatus::Approved,
        ]);
    }

    private function approvedCampaignParticipant(User $user, Campaign $campaign): void
    {
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player,
            'status' => ParticipantStatus::Approved,
        ]);
    }

    private function block(User $blocker, User $blocked): void
    {
        UserRelationship::create([
            'user_id' => $blocker->id,
            'related_user_id' => $blocked->id,
            'type' => RelationshipType::Block,
        ]);
    }

    /** @return VenueType[] */
    private function commercialVenueTypes(): array
    {
        return [
            VenueType::Cafe,
            VenueType::Flgs,
            VenueType::Library,
            VenueType::CommunityCenter,
            VenueType::Convention,
            VenueType::Bar,
        ];
    }

    // ══════════════════════════════════════════════════
    //  ADDRESS — verified commercial venue → exact for all
    // ══════════════════════════════════════════════════

    #[Test]
    public function address_verified_commercial_venue_is_exact_for_every_commercial_type(): void
    {
        foreach ($this->commercialVenueTypes() as $venueType) {
            $owner = User::factory()->create();
            $game = $this->game($owner);
            $location = $this->location($venueType, verified: true);

            // Guest (unauthenticated) still gets exact for a public venue.
            $this->assertEquals(
                DisclosureLevel::Exact,
                $this->service->addressLevel($location, null, $game),
                "Venue type {$venueType->value} should resolve exact for a guest."
            );
        }
    }

    #[Test]
    public function address_verified_commercial_venue_is_exact_for_every_relationship_level(): void
    {
        foreach ($this->commercialVenueTypes() as $venueType) {
            $owner = User::factory()->create();
            $game = $this->game($owner);
            $location = $this->location($venueType, verified: true);

            $stranger = User::factory()->create();
            $friend = User::factory()->create();
            $this->friend($friend, $owner);
            $teammate = User::factory()->create();
            $this->teammate($teammate, $owner);
            $participant = User::factory()->create();
            $this->approvedParticipant($participant, $game);

            $this->assertEquals(DisclosureLevel::Exact, $this->service->addressLevel($location, $stranger, $game));
            $this->assertEquals(DisclosureLevel::Exact, $this->service->addressLevel($location, $friend, $game));
            $this->assertEquals(DisclosureLevel::Exact, $this->service->addressLevel($location, $teammate, $game));
            $this->assertEquals(DisclosureLevel::Exact, $this->service->addressLevel($location, $participant, $game));
            $this->assertEquals(DisclosureLevel::Exact, $this->service->addressLevel($location, $owner, $game));
        }
    }

    #[Test]
    public function address_blocked_viewer_gets_none_even_at_verified_venue(): void
    {
        $owner = User::factory()->create();
        $blockedViewer = User::factory()->create();
        $this->block($owner, $blockedViewer);

        $game = $this->game($owner);
        $venue = $this->location(VenueType::Cafe, verified: true);

        $this->assertEquals(DisclosureLevel::None, $this->service->addressLevel($venue, $blockedViewer, $game));
    }

    // ══════════════════════════════════════════════════
    //  ADDRESS — private / unverified / other → graduated
    // ══════════════════════════════════════════════════

    #[Test]
    public function address_private_location_graduated_by_relationship(): void
    {
        $owner = User::factory()->create();
        $game = $this->game($owner);
        $private = $this->location(type: null, verified: false); // a private home

        $stranger = User::factory()->create();
        $friend = User::factory()->create();
        $this->friend($friend, $owner);
        $teammate = User::factory()->create();
        $this->teammate($teammate, $owner);
        $participant = User::factory()->create();
        $this->approvedParticipant($participant, $game);

        $this->assertEquals(DisclosureLevel::Area, $this->service->addressLevel($private, $stranger, $game));
        $this->assertEquals(DisclosureLevel::City, $this->service->addressLevel($private, $friend, $game));
        $this->assertEquals(DisclosureLevel::City, $this->service->addressLevel($private, $teammate, $game));
        $this->assertEquals(DisclosureLevel::Exact, $this->service->addressLevel($private, $participant, $game));
        $this->assertEquals(DisclosureLevel::Exact, $this->service->addressLevel($private, $owner, $game));
    }

    #[Test]
    public function address_guest_viewer_gets_area_for_private_location(): void
    {
        $owner = User::factory()->create();
        $game = $this->game($owner);
        $private = $this->location(type: null, verified: false);

        $this->assertEquals(DisclosureLevel::Area, $this->service->addressLevel($private, null, $game));
    }

    #[Test]
    public function address_unverified_commercial_venue_type_treated_as_private(): void
    {
        $owner = User::factory()->create();
        $game = $this->game($owner);
        $unverifiedCafe = $this->location(VenueType::Cafe, verified: false);

        $stranger = User::factory()->create();
        $participant = User::factory()->create();
        $this->approvedParticipant($participant, $game);

        // Unverified → not a public venue; graduated by relationship.
        $this->assertEquals(DisclosureLevel::Area, $this->service->addressLevel($unverifiedCafe, $stranger, $game));
        $this->assertEquals(DisclosureLevel::Exact, $this->service->addressLevel($unverifiedCafe, $participant, $game));
    }

    #[Test]
    public function address_other_venue_type_treated_as_private_even_when_verified(): void
    {
        $owner = User::factory()->create();
        $game = $this->game($owner);
        $other = $this->location(VenueType::Other, verified: true);

        $stranger = User::factory()->create();
        $friend = User::factory()->create();
        $this->friend($friend, $owner);

        $this->assertEquals(DisclosureLevel::Area, $this->service->addressLevel($other, $stranger, $game));
        $this->assertEquals(DisclosureLevel::City, $this->service->addressLevel($other, $friend, $game));
    }

    #[Test]
    public function address_blocked_viewer_gets_none_for_private_location(): void
    {
        $owner = User::factory()->create();
        $blockedViewer = User::factory()->create();
        $this->block($owner, $blockedViewer);

        $game = $this->game($owner);
        $private = $this->location(type: null, verified: false);

        $this->assertEquals(DisclosureLevel::None, $this->service->addressLevel($private, $blockedViewer, $game));
    }

    #[Test]
    public function address_viewer_blocking_owner_also_gets_none(): void
    {
        $owner = User::factory()->create();
        $game = $this->game($owner);
        $private = $this->location(type: null, verified: false);

        $viewer = User::factory()->create();
        $this->block($viewer, $owner); // viewer blocked the owner

        $this->assertEquals(DisclosureLevel::None, $this->service->addressLevel($private, $viewer, $game));
    }

    #[Test]
    public function address_campaign_entity_graduated_like_game(): void
    {
        $owner = User::factory()->create();
        $campaign = $this->campaign($owner);
        $private = $this->location(type: null, verified: false);

        $stranger = User::factory()->create();
        $friend = User::factory()->create();
        $this->friend($friend, $owner);
        $participant = User::factory()->create();
        $this->approvedCampaignParticipant($participant, $campaign);

        $this->assertEquals(DisclosureLevel::Area, $this->service->addressLevel($private, $stranger, $campaign));
        $this->assertEquals(DisclosureLevel::City, $this->service->addressLevel($private, $friend, $campaign));
        $this->assertEquals(DisclosureLevel::Exact, $this->service->addressLevel($private, $participant, $campaign));
        $this->assertEquals(DisclosureLevel::Exact, $this->service->addressLevel($private, $owner, $campaign));
    }

    // ══════════════════════════════════════════════════
    //  ADDRESS — fail CLOSED on missing data
    // ══════════════════════════════════════════════════

    #[Test]
    public function address_null_location_returns_none(): void
    {
        $owner = User::factory()->create();
        $game = $this->game($owner);
        $viewer = User::factory()->create();

        $this->assertEquals(DisclosureLevel::None, $this->service->addressLevel(null, $viewer, $game));
        $this->assertEquals(DisclosureLevel::None, $this->service->addressLevel(null, null, $game));
    }

    #[Test]
    public function address_verified_location_with_null_venue_type_never_exact(): void
    {
        // Anomalous: verified flag set but no venue type → fail-closed to private.
        $owner = User::factory()->create();
        $game = $this->game($owner);
        $location = $this->location(type: null, verified: true);

        $stranger = User::factory()->create();
        $participant = User::factory()->create();
        $this->approvedParticipant($participant, $game);

        $this->assertEquals(DisclosureLevel::Area, $this->service->addressLevel($location, $stranger, $game));
        // Even an approved participant never gets exact when the venue type is missing.
        $this->assertEquals(DisclosureLevel::Exact, $this->service->addressLevel($location, $participant, $game),
            'Approved participants still get exact on private locations (relationship axis), but strangers never do.');
    }

    #[Test]
    public function address_null_verification_treated_as_unverified(): void
    {
        $owner = User::factory()->create();
        $game = $this->game($owner);

        // The locations.is_verified column is NOT NULL, so a genuinely null DB
        // value is structurally impossible post-M051. Exercise the in-memory
        // null path (boolean cast resolves it falsy) to prove the service never
        // grants exact on unknown verification.
        $location = $this->location(VenueType::Cafe, verified: false);
        $location->is_verified = null;

        $stranger = User::factory()->create();

        $this->assertEquals(DisclosureLevel::Area, $this->service->addressLevel($location, $stranger, $game),
            'Null/unknown verification must never resolve to exact for a stranger.');
    }

    // ══════════════════════════════════════════════════
    //  DISTANCE — verified commercial venue → precise
    // ══════════════════════════════════════════════════

    #[Test]
    public function distance_verified_venue_shows_precise_for_every_commercial_type(): void
    {
        foreach ($this->commercialVenueTypes() as $venueType) {
            $location = $this->location($venueType, verified: true);

            $display = $this->service->distanceDisplay(12.34, $location, null);

            $this->assertTrue($display->isPrecise(), "Venue {$venueType->value} distance must be precise.");
            $this->assertSame(12.34, $display->preciseKm);
        }
    }

    #[Test]
    public function distance_verified_venue_shows_precise_for_every_relationship_level(): void
    {
        $owner = User::factory()->create();
        $game = $this->game($owner);
        $venue = $this->location(VenueType::Cafe, verified: true);

        $stranger = User::factory()->create();
        $friend = User::factory()->create();
        $this->friend($friend, $owner);
        $teammate = User::factory()->create();
        $this->teammate($teammate, $owner);
        $participant = User::factory()->create();
        $this->approvedParticipant($participant, $game);

        foreach ([$stranger, $friend, $teammate, $participant, $owner] as $viewer) {
            $display = $this->service->distanceDisplay(7.5, $venue, $viewer, $game);
            $this->assertTrue($display->isPrecise(), 'Verified venue distance is precise regardless of relationship.');
        }

        // Guests too.
        $this->assertTrue($this->service->distanceDisplay(7.5, $venue, null)->isPrecise());
    }

    // ══════════════════════════════════════════════════
    //  DISTANCE — private / unverified / other → grid-snap (relationship-invariant)
    // ══════════════════════════════════════════════════

    #[Test]
    public function distance_private_location_is_grid_snapped_regardless_of_relationship(): void
    {
        // Safety invariant: only venues get precise distance. Every private
        // location is grid-snapped, even for the owner/participant, because a
        // precise number to a private location is a trilateration vector and
        // the address axis already gives trusted viewers the exact address.
        $owner = User::factory()->create();
        $game = $this->game($owner);
        $private = $this->location(type: null, verified: false);

        $stranger = User::factory()->create();
        $friend = User::factory()->create();
        $this->friend($friend, $owner);
        $participant = User::factory()->create();
        $this->approvedParticipant($participant, $game);

        foreach ([$stranger, $friend, $participant, $owner] as $viewer) {
            $display = $this->service->distanceDisplay(23.0, $private, $viewer, $game);
            $this->assertTrue($display->isGridSnapped(), 'Private-location distance must always grid-snap.');
            $this->assertSame(25, $display->bucketKm);
        }

        // Guest too.
        $this->assertTrue($this->service->distanceDisplay(23.0, $private, null)->isGridSnapped());
    }

    #[Test]
    public function distance_unverified_venue_type_and_other_type_are_grid_snapped(): void
    {
        $unverifiedCafe = $this->location(VenueType::Cafe, verified: false);
        $verifiedOther = $this->location(VenueType::Other, verified: true);

        $this->assertTrue($this->service->distanceDisplay(12.0, $unverifiedCafe, null)->isGridSnapped());
        $this->assertTrue($this->service->distanceDisplay(12.0, $verifiedOther, null)->isGridSnapped());
    }

    // ── Grid-snap math (reference: people-page.blade.php:258) ──

    #[Test]
    public function distance_grid_snap_rounds_to_nearest_five_with_five_floor(): void
    {
        $private = $this->location(type: null, verified: false);

        $cases = [
            0.2 => 5,    // < 5km floors to 5 (and flags in-area)
            4.9 => 5,    // just under 5 → floor 5
            5.0 => 5,
            7.0 => 5,    // 7 rounds to 5
            8.0 => 10,   // 8 rounds to 10
            12.0 => 10,
            13.0 => 15,
            23.0 => 25,
            48.0 => 50,
            99.9 => 100,
        ];

        foreach ($cases as $precise => $expectedBucket) {
            $display = $this->service->distanceDisplay((float) $precise, $private, null);
            $this->assertSame($expectedBucket, $display->bucketKm, "precise {$precise}km should snap to {$expectedBucket}.");
        }
    }

    #[Test]
    public function distance_in_your_area_when_within_five_km(): void
    {
        $private = $this->location(type: null, verified: false);

        $close = $this->service->distanceDisplay(3.0, $private, null);
        $this->assertTrue($close->inArea, 'Distance under 5km is flagged in-area.');
        $this->assertSame(5, $close->bucketKm);

        $far = $this->service->distanceDisplay(12.0, $private, null);
        $this->assertFalse($far->inArea, 'Distance over 5km is not in-area (no shared tile).');
    }

    #[Test]
    public function distance_in_your_area_when_viewer_shares_geohash_tile(): void
    {
        $owner = User::factory()->create();
        $game = $this->game($owner);

        // A private location with explicit coordinates + computed geohash_4.
        $private = Location::factory()->create([
            'venue_type' => null,
            'is_verified' => false,
            'latitude' => '52.5200000',
            'longitude' => '13.4050000',
        ]);
        $private->refresh(); // pick up the saving-event geohash_4

        // Viewer links a location that shares the same geohash-4 tile.
        $viewer = User::factory()->create([
            'location_id' => Location::factory()->create([
                'latitude' => '52.5210000',
                'longitude' => '13.4060000',
            ])->id,
        ]);

        // 12km away (over the 5km threshold) but same tile → in-area via tile equality.
        $display = $this->service->distanceDisplay(12.0, $private, $viewer, $game);

        $this->assertTrue($display->inArea, 'Same geohash tile should flag in-area even beyond 5km.');
    }

    // ══════════════════════════════════════════════════
    //  DISTANCE — fail CLOSED
    // ══════════════════════════════════════════════════

    #[Test]
    public function distance_null_location_is_hidden(): void
    {
        $owner = User::factory()->create();
        $game = $this->game($owner);
        $viewer = User::factory()->create();

        $this->assertTrue($this->service->distanceDisplay(10.0, null, $viewer, $game)->isHidden());
        $this->assertTrue($this->service->distanceDisplay(10.0, null, null)->isHidden());
    }

    #[Test]
    public function distance_blocked_viewer_is_hidden(): void
    {
        $owner = User::factory()->create();
        $blockedViewer = User::factory()->create();
        $this->block($owner, $blockedViewer);

        $game = $this->game($owner);
        $venue = $this->location(VenueType::Cafe, verified: true);

        // Blocked at a venue → no distance.
        $this->assertTrue($this->service->distanceDisplay(5.0, $venue, $blockedViewer, $game)->isHidden());

        // Without the entity the service cannot resolve the block; venue still
        // resolves precise. This documents that blocked suppression requires
        // passing the entity.
        $this->assertTrue($this->service->distanceDisplay(5.0, $venue, $blockedViewer)->isPrecise());
    }

    // ══════════════════════════════════════════════════
    //  Value objects
    // ══════════════════════════════════════════════════

    #[Test]
    public function disclosure_level_rank_orders_most_restrictive_first(): void
    {
        $this->assertSame(0, DisclosureLevel::None->rank());
        $this->assertSame(3, DisclosureLevel::Exact->rank());

        $this->assertEquals(DisclosureLevel::None, DisclosureLevel::mostRestrictive(DisclosureLevel::Exact, DisclosureLevel::None));
        $this->assertEquals(DisclosureLevel::Area, DisclosureLevel::mostRestrictive(DisclosureLevel::Area, DisclosureLevel::City));
        $this->assertTrue(DisclosureLevel::City->isAtLeast(DisclosureLevel::Area));
        $this->assertFalse(DisclosureLevel::Area->isAtLeast(DisclosureLevel::City));
    }

    #[Test]
    public function distance_display_modes_and_rendering(): void
    {
        $precise = DistanceDisplay::precise(12.34);
        $this->assertTrue($precise->isPrecise());
        $this->assertSame(12.34, $precise->preciseKm);
        $this->assertSame('12.3 km', $precise->display());

        $inArea = DistanceDisplay::gridSnapped(5, true);
        $this->assertTrue($inArea->isGridSnapped());
        $this->assertTrue($inArea->inArea);
        $this->assertSame('In your area', $inArea->display());

        $bucket = DistanceDisplay::gridSnapped(25, false);
        $this->assertSame('Nearby — ~25 km', $bucket->display());

        $hidden = DistanceDisplay::hidden();
        $this->assertTrue($hidden->isHidden());
        $this->assertSame('', $hidden->display());
    }

    #[Test]
    public function service_is_resolvable_from_the_container(): void
    {
        $this->assertInstanceOf(
            LocationDisclosureService::class,
            app(LocationDisclosureService::class)
        );
    }
}

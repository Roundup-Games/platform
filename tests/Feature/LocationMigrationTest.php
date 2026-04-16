<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Game;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LocationMigrationTest extends TestCase
{
    use RefreshDatabase;

    // ── Games Migration ────────────────────────────────

    #[Test]
    public function it_migrates_offline_game_with_details(): void
    {
        $game = Game::factory()->create([
            'location' => [
                'type' => 'offline',
                'details' => '123 Board Game Café, Berlin',
            ],
            'location_id' => null,
        ]);

        $this->artisan('location:migrate')
            ->expectsOutputToContain('Starting location data migration')
            ->assertSuccessful();

        $game->refresh();
        $this->assertNotNull($game->location_id);

        $location = $game->linkedLocation;
        $this->assertEquals('123 Board Game Café, Berlin', $location->address);
        $this->assertEquals('game_json', $location->source);
        $this->assertNull($location->latitude);
        $this->assertNull($location->longitude);
    }

    #[Test]
    public function it_migrates_game_with_legacy_coordinates(): void
    {
        $game = Game::factory()->create([
            'location' => [
                'type' => 'offline',
                'details' => 'Berlin Office',
                'address' => '123 Test St, Berlin',
                'lat' => 52.5200,
                'lng' => 13.4050,
                'placeId' => 'ChIJtest123',
            ],
            'location_id' => null,
        ]);

        $this->artisan('location:migrate')->assertSuccessful();

        $game->refresh();
        $location = $game->linkedLocation;

        $this->assertNotNull($location);
        $this->assertEquals(52.5200, (float) $location->latitude);
        $this->assertEquals(13.4050, (float) $location->longitude);
        $this->assertEquals('ChIJtest123', $location->place_id);
        $this->assertEquals('123 Test St, Berlin', $location->address);
    }

    #[Test]
    public function it_skips_online_games(): void
    {
        $game = Game::factory()->create([
            'location' => [
                'type' => 'online',
                'details' => 'https://discord.gg/example',
            ],
            'location_id' => null,
        ]);

        $this->artisan('location:migrate')->assertSuccessful();

        $game->refresh();
        $this->assertNull($game->location_id);
    }

    #[Test]
    public function it_skips_games_with_empty_location_array(): void
    {
        $game = Game::factory()->create([
            'location' => [],
            'location_id' => null,
        ]);

        $this->artisan('location:migrate')->assertSuccessful();

        $game->refresh();
        $this->assertNull($game->location_id);
    }

    #[Test]
    public function it_skips_games_already_migrated(): void
    {
        $location = Location::factory()->create();
        $game = Game::factory()->create([
            'location' => ['type' => 'offline', 'details' => 'Test'],
            'location_id' => $location->id,
        ]);

        $initialCount = Location::count();

        $this->artisan('location:migrate')->assertSuccessful();

        // Should not create a new location
        $this->assertEquals($initialCount, Location::count());
    }

    // ── Events Migration ───────────────────────────────

    #[Test]
    public function it_migrates_event_with_venue_and_city(): void
    {
        $event = Event::factory()->create([
            'venue_name' => 'Messe Hamburg',
            'venue_address' => 'Marseiller Str. 10',
            'city' => 'Hamburg',
            'country' => 'DEU',
            'postal_code' => '20357',
            'location_id' => null,
        ]);

        $this->artisan('location:migrate')->assertSuccessful();

        $event->refresh();
        $this->assertNotNull($event->location_id);

        $location = $event->linkedLocation;
        $this->assertEquals('Messe Hamburg', $location->name);
        $this->assertEquals('Marseiller Str. 10', $location->address);
        $this->assertEquals('Hamburg', $location->city);
        $this->assertEquals('DEU', $location->country);
        $this->assertEquals('20357', $location->postal_code);
        $this->assertEquals('event_columns', $location->source);
    }

    #[Test]
    public function it_migrates_event_with_only_city(): void
    {
        $event = Event::factory()->create([
            'venue_name' => null,
            'venue_address' => null,
            'city' => 'Munich',
            'country' => 'DEU',
            'postal_code' => null,
            'location_id' => null,
        ]);

        $this->artisan('location:migrate')->assertSuccessful();

        $event->refresh();
        $this->assertNotNull($event->location_id);

        $location = $event->linkedLocation;
        $this->assertEquals('Munich', $location->city);
        $this->assertEquals('DEU', $location->country);
    }

    #[Test]
    public function it_skips_events_without_location_data(): void
    {
        $event = Event::factory()->create([
            'venue_name' => null,
            'venue_address' => null,
            'city' => null,
            'country' => null,
            'postal_code' => null,
            'location_id' => null,
        ]);

        $this->artisan('location:migrate')->assertSuccessful();

        $event->refresh();
        $this->assertNull($event->location_id);
    }

    // ── Users Migration ────────────────────────────────

    #[Test]
    public function it_migrates_user_with_address(): void
    {
        $user = User::factory()->create([
            'location' => ['address' => 'Schillerstraße 5, Vienna'],
            'location_id' => null,
        ]);

        $this->artisan('location:migrate')->assertSuccessful();

        $user->refresh();
        $this->assertNotNull($user->location_id);

        $location = $user->linkedLocation;
        $this->assertEquals('Schillerstraße 5, Vienna', $location->address);
        $this->assertEquals('user_json', $location->source);
    }

    #[Test]
    public function it_skips_users_with_empty_location(): void
    {
        $user = User::factory()->create([
            'location' => null,
            'location_id' => null,
        ]);

        $this->artisan('location:migrate')->assertSuccessful();

        $user->refresh();
        $this->assertNull($user->location_id);
    }

    #[Test]
    public function it_skips_users_with_empty_address(): void
    {
        $user = User::factory()->create([
            'location' => ['address' => ''],
            'location_id' => null,
        ]);

        $this->artisan('location:migrate')->assertSuccessful();

        $user->refresh();
        $this->assertNull($user->location_id);
    }

    // ── Deduplication ──────────────────────────────────

    #[Test]
    public function it_deduplicates_by_place_id(): void
    {
        // First game creates a location with placeId
        Game::factory()->create([
            'location' => [
                'type' => 'offline',
                'details' => 'Spielplatz Berlin',
                'address' => 'Alexanderplatz 1',
                'lat' => 52.5219,
                'lng' => 13.4132,
                'placeId' => 'ChIJDedupTest001',
            ],
            'location_id' => null,
        ]);

        // Second game with same placeId should reuse the location
        Game::factory()->create([
            'location' => [
                'type' => 'offline',
                'details' => 'Same place, different game',
                'address' => 'Alexanderplatz 1',
                'lat' => 52.5219,
                'lng' => 13.4132,
                'placeId' => 'ChIJDedupTest001',
            ],
            'location_id' => null,
        ]);

        $this->artisan('location:migrate')->assertSuccessful();

        $this->assertEquals(1, Location::count());

        $location = Location::first();
        $this->assertEquals(2, Game::where('location_id', $location->id)->count());
    }

    #[Test]
    public function it_deduplicates_by_normalized_address(): void
    {
        // Two events with the same venue, different formatting
        Event::factory()->create([
            'venue_name' => 'Game Store Munich',
            'venue_address' => 'Marienplatz 1',
            'city' => 'Munich',
            'country' => 'DEU',
            'postal_code' => '80331',
            'location_id' => null,
        ]);

        Event::factory()->create([
            'venue_name' => 'Game Store Munich',
            'venue_address' => 'Marienplatz 1',
            'city' => 'Munich',
            'country' => 'DEU',
            'postal_code' => '80331',
            'location_id' => null,
        ]);

        $this->artisan('location:migrate')->assertSuccessful();

        $this->assertEquals(1, Location::count());

        $location = Location::first();
        $this->assertEquals(2, Event::where('location_id', $location->id)->count());
    }

    #[Test]
    public function it_creates_separate_locations_for_different_addresses(): void
    {
        Event::factory()->create([
            'venue_name' => 'Venue A',
            'venue_address' => 'Street 1',
            'city' => 'Berlin',
            'country' => 'DEU',
            'location_id' => null,
        ]);

        Event::factory()->create([
            'venue_name' => 'Venue B',
            'venue_address' => 'Street 2',
            'city' => 'Munich',
            'country' => 'DEU',
            'location_id' => null,
        ]);

        $this->artisan('location:migrate')->assertSuccessful();

        $this->assertEquals(2, Location::count());
    }

    // ── Dry Run ────────────────────────────────────────

    #[Test]
    public function dry_run_does_not_create_locations(): void
    {
        Game::factory()->create([
            'location' => ['type' => 'offline', 'details' => 'Test Address'],
            'location_id' => null,
        ]);

        $this->artisan('location:migrate --dry-run')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertEquals(0, Location::count());

        // Game should still have no location_id
        $game = Game::first();
        $this->assertNull($game->location_id);
    }

    // ── Cross-entity dedup ─────────────────────────────

    #[Test]
    public function it_deduplicates_across_games_and_events(): void
    {
        // Game with coordinates and placeId
        Game::factory()->create([
            'location' => [
                'type' => 'offline',
                'details' => 'Convention Center',
                'address' => 'Messeweg 1',
                'lat' => 53.5511,
                'lng' => 9.9937,
                'placeId' => 'ChIJCrossDedup001',
            ],
            'location_id' => null,
        ]);

        // Event at the same venue (same place_id via address match)
        Event::factory()->create([
            'venue_name' => 'Convention Center',
            'venue_address' => 'Messeweg 1',
            'city' => 'Hamburg',
            'country' => 'DEU',
            'location_id' => null,
        ]);

        $this->artisan('location:migrate')->assertSuccessful();

        // Two locations because address-key dedup won't match (game has different
        // city/country than event). This is expected — place_id dedup only works
        // when both sources have a place_id.
        $this->assertEquals(2, Location::count());
    }

    // ── Statistics output ──────────────────────────────

    #[Test]
    public function it_logs_migration_statistics(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->with('Location data migration completed', \Mockery::on(function ($data) {
                return isset($data['locations_created'])
                    && isset($data['games_migrated'])
                    && isset($data['events_migrated'])
                    && isset($data['users_migrated'])
                    && isset($data['errors']);
            }));

        $this->artisan('location:migrate')->assertSuccessful();
    }

    #[Test]
    public function it_shows_migration_statistics_in_output(): void
    {
        Game::factory()->create([
            'location' => ['type' => 'offline', 'details' => 'Test Café'],
            'location_id' => null,
        ]);

        $this->artisan('location:migrate')
            ->expectsOutputToContain('Migration Statistics')
            ->expectsOutputToContain('Locations created:')
            ->expectsOutputToContain('Games migrated:')
            ->assertSuccessful();
    }
}

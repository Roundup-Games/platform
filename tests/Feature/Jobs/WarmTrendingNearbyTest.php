<?php

namespace Tests\Unit\Jobs;

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Enums\ParticipantRole;
use App\Jobs\WarmTrendingNearby;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Location;
use App\Models\User;
use App\Services\DashboardCacheService;
use App\Services\Geohash;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WarmTrendingNearbyTest extends TestCase
{
    use DatabaseTransactions;

    /** Berlin area coordinates */
    private const LAT = 52.5163;

    private const LNG = 13.3777;

    #[Test]
    public function it_warms_trending_cache_for_geohash_tile(): void
    {
        $geohash4 = Geohash::tilePrefix(self::LAT, self::LNG, 4);

        $location = Location::factory()->create([
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ]);

        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'location_id' => $location->id,
            'status' => GameStatus::Scheduled->value,
            'date_time' => now()->addDays(3),
        ]);

        Log::shouldReceive('info')->atLeast(2);
        Log::shouldReceive('debug')->atLeast(0);

        $job = new WarmTrendingNearby($geohash4, 'cache_miss');
        $job->handle(app(DashboardCacheService::class));

        $cached = Cache::get("dashboard:trending:{$geohash4}");
        $this->assertNotNull($cached);
        $this->assertArrayHasKey('games', $cached);
        $this->assertCount(1, $cached['games']);
        $this->assertEquals($game->id, $cached['games'][0]['id']);
        $this->assertEquals($game->name, $cached['games'][0]['name']);
        $this->assertEquals($location->city, $cached['games'][0]['location_city']);
    }

    #[Test]
    public function it_sorts_by_participant_count_then_created_at(): void
    {
        $geohash4 = Geohash::tilePrefix(self::LAT, self::LNG, 4);

        $location = Location::factory()->create([
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ]);

        $owner = User::factory()->create();

        // Game with fewer participants, created earlier
        $gameFewer = Game::factory()->create([
            'owner_id' => $owner->id,
            'location_id' => $location->id,
            'status' => GameStatus::Scheduled->value,
            'date_time' => now()->addDays(5),
            'created_at' => now()->subDays(3),
        ]);

        // Game with more participants, created later — should rank higher
        $gameMore = Game::factory()->create([
            'owner_id' => $owner->id,
            'location_id' => $location->id,
            'status' => GameStatus::Scheduled->value,
            'date_time' => now()->addDays(5),
            'created_at' => now()->subDays(1),
        ]);

        // Add participants (including owner under explicit model)
        $player1 = User::factory()->create();
        $player2 = User::factory()->create();

        GameParticipant::create([
            'game_id' => $gameMore->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        GameParticipant::create([
            'game_id' => $gameMore->id,
            'user_id' => $player1->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        GameParticipant::create([
            'game_id' => $gameMore->id,
            'user_id' => $player2->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        GameParticipant::create([
            'game_id' => $gameFewer->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        GameParticipant::create([
            'game_id' => $gameFewer->id,
            'user_id' => $player1->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        Log::shouldReceive('info')->atLeast(2);
        Log::shouldReceive('debug')->atLeast(0);

        $job = new WarmTrendingNearby($geohash4, 'sweep');
        $job->handle(app(DashboardCacheService::class));

        $cached = Cache::get("dashboard:trending:{$geohash4}");
        $this->assertNotNull($cached);
        $this->assertCount(2, $cached['games']);

        // Game with more participants comes first
        $this->assertEquals($gameMore->id, $cached['games'][0]['id']);
        $this->assertEquals(3, $cached['games'][0]['participant_count']); // owner + 2 players
        $this->assertEquals($gameFewer->id, $cached['games'][1]['id']);
        $this->assertEquals(2, $cached['games'][1]['participant_count']); // owner + 1 player
    }

    #[Test]
    public function it_limits_results_to_top_five(): void
    {
        $geohash4 = Geohash::tilePrefix(self::LAT, self::LNG, 4);

        $location = Location::factory()->create([
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ]);

        $owner = User::factory()->create();

        // Create 7 scheduled games in the tile
        $games = collect();
        for ($i = 0; $i < 7; $i++) {
            $games->push(Game::factory()->create([
                'owner_id' => $owner->id,
                'location_id' => $location->id,
                'status' => GameStatus::Scheduled->value,
                'date_time' => now()->addDays($i + 1),
            ]));
        }

        Log::shouldReceive('info')->atLeast(2);
        Log::shouldReceive('debug')->atLeast(0);

        $job = new WarmTrendingNearby($geohash4, 'cache_miss');
        $job->handle(app(DashboardCacheService::class));

        $cached = Cache::get("dashboard:trending:{$geohash4}");
        $this->assertNotNull($cached);
        $this->assertCount(5, $cached['games']);
    }

    #[Test]
    public function it_excludes_games_outside_14_day_window(): void
    {
        $geohash4 = Geohash::tilePrefix(self::LAT, self::LNG, 4);

        $location = Location::factory()->create([
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ]);

        $owner = User::factory()->create();

        // Game within window
        Game::factory()->create([
            'owner_id' => $owner->id,
            'location_id' => $location->id,
            'status' => GameStatus::Scheduled->value,
            'date_time' => now()->addDays(10),
        ]);

        // Game outside 14-day window
        Game::factory()->create([
            'owner_id' => $owner->id,
            'location_id' => $location->id,
            'status' => GameStatus::Scheduled->value,
            'date_time' => now()->addDays(20),
        ]);

        // Past game
        Game::factory()->create([
            'owner_id' => $owner->id,
            'location_id' => $location->id,
            'status' => GameStatus::Scheduled->value,
            'date_time' => now()->subDays(1),
        ]);

        Log::shouldReceive('info')->atLeast(2);
        Log::shouldReceive('debug')->atLeast(0);

        $job = new WarmTrendingNearby($geohash4, 'cache_miss');
        $job->handle(app(DashboardCacheService::class));

        $cached = Cache::get("dashboard:trending:{$geohash4}");
        $this->assertNotNull($cached);
        $this->assertCount(1, $cached['games']);
    }

    #[Test]
    public function it_excludes_non_scheduled_games(): void
    {
        $geohash4 = Geohash::tilePrefix(self::LAT, self::LNG, 4);

        $location = Location::factory()->create([
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ]);

        $owner = User::factory()->create();

        // Scheduled game — should be included
        Game::factory()->create([
            'owner_id' => $owner->id,
            'location_id' => $location->id,
            'status' => GameStatus::Scheduled->value,
            'date_time' => now()->addDays(3),
        ]);

        // Canceled game — should be excluded
        Game::factory()->create([
            'owner_id' => $owner->id,
            'location_id' => $location->id,
            'status' => GameStatus::Canceled->value,
            'date_time' => now()->addDays(3),
        ]);

        // Completed game — should be excluded
        Game::factory()->create([
            'owner_id' => $owner->id,
            'location_id' => $location->id,
            'status' => GameStatus::Completed->value,
            'date_time' => now()->addDays(3),
        ]);

        Log::shouldReceive('info')->atLeast(2);
        Log::shouldReceive('debug')->atLeast(0);

        $job = new WarmTrendingNearby($geohash4, 'cache_miss');
        $job->handle(app(DashboardCacheService::class));

        $cached = Cache::get("dashboard:trending:{$geohash4}");
        $this->assertNotNull($cached);
        $this->assertCount(1, $cached['games']);
    }

    #[Test]
    public function it_excludes_games_outside_tile_bounding_box(): void
    {
        $geohash4 = Geohash::tilePrefix(self::LAT, self::LNG, 4);

        $locationNear = Location::factory()->create([
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ]);

        $locationFar = Location::factory()->create([
            'latitude' => 48.2082,  // Vienna — far from Berlin tile
            'longitude' => 16.3738,
        ]);

        $owner = User::factory()->create();

        Game::factory()->create([
            'owner_id' => $owner->id,
            'location_id' => $locationNear->id,
            'status' => GameStatus::Scheduled->value,
            'date_time' => now()->addDays(3),
        ]);

        Game::factory()->create([
            'owner_id' => $owner->id,
            'location_id' => $locationFar->id,
            'status' => GameStatus::Scheduled->value,
            'date_time' => now()->addDays(3),
        ]);

        Log::shouldReceive('info')->atLeast(2);
        Log::shouldReceive('debug')->atLeast(0);

        $job = new WarmTrendingNearby($geohash4, 'cache_miss');
        $job->handle(app(DashboardCacheService::class));

        $cached = Cache::get("dashboard:trending:{$geohash4}");
        $this->assertNotNull($cached);
        $this->assertCount(1, $cached['games']);
    }

    #[Test]
    public function it_returns_zero_results_for_empty_tile(): void
    {
        $geohash4 = Geohash::tilePrefix(self::LAT, self::LNG, 4);

        Log::shouldReceive('info')->atLeast(2);
        Log::shouldReceive('debug')->atLeast(0);

        $job = new WarmTrendingNearby($geohash4, 'sweep');
        $job->handle(app(DashboardCacheService::class));

        $cached = Cache::get("dashboard:trending:{$geohash4}");
        $this->assertNotNull($cached);
        $this->assertCount(0, $cached['games']);
    }

    #[Test]
    public function it_logs_failure_on_exception(): void
    {
        Log::shouldReceive('error')->once()->with('dashboard.warm_trending.failed', \Mockery::on(function ($context) {
            return $context['geohash_4'] === 'u33d'
                && $context['trigger_type'] === 'sweep'
                && isset($context['exception']);
        }));

        $job = new WarmTrendingNearby('u33d', 'sweep');
        $job->failed(new \RuntimeException('test error'));
    }

    #[Test]
    public function it_uses_discovery_queue(): void
    {
        $job = new WarmTrendingNearby('u33d', 'cache_miss');

        $this->assertEquals('discovery', $job->queue);
    }

    #[Test]
    public function it_has_unique_id_per_geohash(): void
    {
        $job1 = new WarmTrendingNearby('u33d', 'cache_miss');
        $job2 = new WarmTrendingNearby('u33e', 'cache_miss');

        $this->assertEquals('u33d', $job1->uniqueId());
        $this->assertEquals('u33e', $job2->uniqueId());
        $this->assertNotEquals($job1->uniqueId(), $job2->uniqueId());
    }

    #[Test]
    public function it_counts_only_approved_participants(): void
    {
        $geohash4 = Geohash::tilePrefix(self::LAT, self::LNG, 4);

        $location = Location::factory()->create([
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ]);

        $owner = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'location_id' => $location->id,
            'status' => GameStatus::Scheduled->value,
            'date_time' => now()->addDays(3),
        ]);

        $approvedPlayer = User::factory()->create();
        $pendingPlayer = User::factory()->create();

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $approvedPlayer->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $pendingPlayer->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        Log::shouldReceive('info')->atLeast(2);
        Log::shouldReceive('debug')->atLeast(0);

        $job = new WarmTrendingNearby($geohash4, 'cache_miss');
        $job->handle(app(DashboardCacheService::class));

        $cached = Cache::get("dashboard:trending:{$geohash4}");
        $this->assertNotNull($cached);
        $this->assertCount(1, $cached['games']);
        // owner + approved player = 2 (pending not counted)
        $this->assertEquals(2, $cached['games'][0]['participant_count']);
    }
}

<?php

namespace Tests\Unit\Jobs;

use App\Jobs\UpdateUserDiscoveryCache;
use App\Models\Location;
use App\Models\NearbyDiscoveryView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UpdateUserDiscoveryCacheTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 52.5163;
    private const LNG = 13.3777;

    #[Test]
    public function it_populates_cache_and_updates_discovery_view(): void
    {
        $location = Location::factory()->create([
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ]);

        $user = User::factory()->create([
            'location_id' => $location->id,
            'profile_complete' => true,
        ]);

        Log::shouldReceive('info')->atLeast(2);
        Log::shouldReceive('debug')->atLeast(0);

        $job = new UpdateUserDiscoveryCache($user->id, 'location_change');
        $job->handle(app(\App\Services\PeopleDiscoveryService::class));

        $view = NearbyDiscoveryView::where('user_id', $user->id)->first();
        $this->assertNotNull($view);
        $this->assertNotNull($view->geohash_4);
        $this->assertEquals(4, strlen($view->geohash_4));
    }

    #[Test]
    public function it_skips_deleted_user_gracefully(): void
    {
        Log::shouldReceive('info')->atLeast(1);
        Log::shouldReceive('warning')->once();

        $job = new UpdateUserDiscoveryCache(\Illuminate\Support\Str::uuid()->toString(), 'location_change');
        $job->handle(app(\App\Services\PeopleDiscoveryService::class));

        $this->assertEquals(0, NearbyDiscoveryView::count());
    }

    #[Test]
    public function it_skips_incomplete_profile(): void
    {
        $location = Location::factory()->create([
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ]);

        $user = User::factory()->create([
            'location_id' => $location->id,
            'profile_complete' => false,
        ]);

        Log::shouldReceive('info')->atLeast(1);

        $job = new UpdateUserDiscoveryCache($user->id, 'vibe_change');
        $job->handle(app(\App\Services\PeopleDiscoveryService::class));

        $this->assertEquals(0, NearbyDiscoveryView::where('user_id', $user->id)->count());
    }

    #[Test]
    public function it_skips_user_without_location(): void
    {
        $user = User::factory()->create([
            'location_id' => null,
            'profile_complete' => true,
        ]);

        Log::shouldReceive('info')->atLeast(1);

        $job = new UpdateUserDiscoveryCache($user->id, 'game_system_change');
        $job->handle(app(\App\Services\PeopleDiscoveryService::class));

        $this->assertEquals(0, NearbyDiscoveryView::where('user_id', $user->id)->count());
    }

    #[Test]
    public function it_uses_discovery_queue(): void
    {
        $job = new UpdateUserDiscoveryCache(1, 'sweep');

        $this->assertEquals('discovery', $job->queue);
    }

    #[Test]
    public function it_has_three_tries(): void
    {
        $job = new UpdateUserDiscoveryCache(1, 'sweep');

        $this->assertEquals(3, $job->tries);
    }

    #[Test]
    public function it_logs_failure_on_exception(): void
    {
        $fakeUuid = \Illuminate\Support\Str::uuid()->toString();
        Log::shouldReceive('error')->once()->with('discovery.job.failed', \Mockery::on(function ($context) use ($fakeUuid) {
            return $context['user_id'] === $fakeUuid
                && $context['trigger_type'] === 'sweep'
                && isset($context['exception']);
        }));

        $job = new UpdateUserDiscoveryCache($fakeUuid, 'sweep');
        $job->failed(new \RuntimeException('test error'));
    }

    #[Test]
    public function it_stores_results_in_cache(): void
    {
        $location = Location::factory()->create([
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ]);

        $user = User::factory()->create([
            'location_id' => $location->id,
            'profile_complete' => true,
        ]);

        Log::shouldReceive('info')->atLeast(2);
        Log::shouldReceive('debug')->atLeast(0);

        $job = new UpdateUserDiscoveryCache($user->id, 'location_change');
        $job->handle(app(\App\Services\PeopleDiscoveryService::class));

        // The cache key follows the pattern people:nearby:{userId}:{geohash4}
        $geohash4 = \App\Services\Geohash::tilePrefix(self::LAT, self::LNG, 4);
        $cacheKey = "people:nearby:{$user->id}:{$geohash4}";

        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        $this->assertNotNull($cached, 'Discovery cache should be populated after job runs');
        $this->assertIsArray($cached);
    }

    #[Test]
    public function it_updates_nearby_discovery_view_with_geohash(): void
    {
        $location = Location::factory()->create([
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ]);

        $user = User::factory()->create([
            'location_id' => $location->id,
            'profile_complete' => true,
        ]);

        Log::shouldReceive('info')->atLeast(2);
        Log::shouldReceive('debug')->atLeast(0);

        $job = new UpdateUserDiscoveryCache($user->id, 'follow');
        $job->handle(app(\App\Services\PeopleDiscoveryService::class));

        $view = NearbyDiscoveryView::where('user_id', $user->id)->first();
        $this->assertNotNull($view);

        $expectedGeohash = \App\Services\Geohash::tilePrefix(self::LAT, self::LNG, 4);
        $this->assertEquals($expectedGeohash, $view->geohash_4);
    }

    #[Test]
    public function it_deletes_when_model_missing(): void
    {
        $job = new UpdateUserDiscoveryCache(\Illuminate\Support\Str::uuid()->toString(), 'location_change');

        $this->assertTrue($job->deleteWhenMissingModels);
    }

    #[Test]
    public function it_can_be_dispatched_with_various_trigger_types(): void
    {
        $triggerTypes = ['location_change', 'vibe_change', 'game_system_change', 'follow', 'unfollow', 'block', 'unblock', 'sweep', 'cache_miss_refresh'];

        foreach ($triggerTypes as $type) {
            $job = new UpdateUserDiscoveryCache(1, $type);
            $this->assertEquals($type, $job->triggerType, "Failed for trigger type: {$type}");
            $this->assertEquals(1, $job->userId);
            $this->assertEquals('discovery', $job->queue);
        }
    }

    #[Test]
    public function it_handles_user_with_location_but_no_lat_lng(): void
    {
        // Location exists but has null lat/lng
        $location = Location::factory()->create([
            'latitude' => null,
            'longitude' => null,
        ]);

        $user = User::factory()->create([
            'location_id' => $location->id,
            'profile_complete' => true,
        ]);

        Log::shouldReceive('info')->atLeast(1);

        $job = new UpdateUserDiscoveryCache($user->id, 'location_change');
        $job->handle(app(\App\Services\PeopleDiscoveryService::class));

        $this->assertEquals(0, NearbyDiscoveryView::where('user_id', $user->id)->count());
    }
}

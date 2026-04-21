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

        $job = new UpdateUserDiscoveryCache(99999, 'location_change');
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
        Log::shouldReceive('error')->once()->with('discovery.job.failed', \Mockery::on(function ($context) {
            return $context['user_id'] === 1
                && $context['trigger_type'] === 'sweep'
                && isset($context['exception']);
        }));

        $job = new UpdateUserDiscoveryCache(1, 'sweep');
        $job->failed(new \RuntimeException('test error'));
    }
}

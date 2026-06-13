<?php

namespace Tests\Unit\Jobs;

use App\Jobs\UpdateUserDiscoveryCache;
use App\Models\Location;
use App\Models\NearbyDiscoveryView;
use App\Models\User;
use App\Services\PeopleDiscoveryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UpdateUserDiscoveryCacheTest extends TestCase
{
    use DatabaseTransactions;

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
        $job->handle(app(PeopleDiscoveryService::class));

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

        $job = new UpdateUserDiscoveryCache(Str::uuid()->toString(), 'location_change');
        $job->handle(app(PeopleDiscoveryService::class));

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
        $job->handle(app(PeopleDiscoveryService::class));

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
        $job->handle(app(PeopleDiscoveryService::class));

        $this->assertEquals(0, NearbyDiscoveryView::where('user_id', $user->id)->count());
    }

    #[Test]
    public function it_logs_failure_on_exception(): void
    {
        $fakeUuid = Str::uuid()->toString();
        Log::shouldReceive('error')->once()->with('discovery.job.failed', \Mockery::on(function ($context) use ($fakeUuid) {
            return $context['user_id'] === $fakeUuid
                && $context['trigger_type'] === 'sweep'
                && isset($context['exception']);
        }));

        $job = new UpdateUserDiscoveryCache($fakeUuid, 'sweep');
        $job->failed(new \RuntimeException('test error'));
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
        $job->handle(app(PeopleDiscoveryService::class));

        $this->assertEquals(0, NearbyDiscoveryView::where('user_id', $user->id)->count());
    }
}

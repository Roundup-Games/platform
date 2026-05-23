<?php

namespace Tests\Unit\Jobs;

use App\Jobs\WarmDashboardCache;
use App\Models\Location;
use App\Models\User;
use App\Services\DashboardCacheService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WarmDashboardCacheTest extends TestCase
{
    use DatabaseTransactions;

    private const LAT = 52.5163;
    private const LNG = 13.3777;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Log::spy();
    }

    #[Test]
    public function it_warms_contributions_feed_and_opportunities(): void
    {
        $location = Location::factory()->create([
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ]);

        $user = User::factory()->create([
            'location_id' => $location->id,
        ]);

        Log::shouldReceive('info')->atLeast(2);
        Log::shouldReceive('debug')->atLeast(0);

        $job = new WarmDashboardCache((string) $user->id, 'cache_miss_week');
        $job->handle(app(DashboardCacheService::class));

        // Contributions should be cached
        $contributions = Cache::get("dashboard:contributions:{$user->id}");
        $this->assertNotNull($contributions);
        $this->assertArrayHasKey('hosted', $contributions);
        $this->assertArrayHasKey('played', $contributions);

        // Feed should be cached
        $feed = Cache::get("dashboard:feed:{$user->id}");
        $this->assertNotNull($feed);
        $this->assertArrayHasKey('items', $feed);

        // Opportunities should be cached (user has a location)
        $geohash4 = \App\Services\Geohash::tilePrefix(self::LAT, self::LNG, 4);
        $opportunities = Cache::get("dashboard:opportunities:{$user->id}:{$geohash4}");
        $this->assertNotNull($opportunities);
        $this->assertArrayHasKey('games', $opportunities);
    }

    #[Test]
    public function it_skips_opportunities_when_user_has_no_location(): void
    {
        $user = User::factory()->create([
            'location_id' => null,
        ]);

        Log::shouldReceive('info')->atLeast(2);
        Log::shouldReceive('debug')->atLeast(0);

        $job = new WarmDashboardCache((string) $user->id, 'cache_miss_feed');
        $job->handle(app(DashboardCacheService::class));

        // Contributions and feed should still be cached
        $this->assertNotNull(Cache::get("dashboard:contributions:{$user->id}"));
        $this->assertNotNull(Cache::get("dashboard:feed:{$user->id}"));

        // No opportunity keys should exist for this user
        $trackedKeys = Cache::get("dashboard:opportunities:keys:{$user->id}");
        $this->assertEmpty($trackedKeys ?? []);
    }

    #[Test]
    public function it_skips_opportunities_when_location_has_no_coordinates(): void
    {
        $location = Location::factory()->create([
            'latitude' => null,
            'longitude' => null,
        ]);

        $user = User::factory()->create([
            'location_id' => $location->id,
        ]);

        Log::shouldReceive('info')->atLeast(2);
        Log::shouldReceive('debug')->atLeast(0);

        $job = new WarmDashboardCache((string) $user->id, 'cache_miss_feed');
        $job->handle(app(DashboardCacheService::class));

        $this->assertNotNull(Cache::get("dashboard:contributions:{$user->id}"));
        $this->assertNotNull(Cache::get("dashboard:feed:{$user->id}"));
    }

    #[Test]
    public function it_handles_missing_user_gracefully(): void
    {
        $fakeId = (string) \Illuminate\Support\Str::orderedUuid();

        Log::shouldReceive('info')->atLeast(1);
        Log::shouldReceive('warning')->once()->with(
            'dashboard.warm.user_not_found',
            \Mockery::on(fn ($ctx) => $ctx['user_id'] === $fakeId),
        );

        $job = new WarmDashboardCache($fakeId, 'cache_miss_week');
        $job->handle(app(DashboardCacheService::class));

        // No cache entries should be created
        $this->assertNull(Cache::get("dashboard:contributions:{$fakeId}"));
        $this->assertNull(Cache::get("dashboard:feed:{$fakeId}"));
    }

    #[Test]
    public function it_logs_start_and_completed_with_duration(): void
    {
        $user = User::factory()->create();

        Log::shouldReceive('info')->once()->with(
            'dashboard.warm.started',
            \Mockery::on(fn ($ctx) => $ctx['user_id'] === (string) $user->id
                && $ctx['trigger_type'] === 'cache_miss_week',
        ));

        Log::shouldReceive('info')->once()->with(
            'dashboard.warm.completed',
            \Mockery::on(fn ($ctx) => $ctx['user_id'] === (string) $user->id
                && isset($ctx['duration_ms'])
                && isset($ctx['item_counts']),
        ));

        $job = new WarmDashboardCache((string) $user->id, 'cache_miss_week');
        $job->handle(app(DashboardCacheService::class));
    }

    #[Test]
    public function it_logs_failure_on_exception(): void
    {
        $userId = (string) \Illuminate\Support\Str::orderedUuid();

        Log::shouldReceive('error')->once()->with(
            'dashboard.warm.failed',
            \Mockery::on(fn ($ctx) => $ctx['user_id'] === $userId
                && $ctx['trigger_type'] === 'sweep'
                && isset($ctx['exception']),
            ),
        );

        $job = new WarmDashboardCache($userId, 'sweep');
        $job->failed(new \RuntimeException('test error'));
    }

    #[Test]
    public function it_uses_discovery_queue(): void
    {
        $job = new WarmDashboardCache('123', 'cache_miss_week');

        $this->assertEquals('discovery', $job->queue);
    }

    #[Test]
    public function it_has_unique_id_per_user(): void
    {
        $job1 = new WarmDashboardCache('user-1', 'cache_miss_week');
        $job2 = new WarmDashboardCache('user-2', 'cache_miss_week');

        $this->assertEquals('user-1', $job1->uniqueId());
        $this->assertEquals('user-2', $job2->uniqueId());
        $this->assertNotEquals($job1->uniqueId(), $job2->uniqueId());
    }

    #[Test]
    public function it_sets_correct_job_properties(): void
    {
        $job = new WarmDashboardCache('123', 'cache_miss_week');

        $this->assertEquals(3, $job->tries);
        $this->assertEquals(120, $job->timeout);
        $this->assertTrue($job->deleteWhenMissingModels);
    }

    #[Test]
    public function same_user_and_different_trigger_shares_unique_id(): void
    {
        $job1 = new WarmDashboardCache('user-1', 'cache_miss_week');
        $job2 = new WarmDashboardCache('user-1', 'sweep');

        // Same uniqueId regardless of trigger type — prevents stacking
        $this->assertEquals($job1->uniqueId(), $job2->uniqueId());
    }

    #[Test]
    public function it_logs_item_counts_in_completed_message(): void
    {
        $user = User::factory()->create();

        Log::shouldReceive('info')->once()->with(
            'dashboard.warm.completed',
            \Mockery::on(function ($ctx) {
                $counts = $ctx['item_counts'] ?? [];
                return isset($counts['contributions'])
                    && isset($counts['feed'])
                    && isset($counts['opportunities']);
            }),
        );

        Log::shouldReceive('info')->once()->with('dashboard.warm.started', \Mockery::any());

        $job = new WarmDashboardCache((string) $user->id, 'cache_miss_week');
        $job->handle(app(DashboardCacheService::class));
    }
}

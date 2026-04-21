<?php

use App\Jobs\UpdateUserDiscoveryCache;
use App\Models\Location;
use App\Models\NearbyDiscoveryView;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

describe('SweepActiveDiscoveryCaches command', function () {
    it('runs successfully with no active users', function () {
        $this->artisan('discovery:sweep-active')
            ->assertSuccessful()
            ->expectsOutput('No active users to sweep.');
    });

    it('runs with --dry-run without dispatching jobs', function () {
        Queue::fake();

        $location = Location::factory()->create();
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $location->id,
        ]);
        // Use a geohash guaranteed to differ from the location's computed one
        NearbyDiscoveryView::create([
            'user_id' => $user->id,
            'last_discovery_view' => now()->subMinutes(5),
            'geohash_4' => 'zzzz',
        ]);

        $this->artisan('discovery:sweep-active --dry-run')
            ->assertSuccessful()
            ->expectsOutputToContain('Would dispatch');

        Queue::assertNotPushed(UpdateUserDiscoveryCache::class);
    });

    it('dispatches jobs for users with changed geohash', function () {
        Queue::fake();

        $location = Location::factory()->create();
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $location->id,
        ]);
        // Use a geohash guaranteed to differ from the location's computed one
        NearbyDiscoveryView::create([
            'user_id' => $user->id,
            'last_discovery_view' => now()->subMinutes(5),
            'geohash_4' => 'zzzz',
        ]);

        $this->artisan('discovery:sweep-active')
            ->assertSuccessful()
            ->expectsOutputToContain('Dispatched: 1');

        Queue::assertPushed(UpdateUserDiscoveryCache::class, function ($job) use ($user) {
            return $job->userId === $user->id && $job->triggerType === 'sweep';
        });
    });

    it('skips users whose cached geohash matches current location', function () {
        Queue::fake();

        $location = Location::factory()->create();
        $actualGeohash = $location->fresh()->geohash_4;
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $location->id,
        ]);
        // Use the actual geohash from the location → should be skipped
        NearbyDiscoveryView::create([
            'user_id' => $user->id,
            'last_discovery_view' => now()->subMinutes(5),
            'geohash_4' => $actualGeohash,
        ]);

        $this->artisan('discovery:sweep-active')
            ->assertSuccessful()
            ->expectsOutputToContain('Skipped (location unchanged): 1');

        Queue::assertNotPushed(UpdateUserDiscoveryCache::class);
    });

    it('skips users without profile_complete', function () {
        Queue::fake();

        $location = Location::factory()->create();
        $user = User::factory()->create([
            'profile_complete' => false,
            'location_id' => $location->id,
        ]);
        NearbyDiscoveryView::create([
            'user_id' => $user->id,
            'last_discovery_view' => now()->subMinutes(5),
            'geohash_4' => 'zzzz',
        ]);

        $this->artisan('discovery:sweep-active')
            ->assertSuccessful()
            ->expectsOutputToContain('No active users to sweep.');

        Queue::assertNotPushed(UpdateUserDiscoveryCache::class);
    });

    it('skips users without location_id', function () {
        Queue::fake();

        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => null,
        ]);
        NearbyDiscoveryView::create([
            'user_id' => $user->id,
            'last_discovery_view' => now()->subMinutes(5),
            'geohash_4' => 'zzzz',
        ]);

        $this->artisan('discovery:sweep-active')
            ->assertSuccessful()
            ->expectsOutputToContain('No active users to sweep.');

        Queue::assertNotPushed(UpdateUserDiscoveryCache::class);
    });

    it('skips users outside the lookback window', function () {
        Queue::fake();

        $location = Location::factory()->create();
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $location->id,
        ]);
        NearbyDiscoveryView::create([
            'user_id' => $user->id,
            'last_discovery_view' => now()->subHours(3), // outside default 60m window
            'geohash_4' => 'zzzz',
        ]);

        $this->artisan('discovery:sweep-active')
            ->assertSuccessful()
            ->expectsOutputToContain('No active users to sweep.');

        Queue::assertNotPushed(UpdateUserDiscoveryCache::class);
    });

    it('respects custom --window option', function () {
        Queue::fake();

        $location = Location::factory()->create();
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $location->id,
        ]);
        NearbyDiscoveryView::create([
            'user_id' => $user->id,
            'last_discovery_view' => now()->subMinutes(120), // 2h ago
            'geohash_4' => 'zzzz',
        ]);

        // Default window (60m) should miss this user
        $this->artisan('discovery:sweep-active')
            ->assertSuccessful()
            ->expectsOutputToContain('No active users to sweep.');

        // Extended window (180m) should catch this user
        $this->artisan('discovery:sweep-active --window=180')
            ->assertSuccessful()
            ->expectsOutputToContain('Dispatched: 1');

        Queue::assertPushed(UpdateUserDiscoveryCache::class, 1);
    });

    it('dispatches for users with null cached geohash but valid location', function () {
        Queue::fake();

        $location = Location::factory()->create();
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $location->id,
        ]);
        NearbyDiscoveryView::create([
            'user_id' => $user->id,
            'last_discovery_view' => now()->subMinutes(5),
            'geohash_4' => null, // no prior cache → should dispatch
        ]);

        $this->artisan('discovery:sweep-active')
            ->assertSuccessful()
            ->expectsOutputToContain('Dispatched: 1');

        Queue::assertPushed(UpdateUserDiscoveryCache::class, 1);
    });

    it('logs structured sweep started and completed fields', function () {
        Queue::fake();

        $location = Location::factory()->create();
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $location->id,
        ]);
        NearbyDiscoveryView::create([
            'user_id' => $user->id,
            'last_discovery_view' => now()->subMinutes(5),
            'geohash_4' => 'zzzz',
        ]);

        $log = Log::spy();

        $this->artisan('discovery:sweep-active')
            ->assertSuccessful();

        // Verify started log
        $log->shouldHaveReceived('info', function ($message, $context) {
            return $message === 'discovery.sweep.started'
                && isset($context['window_minutes'])
                && isset($context['dry_run']);
        });

        // Verify completed log with structured fields
        $log->shouldHaveReceived('info', function ($message, $context) {
            return $message === 'discovery.sweep.completed'
                && isset($context['user_count'])
                && isset($context['job_dispatch_count'])
                && isset($context['skip_count'])
                && isset($context['duration_ms'])
                && isset($context['dry_run'])
                && isset($context['window_minutes']);
        });
    });
});

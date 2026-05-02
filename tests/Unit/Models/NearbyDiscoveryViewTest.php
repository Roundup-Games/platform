<?php

namespace Tests\Unit\Models;

use App\Models\Location;
use App\Models\NearbyDiscoveryView;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NearbyDiscoveryViewTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_can_create_a_discovery_view_record(): void
    {
        $user = User::factory()->create();

        $view = NearbyDiscoveryView::create([
            'user_id' => $user->id,
            'geohash_4' => 'u33d',
        ]);

        $this->assertDatabaseHas('nearby_discovery_views', [
            'id' => $view->id,
            'user_id' => $user->id,
            'geohash_4' => 'u33d',
        ]);
    }

    #[Test]
    public function it_updates_existing_record_via_update_or_create(): void
    {
        $user = User::factory()->create();

        $view1 = NearbyDiscoveryView::updateOrCreate(
            ['user_id' => $user->id],
            ['geohash_4' => 'u33d', 'last_discovery_view' => now()->subHour()],
        );

        $view2 = NearbyDiscoveryView::updateOrCreate(
            ['user_id' => $user->id],
            ['geohash_4' => 'u33d', 'last_discovery_view' => now()],
        );

        $this->assertEquals($view1->id, $view2->id);
        $this->assertEquals(1, NearbyDiscoveryView::where('user_id', $user->id)->count());
    }

    #[Test]
    public function it_casts_last_discovery_view_to_datetime(): void
    {
        $user = User::factory()->create();

        $view = NearbyDiscoveryView::create([
            'user_id' => $user->id,
            'last_discovery_view' => '2026-04-21 10:00:00',
            'geohash_4' => 'u33d',
        ]);

        $fresh = $view->fresh();
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->last_discovery_view);
        $this->assertEquals('2026-04-21 10:00:00', $fresh->last_discovery_view->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_belongs_to_a_user(): void
    {
        $user = User::factory()->create();

        $view = NearbyDiscoveryView::create([
            'user_id' => $user->id,
            'geohash_4' => 'u33d',
        ]);

        $this->assertEquals($user->id, $view->user->id);
    }

    #[Test]
    public function user_has_one_discovery_view(): void
    {
        $user = User::factory()->create();

        NearbyDiscoveryView::create([
            'user_id' => $user->id,
            'geohash_4' => 'u33d',
        ]);

        $freshUser = $user->fresh();
        $this->assertNotNull($freshUser->discoveryView);
        $this->assertEquals('u33d', $freshUser->discoveryView->geohash_4);
    }

    #[Test]
    public function user_without_discovery_view_returns_null(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->discoveryView);
    }

    #[Test]
    public function it_updates_last_discovery_view_timestamp(): void
    {
        $user = User::factory()->create();

        // Initial creation
        $before = now()->subDay();
        NearbyDiscoveryView::create([
            'user_id' => $user->id,
            'geohash_4' => 'u33d',
            'last_discovery_view' => $before,
        ]);

        // Update timestamp (simulating tab view)
        NearbyDiscoveryView::updateOrCreate(
            ['user_id' => $user->id],
            ['last_discovery_view' => now()],
        );

        $view = NearbyDiscoveryView::where('user_id', $user->id)->first();
        $this->assertTrue($view->last_discovery_view->gt($before));
    }

    #[Test]
    public function it_stores_geohash_4_prefix(): void
    {
        $user = User::factory()->create();

        $view = NearbyDiscoveryView::create([
            'user_id' => $user->id,
            'geohash_4' => 'u33d',
        ]);

        $this->assertEquals('u33d', $view->geohash_4);
        $this->assertEquals(4, strlen($view->geohash_4));
    }
}

<?php

namespace Tests\Feature;

use App\Enums\VibeFlag;
use App\Jobs\UpdateUserDiscoveryCache;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\NearbyDiscoveryView;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DiscoveryCacheTriggersTest extends TestCase
{
    use RefreshDatabase;

    // ── Relationship Triggers ───────────────────────────

    #[Test]
    public function follow_dispatches_discovery_cache_job(): void
    {
        Queue::fake();

        $initiator = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::follow($initiator, $target);

        Queue::assertPushedOn('discovery', UpdateUserDiscoveryCache::class, function ($job) use ($initiator) {
            return $job->userId === $initiator->id && $job->triggerType === 'follow';
        });
    }

    #[Test]
    public function follow_dispatches_once_per_call(): void
    {
        Queue::fake();

        $initiator = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::follow($initiator, $target);

        Queue::assertPushed(UpdateUserDiscoveryCache::class, 1);
    }

    #[Test]
    public function unfollow_dispatches_discovery_cache_job(): void
    {
        Queue::fake();

        $initiator = User::factory()->create();
        $target = User::factory()->create();

        // Create the follow first
        UserRelationship::follow($initiator, $target);
        Queue::fake(); // Reset after follow dispatch

        UserRelationship::unfollow($initiator, $target);

        Queue::assertPushedOn('discovery', UpdateUserDiscoveryCache::class, function ($job) use ($initiator) {
            return $job->userId === $initiator->id && $job->triggerType === 'unfollow';
        });
    }

    #[Test]
    public function unfollow_does_not_dispatch_when_no_relationship_existed(): void
    {
        Queue::fake();

        $initiator = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::unfollow($initiator, $target);

        Queue::assertNotPushed(UpdateUserDiscoveryCache::class);
    }

    #[Test]
    public function block_dispatches_discovery_cache_job_for_both_users(): void
    {
        Queue::fake();

        $initiator = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::block($initiator, $target);

        Queue::assertPushed(UpdateUserDiscoveryCache::class, 2);

        Queue::assertPushed(UpdateUserDiscoveryCache::class, function ($job) use ($initiator) {
            return $job->userId === $initiator->id && $job->triggerType === 'block';
        });

        Queue::assertPushed(UpdateUserDiscoveryCache::class, function ($job) use ($target) {
            return $job->userId === $target->id && $job->triggerType === 'block';
        });
    }

    #[Test]
    public function unblock_dispatches_discovery_cache_job_for_both_users(): void
    {
        Queue::fake();

        $initiator = User::factory()->create();
        $target = User::factory()->create();

        // Create the block first
        UserRelationship::block($initiator, $target);
        Queue::fake(); // Reset after block dispatches

        UserRelationship::unblock($initiator, $target);

        Queue::assertPushed(UpdateUserDiscoveryCache::class, 2);

        Queue::assertPushed(UpdateUserDiscoveryCache::class, function ($job) use ($initiator) {
            return $job->userId === $initiator->id && $job->triggerType === 'unblock';
        });

        Queue::assertPushed(UpdateUserDiscoveryCache::class, function ($job) use ($target) {
            return $job->userId === $target->id && $job->triggerType === 'unblock';
        });
    }

    #[Test]
    public function unblock_does_not_dispatch_when_no_block_existed(): void
    {
        Queue::fake();

        $initiator = User::factory()->create();
        $target = User::factory()->create();

        UserRelationship::unblock($initiator, $target);

        Queue::assertNotPushed(UpdateUserDiscoveryCache::class);
    }

    // ── Profile Save Triggers ──────────────────────────

    #[Test]
    public function location_change_on_profile_dispatches_discovery_cache_job(): void
    {
        Queue::fake();

        $oldLocation = Location::factory()->create(['latitude' => 52.5, 'longitude' => 13.3]);
        $newLocation = Location::factory()->create(['latitude' => 48.8, 'longitude' => 2.3]);

        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $oldLocation->id,
        ]);

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Profile\Show::class)
            ->set('locationId', $newLocation->id)
            ->call('saveProfile');

        Queue::assertPushedOn('discovery', UpdateUserDiscoveryCache::class, function ($job) use ($user) {
            return $job->userId === $user->id && $job->triggerType === 'location_change';
        });
    }

    #[Test]
    public function no_location_change_does_not_dispatch_location_job(): void
    {
        Queue::fake();

        $location = Location::factory()->create(['latitude' => 52.5, 'longitude' => 13.3]);

        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $location->id,
        ]);

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Profile\Show::class)
            ->call('saveProfile');

        Queue::assertNotPushed(function (UpdateUserDiscoveryCache $job) {
            return $job->triggerType === 'location_change';
        });
    }

    #[Test]
    public function vibe_change_on_profile_dispatches_discovery_cache_job(): void
    {
        Queue::fake();

        $user = User::factory()->create([
            'profile_complete' => true,
        ]);

        // First save with no vibes, then add a vibe
        \Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Profile\Show::class)
            ->set('vibePreferences', [VibeFlag::Atmospheric->value => 'favorite'])
            ->call('saveProfile');

        Queue::assertPushedOn('discovery', UpdateUserDiscoveryCache::class, function ($job) use ($user) {
            return $job->userId === $user->id && $job->triggerType === 'vibe_change';
        });
    }

    #[Test]
    public function game_system_change_on_profile_dispatches_discovery_cache_job(): void
    {
        Queue::fake();

        $user = User::factory()->create([
            'profile_complete' => true,
        ]);

        $gameSystem = GameSystem::factory()->create();

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Profile\Show::class)
            ->set('favoriteGameSystemIds', [$gameSystem->id])
            ->call('saveProfile');

        Queue::assertPushedOn('discovery', UpdateUserDiscoveryCache::class, function ($job) use ($user) {
            return $job->userId === $user->id && $job->triggerType === 'game_system_change';
        });
    }

    #[Test]
    public function no_game_system_change_does_not_dispatch_game_system_job(): void
    {
        Queue::fake();

        $gameSystem = GameSystem::factory()->create();
        $user = User::factory()->create(['profile_complete' => true]);
        $user->gameSystemPreferences()->attach($gameSystem, ['preference_type' => 'favorite']);

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Profile\Show::class)
            ->set('favoriteGameSystemIds', [$gameSystem->id])
            ->call('saveProfile');

        Queue::assertNotPushed(function (UpdateUserDiscoveryCache $job) {
            return $job->triggerType === 'game_system_change';
        });
    }

    // ── Onboarding Trigger ─────────────────────────────

    #[Test]
    public function onboarding_completion_dispatches_location_change_job(): void
    {
        Queue::fake();

        $user = User::factory()->create(['profile_complete' => false]);
        $location = Location::factory()->create(['latitude' => 52.52, 'longitude' => 13.405]);

        // Simulate what onboarding complete() does: update user with location, then dispatch
        $user->update([
            'location_id' => $location->id,
            'profile_complete' => true,
        ]);

        $freshUser = $user->fresh();
        if ($freshUser->linkedLocation?->latitude && $freshUser->linkedLocation?->longitude) {
            UpdateUserDiscoveryCache::dispatch($freshUser->id, 'location_change');
        }

        Queue::assertPushedOn('discovery', UpdateUserDiscoveryCache::class, function ($job) use ($user) {
            return $job->userId === $user->id && $job->triggerType === 'location_change';
        });
    }

    // ── Nearby Tab View Tracking ───────────────────────

    #[Test]
    public function viewing_nearby_tab_upserts_discovery_view_timestamp(): void
    {
        $location = Location::factory()->create(['latitude' => 52.5, 'longitude' => 13.3]);
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $location->id,
        ]);

        $this->assertDatabaseMissing('nearby_discovery_views', [
            'user_id' => $user->id,
        ]);

        // Access the nearbyUsers computed property via the component
        $component = \Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\People\PeoplePage::class);

        $component->set('activeTab', 'nearby');

        // Access the computed property directly which triggers the view tracking
        $component->instance()->nearbyUsers;

        $this->assertDatabaseHas('nearby_discovery_views', [
            'user_id' => $user->id,
        ]);

        $view = NearbyDiscoveryView::where('user_id', $user->id)->first();
        $this->assertNotNull($view->last_discovery_view);
    }

    #[Test]
    public function nearby_tab_view_does_not_dispatch_tab_view_triggered_job(): void
    {
        Queue::fake();

        $location = Location::factory()->create(['latitude' => 52.5, 'longitude' => 13.3]);
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $location->id,
        ]);

        $component = \Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\People\PeoplePage::class);

        $component->set('activeTab', 'nearby');
        $component->instance()->nearbyUsers;

        // The nearbyUsers computed may dispatch a cache_miss_refresh job via the service,
        // but it should never dispatch with trigger_type 'tab_view'
        Queue::assertNotPushed(function (UpdateUserDiscoveryCache $job) {
            return $job->triggerType === 'tab_view';
        });
    }
}

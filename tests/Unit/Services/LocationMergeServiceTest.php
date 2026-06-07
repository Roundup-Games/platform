<?php

namespace Tests\Unit\Services;

use App\Models\Event;
use App\Models\Game;
use App\Models\Location;
use App\Models\User;
use App\Services\LocationMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LocationMergeServiceTest extends TestCase
{
    use RefreshDatabase;

    private LocationMergeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LocationMergeService::class);
    }

    #[Test]
    public function merge_reassigns_games_to_target(): void
    {
        $source = Location::factory()->create(['name' => 'Source Location']);
        $target = Location::factory()->create(['name' => 'Target Location']);
        $game = Game::factory()->create(['location_id' => $source->id]);

        $result = $this->service->merge($source, $target);

        $this->assertEquals(1, $result['games']);
        $this->assertEquals($target->id, $game->fresh()->location_id);
        $this->assertDatabaseMissing('locations', ['id' => $source->id]);
    }

    #[Test]
    public function merge_reassigns_events_to_target(): void
    {
        $source = Location::factory()->create();
        $target = Location::factory()->create();
        $event = Event::factory()->create(['location_id' => $source->id]);

        $result = $this->service->merge($source, $target);

        $this->assertEquals(1, $result['events']);
        $this->assertEquals($target->id, $event->fresh()->location_id);
    }

    #[Test]
    public function merge_reassigns_users_to_target(): void
    {
        $source = Location::factory()->create();
        $target = Location::factory()->create();
        $user = User::factory()->create(['location_id' => $source->id]);

        $result = $this->service->merge($source, $target);

        $this->assertEquals(1, $result['users']);
        $this->assertEquals($target->id, $user->fresh()->location_id);
    }

    #[Test]
    public function merge_deletes_source_location(): void
    {
        $source = Location::factory()->create();
        $target = Location::factory()->create();

        $this->service->merge($source, $target);

        $this->assertDatabaseMissing('locations', ['id' => $source->id]);
        $this->assertDatabaseHas('locations', ['id' => $target->id]);
    }

    #[Test]
    public function merge_returns_source_and_target_ids(): void
    {
        $source = Location::factory()->create();
        $target = Location::factory()->create();

        $result = $this->service->merge($source, $target);

        $this->assertEquals($source->id, $result['source_id']);
        $this->assertEquals($target->id, $result['target_id']);
    }

    #[Test]
    public function merge_logs_the_action(): void
    {
        Log::spy();

        $source = Location::factory()->create();
        $target = Location::factory()->create();

        $this->service->merge($source, $target);

        Log::shouldHaveReceived('info')
            ->with('Location merge completed', \Mockery::on(function ($context) use ($source, $target) {
                return $context['source_id'] === $source->id
                    && $context['target_id'] === $target->id
                    && isset($context['counts']);
            }));
    }

    #[Test]
    public function merge_handles_zero_references(): void
    {
        $source = Location::factory()->create();
        $target = Location::factory()->create();

        $result = $this->service->merge($source, $target);

        $this->assertEquals(0, $result['games']);
        $this->assertEquals(0, $result['events']);
        $this->assertEquals(0, $result['campaigns']);
        $this->assertEquals(0, $result['users']);
    }
}

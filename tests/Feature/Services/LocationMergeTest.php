<?php

use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use App\Services\LocationMergeService;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->service = app(LocationMergeService::class);
    $this->source = Location::factory()->create(['name' => 'Source Location']);
    $this->target = Location::factory()->create(['name' => 'Target Location']);
});

describe('FK reassignment', function () {
    test('merge reassigns games from source to target', function () {
        $game = Game::factory()->create(['location_id' => $this->source->id]);

        $result = $this->service->merge($this->source, $this->target);

        expect($result['games'])->toBe(1);
        expect($game->fresh()->location_id)->toBe($this->target->id);
    });

    test('merge reassigns events from source to target', function () {
        $event = Event::factory()->create(['location_id' => $this->source->id]);

        $result = $this->service->merge($this->source, $this->target);

        expect($result['events'])->toBe(1);
        expect($event->fresh()->location_id)->toBe($this->target->id);
    });

    test('merge reassigns campaigns from source to target', function () {
        $campaign = Campaign::factory()->create([
            'location_id' => $this->source->id,
            'owner_id' => User::factory()->create(),
            'game_system_id' => GameSystem::factory()->create(),
        ]);

        $result = $this->service->merge($this->source, $this->target);

        expect($result['campaigns'])->toBe(1);
        expect($campaign->fresh()->location_id)->toBe($this->target->id);
    });

    test('merge reassigns users from source to target', function () {
        $user = User::factory()->create(['location_id' => $this->source->id]);

        $result = $this->service->merge($this->source, $this->target);

        expect($result['users'])->toBe(1);
        expect($user->fresh()->location_id)->toBe($this->target->id);
    });

    test('merge reassigns all FK types together', function () {
        $game = Game::factory()->create(['location_id' => $this->source->id]);
        $event = Event::factory()->create(['location_id' => $this->source->id]);
        $campaign = Campaign::factory()->create([
            'location_id' => $this->source->id,
            'owner_id' => User::factory()->create(),
            'game_system_id' => GameSystem::factory()->create(),
        ]);
        $user = User::factory()->create(['location_id' => $this->source->id]);

        $result = $this->service->merge($this->source, $this->target);

        expect($result)->toBe([
            'source_id' => $this->source->id,
            'target_id' => $this->target->id,
            'games' => 1,
            'events' => 1,
            'campaigns' => 1,
            'users' => 1,
        ]);
    });
});

describe('source deletion', function () {
    test('merge deletes the source location', function () {
        $this->service->merge($this->source, $this->target);

        expect(Location::find($this->source->id))->toBeNull();
        expect(Location::find($this->target->id))->not->toBeNull();
    });
});

describe('logging', function () {
    test('merge logs the completed action with source, target and counts', function () {
        Log::spy();

        $this->service->merge($this->source, $this->target);

        Log::shouldHaveReceived('info')
            ->with('Location merge completed', Mockery::on(function ($context) {
                return $context['source_id'] === $this->source->id
                    && $context['target_id'] === $this->target->id
                    && isset($context['counts']);
            }));
    });
});

describe('merge with no dependencies', function () {
    test('clean merge returns zero counts', function () {
        $result = $this->service->merge($this->source, $this->target);

        expect($result['games'])->toBe(0);
        expect($result['events'])->toBe(0);
        expect($result['campaigns'])->toBe(0);
        expect($result['users'])->toBe(0);
    });
});

describe('transaction safety', function () {
    test('merge is wrapped in a transaction', function () {
        $game = Game::factory()->create(['location_id' => $this->source->id]);
        $sourceId = $this->source->id;

        // Force a failure during source delete to trigger rollback.
        Location::deleting(function ($location) use ($sourceId) {
            if ($location->id === $sourceId) {
                throw new RuntimeException('forced delete failure');
            }
        });

        expect(fn () => $this->service->merge($this->source, $this->target))
            ->toThrow(RuntimeException::class);

        // Rollback check: no partial reassignment persisted.
        expect(Location::find($sourceId))->not->toBeNull();
        expect($game->fresh()->location_id)->toBe($sourceId);
    });
});

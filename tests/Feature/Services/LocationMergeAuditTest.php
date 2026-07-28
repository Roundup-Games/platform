<?php

use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\Location;
use App\Models\User;
use App\Services\LocationMergeService;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->service = app(LocationMergeService::class);
    $this->source = Location::factory()->create(['name' => 'Source Location']);
    $this->target = Location::factory()->create(['name' => 'Target Location']);
});

// ── Core behaviour: FK reassignment + source deletion + counts ─────────
//
// merge() reassigns location_id across games/events/campaigns/users inside a
// DB::transaction, deletes the source, and returns per-table counts. The
// previous version of this file asserted ONLY the merged_by log context — a
// regression that commented out the entire transaction would have passed as
// long as the log line still fired. These tests prove the transaction ran.

describe('merge mechanics', function () {
    it('reassigns all foreign keys from source to target, deletes source, and returns counts', function () {
        // One row in each affected table pointing at the source location.
        $game = Game::factory()->create(['location_id' => $this->source->id]);
        $event = Event::factory()->create(['location_id' => $this->source->id]);
        $campaign = Campaign::factory()->create(['location_id' => $this->source->id]);
        $user = User::factory()->create(['location_id' => $this->source->id]);

        // Silence the merge-completed log so it doesn't bleed into other tests.
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $result = $this->service->merge($this->source, $this->target);

        // Every FK was reassigned to the target.
        expect($game->fresh()->location_id)->toBe($this->target->id)
            ->and($event->fresh()->location_id)->toBe($this->target->id)
            ->and($campaign->fresh()->location_id)->toBe($this->target->id)
            ->and($user->fresh()->location_id)->toBe($this->target->id);

        // The source location was deleted.
        expect(Location::find($this->source->id))->toBeNull();

        // Returned counts reflect exactly what was moved.
        expect($result)->toBe([
            'source_id' => (string) $this->source->id,
            'target_id' => (string) $this->target->id,
            'games' => 1,
            'events' => 1,
            'campaigns' => 1,
            'users' => 1,
        ]);
    });

    it('reports zero counts when no rows reference the source', function () {
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $result = $this->service->merge($this->source, $this->target);

        expect($result)->toHaveKey('games', 0)
            ->and($result)->toHaveKey('events', 0)
            ->and($result)->toHaveKey('campaigns', 0)
            ->and($result)->toHaveKey('users', 0)
            ->and(Location::find($this->source->id))->toBeNull();
    });
});

// ── actedBy user resolution ───────────────────────────────────────────
//
// merged_by surfaces ONLY in the log (the return value has no actor field),
// so the log assertion here is the legitimate contract — but each test also
// proves the merge actually ran (game moved + source deleted + count = 1)
// rather than passing on a no-op that still logged.

describe('actedBy user parameter', function () {
    it('merge accepts explicit acting user', function () {
        $admin = User::factory()->create();
        $game = Game::factory()->create(['location_id' => $this->source->id]);

        Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) use ($admin) {
            return $message === 'Location merge completed'
                && $context['merged_by'] === $admin->id;
        });

        $result = $this->service->merge($this->source, $this->target, $admin);

        expect($game->fresh()->location_id)->toBe($this->target->id)
            ->and($result['games'])->toBe(1)
            ->and(Location::find($this->source->id))->toBeNull();
    });

    it('merge falls back to auth user when actedBy is null', function () {
        $user = User::factory()->create();
        $this->actingAs($user);
        $game = Game::factory()->create(['location_id' => $this->source->id]);

        Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) use ($user) {
            return $message === 'Location merge completed'
                && $context['merged_by'] === $user->id;
        });

        $result = $this->service->merge($this->source, $this->target);

        expect($game->fresh()->location_id)->toBe($this->target->id)
            ->and($result['games'])->toBe(1)
            ->and(Location::find($this->source->id))->toBeNull();
    });

    it('merge logs null merged_by when no user in context', function () {
        $game = Game::factory()->create(['location_id' => $this->source->id]);

        Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) {
            return $message === 'Location merge completed'
                && $context['merged_by'] === null;
        });

        $result = $this->service->merge($this->source, $this->target, null);

        expect($game->fresh()->location_id)->toBe($this->target->id)
            ->and($result['games'])->toBe(1)
            ->and(Location::find($this->source->id))->toBeNull();
    });
});

describe('self-merge guard', function () {
    it('merge rejects merging a location into itself', function () {
        expect(fn () => $this->service->merge($this->source, $this->source))
            ->toThrow(InvalidArgumentException::class, 'Cannot merge a location into itself');
    });
});

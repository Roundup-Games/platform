<?php

use App\Models\Game;
use App\Models\GameSystem;
use Illuminate\Database\Eloquent\ModelNotFoundException;

// UuidBuilder (via HasPlatformUuid) treats a non-uuid id as "not found"
// instead of letting Postgres reject it at bind time (SQLSTATE 22P02 → 500).
describe('HasPlatformUuid id lookups', function () {
    it('returns null from find() for a non-uuid id', function () {
        expect(GameSystem::find('not-a-uuid'))->toBeNull()
            ->and(GameSystem::find("1' OR '1'='1"))->toBeNull()
            ->and(GameSystem::find(42))->toBeNull();
    });

    it('throws ModelNotFoundException from findOrFail() for a non-uuid id', function () {
        GameSystem::findOrFail('not-a-uuid');
    })->throws(ModelNotFoundException::class);

    it('guards chained builder lookups such as with()->findOrFail()', function () {
        Game::with('campaign')->findOrFail('not-a-uuid');
    })->throws(ModelNotFoundException::class);

    it('drops non-uuid ids from array lookups and reports them as missing', function () {
        $system = GameSystem::factory()->create();

        try {
            GameSystem::findOrFail([$system->id, 'not-a-uuid']);
            $this->fail('findOrFail() should throw for a partially invalid id list.');
        } catch (ModelNotFoundException $e) {
            expect(array_values($e->getIds()))->toBe(['not-a-uuid']);
        }
    });

    it('finds a record by a valid uuid', function () {
        $system = GameSystem::factory()->create();

        expect(GameSystem::find($system->id)?->id)->toBe($system->id)
            ->and(GameSystem::findOrFail($system->id)->id)->toBe($system->id)
            ->and(GameSystem::query()->findOrFail($system->id)->id)->toBe($system->id);
    });

    it('reports a well-formed but unknown uuid as not found', function () {
        GameSystem::findOrFail('00000000-0000-4000-8000-000000000000');
    })->throws(ModelNotFoundException::class);
});

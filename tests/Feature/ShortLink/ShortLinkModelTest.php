<?php

use App\Models\Game;
use App\Models\GameSystem;
use App\Models\ShortLink;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    // Cache::flush is required: the cache-invalidation tests below write to
    // literal keys like 'short_link:CACHE1'. Without a flush, entries from a
    // prior test (or a sibling --parallel worker) survive and turn the
    // expect(Cache::has(...))->toBeFalse() assertions into false passes.
    Cache::flush();
    $this->gameSystem = GameSystem::factory()->create();
    $this->game = Game::factory()->create(['game_system_id' => $this->gameSystem->id]);
});

// ── Cache invalidation ─────────────────────────────────

describe('ShortLink model — cache invalidation', function () {
    it('clears cache on update', function () {
        $link = ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'code' => 'CACHE1',
        ]);

        Cache::put('short_link:CACHE1', $link, 3600);
        expect(Cache::has('short_link:CACHE1'))->toBeTrue();

        $link->update(['label' => 'updated']);

        expect(Cache::has('short_link:CACHE1'))->toBeFalse();
    });

    it('clears both old and new cache keys when code changes', function () {
        $link = ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'code' => 'OLDCODE',
        ]);

        Cache::put('short_link:OLDCODE', $link, 3600);

        $link->update(['code' => 'NEWCODE']);

        expect(Cache::has('short_link:OLDCODE'))->toBeFalse();
        expect(Cache::has('short_link:NEWCODE'))->toBeFalse();
    });

    it('clears cache on delete', function () {
        $link = ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'code' => 'DELMEE',
        ]);

        Cache::put('short_link:DELMEE', $link, 3600);

        $link->delete();

        expect(Cache::has('short_link:DELMEE'))->toBeFalse();
    });
});

// ── isExpired ───────────────────────────────────────────

describe('ShortLink model — isExpired', function () {
    it('returns false when expires_at is null', function () {
        $link = ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'expires_at' => null,
        ]);

        expect($link->isExpired())->toBeFalse();
    });

    it('returns true when expires_at is in the past', function () {
        $link = ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'expires_at' => now()->subHour(),
        ]);

        expect($link->isExpired())->toBeTrue();
    });

    it('returns false when expires_at is in the future', function () {
        $link = ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'expires_at' => now()->addDay(),
        ]);

        expect($link->isExpired())->toBeFalse();
    });
});

// ── hasHitCap ───────────────────────────────────────────

describe('ShortLink model — hasHitCap', function () {
    it('returns false when max_hits is null', function () {
        $link = ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'max_hits' => null,
            'hit_count' => 999,
        ]);

        expect($link->hasHitCap())->toBeFalse();
    });

    it('returns false when hit_count is below max_hits', function () {
        $link = ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'max_hits' => 100,
            'hit_count' => 50,
        ]);

        expect($link->hasHitCap())->toBeFalse();
    });

    it('returns true when hit_count equals max_hits', function () {
        $link = ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'max_hits' => 10,
            'hit_count' => 10,
        ]);

        expect($link->hasHitCap())->toBeTrue();
    });

    it('returns true when hit_count exceeds max_hits', function () {
        $link = ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'max_hits' => 5,
            'hit_count' => 10,
        ]);

        expect($link->hasHitCap())->toBeTrue();
    });
});

// ── Soft deletes ────────────────────────────────────────

describe('ShortLink model — soft deletes', function () {
    it('soft deletes a link and verifies trashed', function () {
        $link = ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
        ]);

        $link->delete();

        expect($link->fresh()->trashed())->toBeTrue();
        expect(ShortLink::find($link->id))->toBeNull();
        expect(ShortLink::withTrashed()->find($link->id))->not->toBeNull();
    });

    it('keeps hits accessible after soft delete', function () {
        $link = ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
        ]);

        $link->hits()->create([
            'ip_address' => '127.0.0.1',
            'hit_at' => now(),
        ]);

        $link->delete();

        expect($link->hits()->count())->toBe(1);
    });
});

// ── Relationships ───────────────────────────────────────

describe('ShortLink model — relationships', function () {
    it('belongs to a linkable entity (polymorphic)', function () {
        $link = ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'linkable_type' => Game::class,
        ]);

        expect($link->linkable)->not->toBeNull();
        expect($link->linkable)->toBeInstanceOf(Game::class);
        expect($link->linkable->id)->toBe($this->game->id);
    });

    it('has many hits', function () {
        $link = ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
        ]);

        $link->hits()->create([
            'ip_address' => '10.0.0.1',
            'hit_at' => now(),
        ]);
        $link->hits()->create([
            'ip_address' => '10.0.0.2',
            'hit_at' => now(),
        ]);

        expect($link->hits)->toHaveCount(2);
    });

    it('belongs to a user', function () {
        $user = User::factory()->create();
        $link = ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'user_id' => $user->id,
        ]);

        expect($link->user)->not->toBeNull();
        expect($link->user->id)->toBe($user->id);
    });
});

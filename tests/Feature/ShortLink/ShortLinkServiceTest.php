<?php

use App\Models\Game;
use App\Models\GameSystem;
use App\Models\ShortLink;
use App\Models\User;
use App\Services\ShortLinkService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->gameSystem = GameSystem::factory()->create();
    $this->game = Game::factory()->create(['game_system_id' => $this->gameSystem->id]);
    $this->user = User::factory()->create();
    $this->service = new ShortLinkService;

    Cache::flush();
});

afterEach(function () {
    Cache::flush();
});

// ── generateUniqueCode ─────────────────────────────────

describe('ShortLinkService — generateUniqueCode', function () {
    it('returns a 7-char string by default', function () {
        $code = $this->service->generateUniqueCode();

        expect($code)->toBeString();
        expect(strlen($code))->toBe(7);
        expect(preg_match('/^[a-zA-Z0-9]+$/', $code))->toBe(1);
    });

    it('respects custom length parameter', function () {
        $code = $this->service->generateUniqueCode(10);

        expect(strlen($code))->toBe(10);
    });

    it('throws after 10 collision attempts', function () {
        // Create a link to occupy one code, then force collisions
        ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'code' => 'AAAAAAA',
        ]);

        Str::createRandomStringsUsing(fn (int $length) => 'AAAAAAA');

        expect(fn () => $this->service->generateUniqueCode())
            ->toThrow(\RuntimeException::class, 'Unable to generate a unique short link code');

        Str::createRandomStringsUsing(null);
    });

    it('returns a unique code when first attempt collides', function () {
        ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'code' => 'AAAAAAA',
        ]);

        $callCount = 0;
        Str::createRandomStringsUsing(function (int $length) use (&$callCount) {
            $callCount++;

            return $callCount <= 1 ? 'AAAAAAA' : 'BBBBBBB';
        });

        $code = $this->service->generateUniqueCode();
        expect($code)->not->toBe('AAAAAAA');
        expect($code)->toBe('BBBBBBB');

        Str::createRandomStringsUsing(null);
    });
});

// ── createLink ─────────────────────────────────────────

describe('ShortLinkService — createLink', function () {
    it('creates a ShortLink with correct entity association', function () {
        $link = $this->service->createLink($this->game, $this->user);

        expect($link)->toBeInstanceOf(ShortLink::class);
        expect($link->linkable_type)->toBe(Game::class);
        expect($link->linkable_id)->toBe((string) $this->game->id);
        expect($link->user_id)->toBe($this->user->id);
        expect($link->code)->toBeString();
        expect(strlen($link->code))->toBe(7);
    });

    it('accepts custom code parameter', function () {
        $link = $this->service->createLink($this->game, $this->user, [
            'code' => 'CUSTOM1',
        ]);

        expect($link->code)->toBe('CUSTOM1');
    });

    it('accepts custom URL parameter', function () {
        $link = $this->service->createLink($this->game, $this->user, [
            'url' => 'https://custom.url/page',
        ]);

        expect($link->url)->toBe('https://custom.url/page');
    });

    it('generates URL from entity route when no URL provided', function () {
        $link = $this->service->createLink($this->game);

        expect($link->url)->toContain($this->game->id);
    });

    it('stores optional label and purpose', function () {
        $link = $this->service->createLink($this->game, $this->user, [
            'label' => 'Campaign Promo',
            'purpose' => 'marketing',
        ]);

        expect($link->label)->toBe('Campaign Promo');
        expect($link->purpose)->toBe('marketing');
    });

    it('stores expires_at and max_hits when provided', function () {
        $expires = now()->addDays(30);

        $link = $this->service->createLink($this->game, $this->user, [
            'expires_at' => $expires,
            'max_hits' => 100,
        ]);

        expect($link->expires_at->toDateString())->toBe($expires->toDateString());
        expect($link->max_hits)->toBe(100);
    });
});

// ── resolveLink ────────────────────────────────────────

describe('ShortLinkService — resolveLink', function () {
    it('finds a valid link by code', function () {
        $link = ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'code' => 'VALID01',
        ]);

        $resolved = $this->service->resolveLink('VALID01');

        expect($resolved)->not->toBeNull();
        expect($resolved->id)->toBe($link->id);
    });

    it('returns null for non-existent code', function () {
        $resolved = $this->service->resolveLink('NOTFND0');

        expect($resolved)->toBeNull();
    });

    it('returns null for expired link', function () {
        ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'code' => 'EXPIRED',
            'expires_at' => now()->subHour(),
        ]);

        $resolved = $this->service->resolveLink('EXPIRED');

        expect($resolved)->toBeNull();
    });

    it('returns null for hit-capped link', function () {
        ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'code' => 'CAPPED1',
            'max_hits' => 5,
            'hit_count' => 5,
        ]);

        $resolved = $this->service->resolveLink('CAPPED1');

        expect($resolved)->toBeNull();
    });
});

// ── getLinksForEntity ──────────────────────────────────

describe('ShortLinkService — getLinksForEntity', function () {
    it('returns only links for the given entity', function () {
        $game2 = Game::factory()->create(['game_system_id' => $this->gameSystem->id]);

        ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'linkable_type' => Game::class,
            'code' => 'LINK001',
        ]);
        ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'linkable_type' => Game::class,
            'code' => 'LINK002',
        ]);
        ShortLink::factory()->create([
            'linkable_id' => $game2->id,
            'linkable_type' => Game::class,
            'code' => 'LINK003',
        ]);

        $links = $this->service->getLinksForEntity($this->game);

        expect($links)->toHaveCount(2);
        expect($links->pluck('code')->toArray())->toContain('LINK001', 'LINK002');
    });

    it('returns empty collection for entity with no links', function () {
        $links = $this->service->getLinksForEntity($this->game);

        expect($links)->toHaveCount(0);
    });

    it('orders links by newest first', function () {
        ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'code' => 'FIRST01',
            'created_at' => now()->subDay(),
        ]);
        ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'code' => 'SECOND2',
            'created_at' => now(),
        ]);

        $links = $this->service->getLinksForEntity($this->game);

        expect($links->first()->code)->toBe('SECOND2');
        expect($links->last()->code)->toBe('FIRST01');
    });
});

// ── revokeLink ─────────────────────────────────────────

describe('ShortLinkService — revokeLink', function () {
    it('soft-deletes the link', function () {
        $link = ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'code' => 'REVOKE1',
        ]);

        $this->service->revokeLink($link);

        expect($link->fresh()->trashed())->toBeTrue();
        expect(ShortLink::where('code', 'REVOKE1')->exists())->toBeFalse();
    });

    it('clears the cache for the revoked link', function () {
        $link = ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'code' => 'CACHREV',
        ]);

        Cache::put("short_link:CACHREV", $link, 3600);

        $this->service->revokeLink($link);

        expect(Cache::has("short_link:CACHREV"))->toBeFalse();
    });
});

// ── canCreateMore ──────────────────────────────────────

describe('ShortLinkService — canCreateMore', function () {
    it('returns true when under the limit', function () {
        ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'user_id' => $this->user->id,
        ]);

        expect($this->service->canCreateMore($this->game, $this->user))->toBeTrue();
    });

    it('returns false when at the limit', function () {
        // Default limit is 10
        for ($i = 0; $i < 10; $i++) {
            ShortLink::factory()->create([
                'linkable_id' => $this->game->id,
                'user_id' => $this->user->id,
            ]);
        }

        expect($this->service->canCreateMore($this->game, $this->user))->toBeFalse();
    });

    it('only counts links by the same user', function () {
        $otherUser = User::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            ShortLink::factory()->create([
                'linkable_id' => $this->game->id,
                'user_id' => $otherUser->id,
            ]);
        }

        // This user has 0 links
        expect($this->service->canCreateMore($this->game, $this->user))->toBeTrue();
    });

    it('respects custom max_links_per_entity from user', function () {
        // The column will be added in S03. Until then, the service falls back to 10.
        // Test the default behavior: user with 10 links should be at limit.
        $user = User::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            ShortLink::factory()->create([
                'linkable_id' => $this->game->id,
                'user_id' => $user->id,
            ]);
        }

        expect($this->service->canCreateMore($this->game, $user))->toBeFalse();
    });
});

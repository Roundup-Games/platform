<?php

use App\Jobs\RecordShortLinkHit;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\ShortLink;
use App\Models\ShortLinkHit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->gameSystem = GameSystem::factory()->create();
    $this->game = Game::factory()->create(['game_system_id' => $this->gameSystem->id]);
    $this->link = ShortLink::factory()->create([
        'linkable_id' => $this->game->id,
        'linkable_type' => Game::class,
        'url' => route('games.detail', $this->game->id),
    ]);

    // Clear specific cache keys instead of flushing entire cache
    Cache::forget("short_link:{$this->link->code}");
    Cache::forget('short_link_misses:127.0.0.1');
});

afterEach(function () {
    Cache::forget('short_link_misses:127.0.0.1');
});

// ── Valid redirects ────────────────────────────────────

describe('ShortLink redirect — valid codes', function () {
    it('returns 302 to the correct URL', function () {
        $response = $this->get("/link/{$this->link->code}");

        $response->assertStatus(302);
        $response->assertRedirect($this->link->url);
    });

    it('sets Cache-Control no-cache header', function () {
        $response = $this->get("/link/{$this->link->code}");

        $cacheControl = $response->headers->get('Cache-Control');
        expect($cacheControl)->toContain('no-cache');
        expect($cacheControl)->toContain('no-store');
    });

    it('sets ph_link_id cookie on redirect', function () {
        $response = $this->get("/link/{$this->link->code}");

        $cookie = $response->getCookie('ph_link_id');
        expect($cookie)->not->toBeNull();
        expect($cookie->getValue())->toBe((string) $this->link->id);
    });

    it('dispatches RecordShortLinkHit job on redirect', function () {
        Queue::fake();

        $this->get("/link/{$this->link->code}");

        Queue::assertPushed(RecordShortLinkHit::class, function (RecordShortLinkHit $job) {
            return $job->shortLinkId === $this->link->id;
        });
    });

    it('passes IP, referer and user-agent to the job', function () {
        Queue::fake();

        $this->withHeaders([
            'Referer' => 'https://google.com',
            'User-Agent' => 'TestBot/1.0',
        ])->get("/link/{$this->link->code}");

        Queue::assertPushed(RecordShortLinkHit::class, function (RecordShortLinkHit $job) {
            // IP is hashed in constructor — verify it's a 64-char sha256, not raw
            return strlen($job->ipAddress) === 64
                && $job->ipAddress !== '127.0.0.1'
                && $job->referer === 'https://google.com'
                && $job->userAgent === 'TestBot/1.0';
        });
    });
});

// ── Invalid codes ──────────────────────────────────────

describe('ShortLink redirect — invalid codes', function () {
    it('returns 404 for a non-existent code', function () {
        $response = $this->get('/link/NOTFND0');

        $response->assertStatus(404);
    });
});

// ── Expired links ──────────────────────────────────────

describe('ShortLink redirect — expired links', function () {
    it('returns 404 for an expired link', function () {
        $link = ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'expires_at' => now()->subHour(),
        ]);

        $response = $this->get("/link/{$link->code}");

        $response->assertStatus(404);
    });

    it('clears cache for expired links', function () {
        $link = ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'expires_at' => now()->subHour(),
        ]);

        // Prime the cache
        Cache::put("short_link:{$link->code}", $link, 3600);
        expect(Cache::has("short_link:{$link->code}"))->toBeTrue();

        $this->get("/link/{$link->code}");

        expect(Cache::has("short_link:{$link->code}"))->toBeFalse();
    });
});

// ── Hit cap ────────────────────────────────────────────

describe('ShortLink redirect — hit cap', function () {
    it('returns 404 when hit cap is exceeded', function () {
        $link = ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'max_hits' => 2,
            'hit_count' => 2,
        ]);

        $response = $this->get("/link/{$link->code}");

        $response->assertStatus(404);
    });

    it('returns 302 when hit count is below max_hits', function () {
        $link = ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'max_hits' => 10,
            'hit_count' => 5,
        ]);

        $response = $this->get("/link/{$link->code}");

        $response->assertStatus(302);
    });

    it('clears cache when hit cap is reached', function () {
        $link = ShortLink::factory()->create([
            'linkable_id' => $this->game->id,
            'max_hits' => 1,
            'hit_count' => 1,
        ]);

        Cache::put("short_link:{$link->code}", $link, 3600);

        $this->get("/link/{$link->code}");

        expect(Cache::has("short_link:{$link->code}"))->toBeFalse();
    });
});

// ── Cache behavior ─────────────────────────────────────

describe('ShortLink redirect — cache layer', function () {
    it('serves successful redirects consistently (cache-backed)', function () {
        // First request primes the cache
        $response1 = $this->get("/link/{$this->link->code}");
        $response1->assertStatus(302);
        $response1->assertRedirect($this->link->url);

        // Second request should succeed — if cache is working,
        // the link is resolved without re-querying the database
        $response2 = $this->get("/link/{$this->link->code}");
        $response2->assertStatus(302);
        $response2->assertRedirect($this->link->url);
    });
});

// ── Miss counter rate limiting ─────────────────────────

describe('ShortLink redirect — miss counter', function () {
    it('increments miss counter on invalid code', function () {
        $this->get('/link/NOTFND0');

        expect(Cache::get('short_link_misses:127.0.0.1'))->toBe(1);
    });

    it('does not increment miss counter for valid code', function () {
        $this->get("/link/{$this->link->code}");

        expect(Cache::get('short_link_misses:127.0.0.1'))->toBeNull();
    });

    it('returns 429 after 50 misses from same IP', function () {
        // Pre-fill the miss counter to the threshold
        Cache::put('short_link_misses:127.0.0.1', 50, 300);

        $response = $this->get('/link/NOTFND0');

        $response->assertStatus(429);
    });
});

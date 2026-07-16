<?php

use App\Jobs\RecordShortLinkHit;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\ShortLink;
use App\Models\ShortLinkHit;
use App\Services\PostHogClient;

beforeEach(function () {
    $this->gameSystem = GameSystem::factory()->create();
    $this->game = Game::factory()->create(['game_system_id' => $this->gameSystem->id]);
    $this->link = ShortLink::factory()->create([
        'linkable_id' => $this->game->id,
        'linkable_type' => Game::class,
        'hit_count' => 0,
    ]);
});

// ── Hit recording (consent granted — PostHog fires) ─────

describe('RecordShortLinkHit job — hit recording', function () {
    it('creates a ShortLinkHit record', function () {
        $posthog = $this->mock(PostHogClient::class);
        $posthog->shouldReceive('capture')->once();

        (new RecordShortLinkHit(
            shortLinkId: $this->link->id,
            ipAddress: '192.168.1.1',
            referer: 'https://google.com',
            userAgent: 'TestBot/1.0',
            hasConsent: true,
        ))->handle($posthog);

        expect(ShortLinkHit::where('short_link_id', $this->link->id)->count())->toBe(1);

        $hit = ShortLinkHit::first();
        // IP is hashed for PII compliance — verify it's a sha256 hash, not raw IP
        expect($hit->ip_address)->not->toBe('192.168.1.1');
        expect(strlen($hit->ip_address))->toBe(64); // sha256 hex
        expect($hit->referer)->toBe('google.com');
        expect($hit->user_agent)->toBe('Bot/Unknown');
        expect($hit->hit_at)->not->toBeNull();
    });

    it('increments hit_count on the short link', function () {
        $posthog = $this->mock(PostHogClient::class);
        $posthog->shouldReceive('capture')->once();

        (new RecordShortLinkHit(
            shortLinkId: $this->link->id,
            hasConsent: true,
        ))->handle($posthog);

        expect($this->link->fresh()->hit_count)->toBe(1);
    });

    it('updates last_hit_at on the short link', function () {
        $posthog = $this->mock(PostHogClient::class);
        $posthog->shouldReceive('capture')->once();

        expect($this->link->last_hit_at)->toBeNull();

        (new RecordShortLinkHit(
            shortLinkId: $this->link->id,
            hasConsent: true,
        ))->handle($posthog);

        expect($this->link->fresh()->last_hit_at)->not->toBeNull();
    });

    it('increments hit_count across multiple hits', function () {
        $posthog = $this->mock(PostHogClient::class);
        $posthog->shouldReceive('capture')->times(3);

        for ($i = 0; $i < 3; $i++) {
            (new RecordShortLinkHit(
                shortLinkId: $this->link->id,
                hasConsent: true,
            ))->handle($posthog);
        }

        expect($this->link->fresh()->hit_count)->toBe(3);
        expect(ShortLinkHit::where('short_link_id', $this->link->id)->count())->toBe(3);
    });
});

// ── Consent gating (first-party row always; PostHog gated) ─

describe('RecordShortLinkHit job — consent gating', function () {
    it('still records the hit row when consent is absent', function () {
        // The hit row is the link owner's operational data (legitimate interest);
        // it must be recorded regardless of analytics consent.
        $posthog = $this->mock(PostHogClient::class);
        $posthog->shouldNotReceive('capture');

        (new RecordShortLinkHit(
            shortLinkId: $this->link->id,
            ipAddress: '192.168.1.1',
            hasConsent: false,
        ))->handle($posthog);

        expect(ShortLinkHit::where('short_link_id', $this->link->id)->count())->toBe(1);
        expect($this->link->fresh()->hit_count)->toBe(1);
    });

    it('does not capture a PostHog event when consent is absent', function () {
        $posthog = $this->mock(PostHogClient::class);
        $posthog->shouldNotReceive('capture');

        (new RecordShortLinkHit(
            shortLinkId: $this->link->id,
            hasConsent: false,
        ))->handle($posthog);
    });

    it('defaults to no consent (privacy-safe) when omitted', function () {
        $job = new RecordShortLinkHit(shortLinkId: $this->link->id);
        expect($job->hasConsent)->toBeFalse();
    });
});

// ── PostHog analytics (consent granted) ────────────────

describe('RecordShortLinkHit job — PostHog', function () {
    it('captures a link.hit event with correct properties', function () {
        $posthog = $this->mock(PostHogClient::class);
        $posthog->shouldReceive('capture')->once()->withArgs(function (array $payload) {
            expect($payload['event'])->toBe('link.hit');
            expect($payload['distinctId'])->toStartWith('link:');
            expect($payload['properties']['link_id'])->toBe($this->link->id);
            expect($payload['properties']['link_code'])->toBe($this->link->code);
            expect($payload['properties']['linkable_type'])->toBe('Game');
            expect($payload['properties']['linkable_id'])->toBe($this->link->linkable_id);

            return true;
        });

        (new RecordShortLinkHit(
            shortLinkId: $this->link->id,
            ipAddress: '10.0.0.1',
            referer: 'https://example.com/page',
            userAgent: 'Mozilla/5.0',
            hasConsent: true,
        ))->handle($posthog);
    });

    it('uses xxh128 hash of IP + UA for distinctId', function () {
        $ip = '10.0.0.1';
        $ua = 'TestAgent';

        $posthog = $this->mock(PostHogClient::class);
        $posthog->shouldReceive('capture')->once()->withArgs(function (array $payload) use ($ip, $ua) {
            $expected = 'link:'.hash('xxh128', $ip.$ua);
            expect($payload['distinctId'])->toBe($expected);

            return true;
        });

        (new RecordShortLinkHit(
            shortLinkId: $this->link->id,
            ipAddress: $ip,
            userAgent: $ua,
            hasConsent: true,
        ))->handle($posthog);
    });

    it('extracts referer domain from full URL', function () {
        $posthog = $this->mock(PostHogClient::class);
        $posthog->shouldReceive('capture')->once()->withArgs(function (array $payload) {
            expect($payload['properties']['referer_domain'])->toBe('google.com');

            return true;
        });

        (new RecordShortLinkHit(
            shortLinkId: $this->link->id,
            referer: 'https://google.com/search?q=test',
            hasConsent: true,
        ))->handle($posthog);
    });

    it('handles null referer gracefully', function () {
        $posthog = $this->mock(PostHogClient::class);
        $posthog->shouldReceive('capture')->once()->withArgs(function (array $payload) {
            expect($payload['properties']['referer_domain'])->toBeNull();

            return true;
        });

        (new RecordShortLinkHit(
            shortLinkId: $this->link->id,
            referer: null,
            hasConsent: true,
        ))->handle($posthog);
    });
});

// ── Error handling ─────────────────────────────────────

describe('RecordShortLinkHit job — error handling', function () {
    it('does not fail when PostHog throws an exception', function () {
        $posthog = $this->mock(PostHogClient::class);
        $posthog->shouldReceive('capture')->once()->andThrow(new RuntimeException('PostHog down'));

        // Should not throw
        (new RecordShortLinkHit(
            shortLinkId: $this->link->id,
            hasConsent: true,
        ))->handle($posthog);

        // Hit should still be recorded even when PostHog fails
        expect(ShortLinkHit::where('short_link_id', $this->link->id)->count())->toBe(1);
        expect($this->link->fresh()->hit_count)->toBe(1);
    });

    it('handles non-existent link_id gracefully', function () {
        $posthog = $this->mock(PostHogClient::class);
        $posthog->shouldNotReceive('capture');

        $nonExistentId = 999999;

        // Should not throw
        (new RecordShortLinkHit(
            shortLinkId: $nonExistentId,
            hasConsent: true,
        ))->handle($posthog);

        // No hit should be recorded
        expect(ShortLinkHit::count())->toBe(0);
    });
});

// ── Job configuration ──────────────────────────────────

describe('RecordShortLinkHit job — configuration', function () {
    it('has correct retry configuration', function () {
        $job = new RecordShortLinkHit(shortLinkId: 1);

        expect($job->tries)->toBe(3);
        expect($job->timeout)->toBe(30);
        expect($job->deleteWhenMissingModels)->toBeTrue();
    });
});

// ── Referer sanitization (GDPR) ────────────────────────

describe('RecordShortLinkHit job — referer sanitization', function () {
    it('strips referer to hostname only, removing query params', function () {
        $posthog = $this->mock(PostHogClient::class);
        $posthog->shouldReceive('capture')->once();

        (new RecordShortLinkHit(
            shortLinkId: $this->link->id,
            referer: 'https://google.com/search?q=test&utm_source=mail&uid=12345',
            hasConsent: true,
        ))->handle($posthog);

        $hit = ShortLinkHit::first();
        expect($hit->referer)->toBe('google.com');
        expect($hit->referer_domain)->toBe('google.com');
    });

    it('strips referer with path and fragment to hostname only', function () {
        $posthog = $this->mock(PostHogClient::class);
        $posthog->shouldReceive('capture')->once();

        (new RecordShortLinkHit(
            shortLinkId: $this->link->id,
            referer: 'https://example.com/path/to/page#section',
            hasConsent: true,
        ))->handle($posthog);

        $hit = ShortLinkHit::first();
        expect($hit->referer)->toBe('example.com');
    });

    it('falls back to raw referer when parse_url fails', function () {
        $posthog = $this->mock(PostHogClient::class);
        $posthog->shouldReceive('capture')->once();

        // Malformed string that parse_url can't extract a host from
        (new RecordShortLinkHit(
            shortLinkId: $this->link->id,
            referer: 'not-a-valid-url',
            hasConsent: true,
        ))->handle($posthog);

        $hit = ShortLinkHit::first();
        expect($hit->referer)->toBe('not-a-valid-url');
    });

    it('handles null referer gracefully after sanitization', function () {
        $posthog = $this->mock(PostHogClient::class);
        $posthog->shouldReceive('capture')->once();

        (new RecordShortLinkHit(
            shortLinkId: $this->link->id,
            referer: null,
            hasConsent: true,
        ))->handle($posthog);

        $hit = ShortLinkHit::first();
        expect($hit->referer)->toBeNull();
        expect($hit->referer_domain)->toBeNull();
    });

    it('sanitizes referer before it enters the queue store', function () {
        $job = new RecordShortLinkHit(
            shortLinkId: $this->link->id,
            referer: 'https://example.com/page?user=123',
        );

        // The public referer property should already be sanitized at construction
        expect($job->referer)->toBe('example.com');
    });
});

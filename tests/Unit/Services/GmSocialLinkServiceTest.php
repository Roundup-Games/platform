<?php

use App\Models\GmSocialLink;
use App\Models\User;
use App\Services\GmSocialLinkService;

beforeEach(function () {
    $this->service = new GmSocialLinkService();
});

// ── generateUrl ────────────────────────────────────────

describe('generateUrl', function () {
    it('generates Twitter/X URL', function () {
        expect($this->service->generateUrl('twitter', 'testuser'))
            ->toBe('https://x.com/testuser');
    });

    it('generates YouTube URL with @ prefix', function () {
        expect($this->service->generateUrl('youtube', 'mychannel'))
            ->toBe('https://youtube.com/@mychannel');
    });

    it('generates Mastodon URL with instance', function () {
        expect($this->service->generateUrl('mastodon', 'testuser', 'mastodon.social'))
            ->toBe('https://mastodon.social/@testuser');
    });

    it('generates itch.io URL with subdomain', function () {
        expect($this->service->generateUrl('itch-io', 'mygame'))
            ->toBe('https://mygame.itch.io');
    });

    it('generates Bluesky URL', function () {
        expect($this->service->generateUrl('bluesky', 'user.bsky.social'))
            ->toBe('https://bsky.app/profile/user.bsky.social');
    });

    it('returns null for unknown platform', function () {
        expect($this->service->generateUrl('nonexistent', 'handle'))
            ->toBeNull();
    });

    it('handles Mastodon without instance (instance_required returns null)', function () {
        $result = $this->service->generateUrl('mastodon', 'testuser');
        // Mastodon has instance_required=true, so missing instance returns null
        expect($result)->toBeNull();
    });
});

// ── validateHandle ─────────────────────────────────────

describe('validateHandle', function () {
    it('accepts valid Twitter handle', function () {
        expect($this->service->validateHandle('twitter', 'valid_user'))
            ->toHaveKey('valid', true);
    });

    it('rejects handle with special characters on Twitter', function () {
        $result = $this->service->validateHandle('twitter', 'invalid!');
        expect($result)->toHaveKey('valid', false);
        expect($result)->toHaveKey('error');
    });

    it('rejects empty handle', function () {
        $result = $this->service->validateHandle('twitter', '');
        expect($result)->toHaveKey('valid', false);
        expect($result['error'])->toBe('Handle is required.');
    });

    it('rejects unknown platform', function () {
        $result = $this->service->validateHandle('nonexistent', 'handle');
        expect($result)->toHaveKey('valid', false);
        expect($result['error'])->toContain('Unknown platform');
    });

    it('accepts valid Twitch handle (4-25 chars)', function () {
        expect($this->service->validateHandle('twitch', 'valid_twitch'))
            ->toHaveKey('valid', true);
    });

    it('rejects short Twitch handle (under 4 chars)', function () {
        expect($this->service->validateHandle('twitch', 'abc'))
            ->toHaveKey('valid', false);
    });

    it('accepts valid Reddit username', function () {
        expect($this->service->validateHandle('reddit', 'valid_user-name'))
            ->toHaveKey('valid', true);
    });
});

// ── validateInstance ───────────────────────────────────

describe('validateInstance', function () {
    it('accepts valid Mastodon instance', function () {
        expect($this->service->validateInstance('mastodon.social'))
            ->toHaveKey('valid', true);
    });

    it('accepts valid instance with subdomain', function () {
        expect($this->service->validateInstance('social.example.com'))
            ->toHaveKey('valid', true);
    });

    it('rejects instance with spaces', function () {
        expect($this->service->validateInstance('bad domain'))
            ->toHaveKey('valid', false);
    });

    it('rejects instance without TLD', function () {
        expect($this->service->validateInstance('nodots'))
            ->toHaveKey('valid', false);
    });
});

// ── getPlatforms ───────────────────────────────────────

describe('getPlatforms', function () {
    it('returns all 15 platforms', function () {
        expect(count($this->service->getPlatforms()))->toBe(15);
    });

    it('returns platforms sorted by sort_order', function () {
        $platforms = $this->service->getPlatforms();
        $keys = array_keys($platforms);
        expect($keys[0])->toBe('twitter');
        expect($keys[count($keys) - 1])->toBe('startplaying');
    });

    it('each platform has required keys', function () {
        $platforms = $this->service->getPlatforms();
        foreach ($platforms as $key => $config) {
            expect($config)->toHaveKeys(['name', 'url_template', 'handle_pattern', 'icon', 'sort_order']);
        }
    });
});

// ── getDisplayUrl ──────────────────────────────────────

describe('getDisplayUrl', function () {
    it('returns stored URL when available', function () {
        $link = new GmSocialLink([
            'platform' => 'twitter',
            'handle' => 'testuser',
            'url' => 'https://x.com/testuser',
        ]);

        expect($this->service->getDisplayUrl($link))
            ->toBe('https://x.com/testuser');
    });

    it('regenerates URL when stored url is null', function () {
        $link = new GmSocialLink([
            'platform' => 'twitter',
            'handle' => 'testuser',
            'url' => null,
        ]);

        expect($this->service->getDisplayUrl($link))
            ->toBe('https://x.com/testuser');
    });
});

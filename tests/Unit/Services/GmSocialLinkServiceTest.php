<?php

use App\Services\GmSocialLinkService;

// ── Discord platform contract (S01 T04 / T05) ──────────
// Discord was added to config/platforms.php as the primary external-community
// platform. These tests pin its public contract independently of the broad
// Feature/GM/GmSocialLinkTest.php matrix so the numeric-ID snowflake pattern,
// the users/{handle} URL template, and the sort_order placement are explicit
// and fail loudly if anyone "normalizes" Discord to a username-style handle.

beforeEach(function () {
    $this->service = app(GmSocialLinkService::class);
});

describe('Discord URL generation', function () {
    it('generates the canonical Discord profile URL from a numeric snowflake', function () {
        expect($this->service->generateUrl('discord', '123456789012345678')) // gitleaks:allow — synthetic test snowflake, not a real credential
            ->toBe('https://discord.com/users/123456789012345678');
    });

    it('generates a URL for the minimum 17-digit snowflake', function () {
        expect($this->service->generateUrl('discord', '10000000000000000'))
            ->toBe('https://discord.com/users/10000000000000000');
    });

    it('generates a URL for the maximum 20-digit snowflake', function () {
        expect($this->service->generateUrl('discord', '99999999999999999999'))
            ->toBe('https://discord.com/users/99999999999999999999');
    });
});

describe('Discord handle validation', function () {
    it('accepts a valid 18-digit Discord user ID', function () {
        expect($this->service->validateHandle('discord', '123456789012345678')) // gitleaks:allow — synthetic test snowflake, not a real credential
            ->toBe(['valid' => true]);
    });

    it('accepts the 17-digit floor', function () {
        expect($this->service->validateHandle('discord', '12345678901234567'))
            ->toBe(['valid' => true]);
    });

    it('accepts the 20-digit ceiling', function () {
        expect($this->service->validateHandle('discord', '12345678901234567890'))
            ->toBe(['valid' => true]);
    });

    it('rejects a Discord username handle', function () {
        $result = $this->service->validateHandle('discord', 'username');
        expect($result['valid'])->toBeFalse()
            ->and($result['error'])->toBeString();
    });

    it('rejects a display name with spaces', function () {
        $result = $this->service->validateHandle('discord', 'Invalid Name');
        expect($result['valid'])->toBeFalse();
    });

    it('rejects a 16-digit ID (one short of the snowflake floor)', function () {
        $result = $this->service->validateHandle('discord', '1234567890123456');
        expect($result['valid'])->toBeFalse();
    });

    it('rejects a 21-digit ID (one past the snowflake ceiling)', function () {
        $result = $this->service->validateHandle('discord', '123456789012345678901');
        expect($result['valid'])->toBeFalse();
    });

    it('rejects a handle containing non-numeric characters', function () {
        $result = $this->service->validateHandle('discord', '12345678901234567a');
        expect($result['valid'])->toBeFalse();
    });

    it('rejects an empty handle', function () {
        $result = $this->service->validateHandle('discord', '');
        expect($result['valid'])->toBeFalse()
            ->and($result['error'])->toBe('Handle is required.');
    });
});

describe('Discord platform registry placement', function () {
    it('surfaces Discord as the first platform by sort_order', function () {
        $platforms = $this->service->getPlatforms();

        $firstKey = array_key_first($platforms);
        expect($firstKey)->toBe('discord');
        expect($platforms['discord']['sort_order'])->toBe(5);
    });

    it('registers the Discord platform with the expected display metadata', function () {
        $config = config('platforms.discord');

        expect($config)->not->toBeNull()
            ->and($config['name'])->toBe('Discord')
            ->and($config['url_template'])->toBe('https://discord.com/users/{handle}')
            ->and($config['handle_pattern'])->toBe('/^[0-9]{17,20}$/')
            ->and($config['at_prefixed'])->toBeFalse();
    });
});

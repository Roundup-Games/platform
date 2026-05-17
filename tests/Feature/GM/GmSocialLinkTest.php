<?php

use App\Models\GmSocialLink;
use App\Models\User;
use App\Services\GmSocialLinkService;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->service = app(GmSocialLinkService::class);
    $this->user = User::factory()->create();
});

// ── URL Generation ──────────────────────────────────────

describe('URL generation', function () {
    it('generates correct URL for Twitter/X', function () {
        expect($this->service->generateUrl('twitter', 'john_doe'))
            ->toBe('https://x.com/john_doe');
    });

    it('generates correct URL for Instagram', function () {
        expect($this->service->generateUrl('instagram', 'janedoe'))
            ->toBe('https://instagram.com/janedoe');
    });

    it('generates correct URL for YouTube', function () {
        expect($this->service->generateUrl('youtube', 'MyChannel'))
            ->toBe('https://youtube.com/@MyChannel');
    });

    it('generates correct URL for Twitch', function () {
        expect($this->service->generateUrl('twitch', 'streamer99'))
            ->toBe('https://twitch.tv/streamer99');
    });

    it('generates correct URL for TikTok', function () {
        expect($this->service->generateUrl('tiktok', 'coolcreator'))
            ->toBe('https://tiktok.com/@coolcreator');
    });

    it('generates correct URL for Threads', function () {
        expect($this->service->generateUrl('threads', 'threader'))
            ->toBe('https://threads.net/@threader');
    });

    it('generates correct URL for Reddit', function () {
        expect($this->service->generateUrl('reddit', 'redditor42'))
            ->toBe('https://reddit.com/user/redditor42');
    });

    it('generates correct URL for Facebook', function () {
        expect($this->service->generateUrl('facebook', 'page.name'))
            ->toBe('https://facebook.com/page.name');
    });

    it('generates correct URL for Mastodon with instance', function () {
        expect($this->service->generateUrl('mastodon', 'user', 'mastodon.social'))
            ->toBe('https://mastodon.social/@user');
    });

    it('generates correct URL for Bluesky', function () {
        expect($this->service->generateUrl('bluesky', 'user.bsky.social'))
            ->toBe('https://bsky.app/profile/user.bsky.social');
    });

    it('generates correct URL for Patreon', function () {
        expect($this->service->generateUrl('patreon', 'creator_name'))
            ->toBe('https://patreon.com/creator_name');
    });

    it('generates correct URL for Ko-fi', function () {
        expect($this->service->generateUrl('ko-fi', 'mykofi'))
            ->toBe('https://ko-fi.com/mykofi');
    });

    it('generates correct URL for Linktree', function () {
        expect($this->service->generateUrl('linktree', 'mylinks'))
            ->toBe('https://linktr.ee/mylinks');
    });

    it('generates correct URL for itch.io', function () {
        expect($this->service->generateUrl('itch-io', 'devname'))
            ->toBe('https://devname.itch.io');
    });

    it('generates correct URL for StartPlaying', function () {
        expect($this->service->generateUrl('startplaying', 'gm_name'))
            ->toBe('https://startplaying.games/gm/gm_name');
    });

    it('returns null for unknown platform', function () {
        Log::shouldReceive('warning')->once();

        expect($this->service->generateUrl('nonexistent', 'handle'))
            ->toBeNull();
    });
});

// ── Mastodon instance ───────────────────────────────────

describe('Mastodon instance handling', function () {
    it('generates URL with custom instance domain', function () {
        expect($this->service->generateUrl('mastodon', 'alice', 'fosstodon.org'))
            ->toBe('https://fosstodon.org/@alice');
    });

    it('generates URL with subdomain instance', function () {
        expect($this->service->generateUrl('mastodon', 'bob', 'social.example.com'))
            ->toBe('https://social.example.com/@bob');
    });

    it('generates URL with empty instance fallback', function () {
        // No instance provided — instance_required platforms return null
        expect($this->service->generateUrl('mastodon', 'charlie'))
            ->toBeNull();
    });
});

// ── Bluesky domain handle ────────────────────────────────

describe('Bluesky domain handle', function () {
    it('generates URL for domain-style handle', function () {
        expect($this->service->generateUrl('bluesky', 'example.com'))
            ->toBe('https://bsky.app/profile/example.com');
    });

    it('generates URL for standard bsky.social handle', function () {
        expect($this->service->generateUrl('bluesky', 'user.bsky.social'))
            ->toBe('https://bsky.app/profile/user.bsky.social');
    });
});

// ── Handle Validation ───────────────────────────────────

describe('handle validation', function () {
    it('validates a correct Twitter handle', function () {
        expect($this->service->validateHandle('twitter', 'john_doe'))
            ->toBe(['valid' => true]);
    });

    it('rejects a Twitter handle that is too long', function () {
        $result = $this->service->validateHandle('twitter', str_repeat('a', 16));
        expect($result['valid'])->toBeFalse()
            ->and($result['error'])->toBeString();
    });

    it('rejects a Twitter handle with invalid characters', function () {
        $result = $this->service->validateHandle('twitter', 'john@doe');
        expect($result['valid'])->toBeFalse();
    });

    it('validates a correct Twitch handle', function () {
        expect($this->service->validateHandle('twitch', 'streamer'))
            ->toBe(['valid' => true]);
    });

    it('rejects a Twitch handle that is too short', function () {
        $result = $this->service->validateHandle('twitch', 'abc');
        expect($result['valid'])->toBeFalse();
    });

    it('validates a correct Reddit handle', function () {
        expect($this->service->validateHandle('reddit', 'my-user_1'))
            ->toBe(['valid' => true]);
    });

    it('rejects a Reddit handle that is too short', function () {
        $result = $this->service->validateHandle('reddit', 'ab');
        expect($result['valid'])->toBeFalse();
    });

    it('rejects an empty handle', function () {
        $result = $this->service->validateHandle('twitter', '');
        expect($result['valid'])->toBeFalse()
            ->and($result['error'])->toBe('Handle is required.');
    });

    it('rejects an unknown platform', function () {
        $result = $this->service->validateHandle('nonexistent', 'handle');
        expect($result['valid'])->toBeFalse()
            ->and($result['error'])->toBe('Unknown platform: nonexistent');
    });

    it('logs a warning for invalid handles', function () {
        Log::shouldReceive('info')->once();

        $this->service->validateHandle('twitter', 'invalid!chars');
    });
});

// ── Instance Validation ─────────────────────────────────

describe('instance validation', function () {
    it('validates a correct Mastodon instance', function () {
        expect($this->service->validateInstance('mastodon.social'))
            ->toBe(['valid' => true]);
    });

    it('validates a subdomain instance', function () {
        expect($this->service->validateInstance('social.example.com'))
            ->toBe(['valid' => true]);
    });

    it('rejects an instance without a TLD', function () {
        $result = $this->service->validateInstance('notadomain');
        expect($result['valid'])->toBeFalse()
            ->and($result['error'])->toBe('Invalid instance domain.');
    });

    it('rejects an instance with spaces', function () {
        $result = $this->service->validateInstance('not valid.com');
        expect($result['valid'])->toBeFalse();
    });
});

// ── syncLinksForUser ────────────────────────────────────

describe('syncLinksForUser', function () {
    it('creates new social links', function () {
        $result = $this->service->syncLinksForUser($this->user, [
            ['platform' => 'twitter', 'handle' => 'john_doe'],
            ['platform' => 'youtube', 'handle' => 'MyChannel'],
        ]);

        expect($result['synced'])->toBe(2)
            ->and($result['errors'])->toBeEmpty();

        expect(GmSocialLink::where('user_id', $this->user->id)->count())->toBe(2);
    });

    it('updates an existing social link via upsert', function () {
        GmSocialLink::create([
            'user_id' => $this->user->id,
            'platform' => 'twitter',
            'handle' => 'old_handle',
        ]);

        $this->service->syncLinksForUser($this->user, [
            ['platform' => 'twitter', 'handle' => 'new_handle'],
        ]);

        $link = GmSocialLink::where('user_id', $this->user->id)
            ->where('platform', 'twitter')
            ->first();

        expect($link->handle)->toBe('new_handle')
            ->and($link->url)->toBe('https://x.com/new_handle');
    });

    it('deletes a link when handle is empty', function () {
        GmSocialLink::create([
            'user_id' => $this->user->id,
            'platform' => 'twitter',
            'handle' => 'to_delete',
        ]);

        $this->service->syncLinksForUser($this->user, [
            ['platform' => 'twitter', 'handle' => ''],
        ]);

        expect(GmSocialLink::where('user_id', $this->user->id)
            ->where('platform', 'twitter')
            ->exists())->toBeFalse();
    });

    it('creates, updates, and deletes in one call', function () {
        // Pre-existing link to update
        GmSocialLink::create([
            'user_id' => $this->user->id,
            'platform' => 'twitter',
            'handle' => 'old_twitter',
        ]);

        // Pre-existing link to delete
        GmSocialLink::create([
            'user_id' => $this->user->id,
            'platform' => 'youtube',
            'handle' => 'old_yt',
        ]);

        $result = $this->service->syncLinksForUser($this->user, [
            ['platform' => 'twitter', 'handle' => 'new_twitter'],       // update
            ['platform' => 'youtube', 'handle' => ''],                   // delete
            ['platform' => 'instagram', 'handle' => 'new_insta'],        // create
        ]);

        expect($result['synced'])->toBe(2);  // update + create

        $links = GmSocialLink::where('user_id', $this->user->id)->get();
        expect($links->count())->toBe(2);

        $twitter = $links->firstWhere('platform', 'twitter');
        expect($twitter->handle)->toBe('new_twitter');

        $instagram = $links->firstWhere('platform', 'instagram');
        expect($instagram->handle)->toBe('new_insta');

        expect($links->firstWhere('platform', 'youtube'))->toBeNull();
    });

    it('returns validation errors for invalid handles', function () {
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $result = $this->service->syncLinksForUser($this->user, [
            ['platform' => 'twitter', 'handle' => str_repeat('a', 16)], // too long
            ['platform' => 'instagram', 'handle' => 'valid_handle'],     // valid
        ]);

        expect($result['synced'])->toBe(1)
            ->and($result['errors'])->toHaveKey('twitter')
            ->and($result['errors']['twitter'])->toBeString();
    });

    it('handles Mastodon with instance for URL generation', function () {
        Role::firstOrCreate(['name' => 'Game Master', 'guard_name' => 'web', 'team_id' => null]);

        $result = $this->service->syncLinksForUser($this->user, [
            ['platform' => 'mastodon', 'handle' => 'alice', 'instance' => 'mastodon.social'],
        ]);

        expect($result['synced'])->toBe(1);

        $link = GmSocialLink::where('user_id', $this->user->id)
            ->where('platform', 'mastodon')
            ->first();

        expect($link->url)->toBe('https://mastodon.social/@alice');
    });

    it('skips entries without a platform key', function () {
        $result = $this->service->syncLinksForUser($this->user, [
            ['handle' => 'orphan_handle'],
        ]);

        expect($result['synced'])->toBe(0);
    });
});

// ── Unique constraint ───────────────────────────────────

describe('unique constraint', function () {
    it('prevents duplicate platform links for the same user', function () {
        GmSocialLink::create([
            'user_id' => $this->user->id,
            'platform' => 'twitter',
            'handle' => 'first',
        ]);

        // Attempt to insert a second twitter link directly (bypassing updateOrCreate)
        expect(fn () => GmSocialLink::create([
            'user_id' => $this->user->id,
            'platform' => 'twitter',
            'handle' => 'second',
        ]))->toThrow(\Illuminate\Database\QueryException::class);
    });

    it('allows same platform for different users', function () {
        $user2 = User::factory()->create();

        GmSocialLink::create([
            'user_id' => $this->user->id,
            'platform' => 'twitter',
            'handle' => 'user1_handle',
        ]);

        GmSocialLink::create([
            'user_id' => $user2->id,
            'platform' => 'twitter',
            'handle' => 'user2_handle',
        ]);

        expect(GmSocialLink::where('platform', 'twitter')->count())->toBe(2);
    });
});

// ── Model booted hooks ──────────────────────────────────

describe('model URL auto-generation', function () {
    it('generates URL on create via model hook', function () {
        $link = GmSocialLink::create([
            'user_id' => $this->user->id,
            'platform' => 'twitter',
            'handle' => 'autogen',
        ]);

        expect($link->url)->toBe('https://x.com/autogen');
    });

    it('regenerates URL when handle changes', function () {
        $link = GmSocialLink::create([
            'user_id' => $this->user->id,
            'platform' => 'twitter',
            'handle' => 'original',
        ]);

        $link->handle = 'updated';
        $link->save();

        expect($link->fresh()->url)->toBe('https://x.com/updated');
    });

    it('regenerates URL when platform changes', function () {
        $link = GmSocialLink::create([
            'user_id' => $this->user->id,
            'platform' => 'twitter',
            'handle' => 'myhandle',
        ]);

        $link->platform = 'instagram';
        $link->save();

        expect($link->fresh()->url)->toBe('https://instagram.com/myhandle');
    });
});

// ── getPlatforms ────────────────────────────────────────

describe('getPlatforms', function () {
    it('returns all platforms sorted by sort_order', function () {
        $platforms = $this->service->getPlatforms();

        expect(count($platforms))->toBe(15);

        $orders = array_values(array_map(fn ($p) => $p['sort_order'], $platforms));
        $sortedOrders = $orders;
        sort($sortedOrders);

        expect($orders)->toBe($sortedOrders);
    });
});

// ── getDisplayUrl ───────────────────────────────────────

describe('getDisplayUrl', function () {
    it('returns stored URL when available', function () {
        $link = GmSocialLink::create([
            'user_id' => $this->user->id,
            'platform' => 'twitter',
            'handle' => 'stored',
        ]);

        expect($this->service->getDisplayUrl($link))
            ->toBe('https://x.com/stored');
    });

    it('regenerates URL when stored url is null', function () {
        $link = GmSocialLink::create([
            'user_id' => $this->user->id,
            'platform' => 'twitter',
            'handle' => 'regenerated',
        ]);

        // Force url to null (simulating legacy data)
        $link->url = null;
        $link->saveQuietly();

        expect($this->service->getDisplayUrl($link->fresh()))
            ->toBe('https://x.com/regenerated');
    });
});

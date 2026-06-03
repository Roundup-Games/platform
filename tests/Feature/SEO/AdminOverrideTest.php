<?php

use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Team;
use App\Models\User;
use App\Services\SeoCacheService;
use Illuminate\Support\Facades\Cache;
use function Pest\Laravel\{get, actingAs};

// ── GameSystem Override Precedence ───────────────────

describe('GameSystem Admin Override Precedence', function () {
    it('shows dynamic SEO data when no override exists', function () {
        $system = GameSystem::factory()->create([
            'name' => ['en' => 'Dynamic Title System'],
            'description' => ['en' => 'Dynamic description for this game system.'],
        ]);

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();
        assertPageTitle($response, 'Dynamic Title System');

        $content = get(route('game-systems.show', $system->slug))->content();
        expect(extractMetaDescription($content))->toContain('Dynamic description for this game system.');
    });

    it('overrides title via admin SEO override', function () {
        $system = GameSystem::factory()->create(['name' => ['en' => 'Dynamic Title System']]);
        $system->seo->update(['title' => 'Admin Override Title']);

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();
        assertPageTitle($response, 'Admin Override Title');
    });

    it('overrides description via admin SEO override', function () {
        $system = GameSystem::factory()->create([
            'name' => ['en' => 'Desc Override System'],
            'description' => ['en' => 'Dynamic description.'],
        ]);
        $system->seo->update(['description' => 'Admin override description for SEO.']);

        $content = get(route('game-systems.show', $system->slug))->content();
        expect(extractMetaDescription($content))->toContain('Admin override description for SEO.');
    });

    it('overrides image via admin SEO override', function () {
        $system = GameSystem::factory()->create(['name' => ['en' => 'Image Override System']]);
        $system->seo->update(['image' => 'https://example.com/admin-override-image.jpg']);

        $content = get(route('game-systems.show', $system->slug))->content();
        expect($content)->toContain('https://example.com/admin-override-image.jpg');
    });

    it('overrides robots directive via admin SEO override', function () {
        $system = GameSystem::factory()->create(['name' => ['en' => 'Robots Override System']]);
        $system->seo->update(['robots' => 'noindex, nofollow']);

        get(route('game-systems.show', $system->slug))
            ->assertOk()
            ->assertSee('name="robots"', false)
            ->assertSee('noindex, nofollow', false);
    });

    it('overrides canonical_url via admin SEO override', function () {
        $system = GameSystem::factory()->create(['name' => ['en' => 'Canonical Override System']]);
        $system->seo->update(['canonical_url' => 'https://example.com/custom-canonical']);

        get(route('game-systems.show', $system->slug))
            ->assertOk()
            ->assertSee('rel="canonical"', false)
            ->assertSee('https://example.com/custom-canonical', false);
    });

    it('restores dynamic data when override is cleared', function () {
        $system = GameSystem::factory()->create([
            'name' => ['en' => 'Restore Dynamic Title'],
            'description' => ['en' => 'Original dynamic description.'],
        ]);

        $system->seo->update([
            'title' => 'Admin Override',
            'description' => 'Admin description.',
        ]);

        $system->seo->update(['title' => null, 'description' => null]);

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();
        assertPageTitle($response, 'Restore Dynamic Title');
        expect(extractMetaDescription($response->content()))->toContain('Original dynamic description.');
    });

    it('allows partial overrides with mixed fields', function () {
        $system = GameSystem::factory()->create([
            'name' => ['en' => 'Partial Override System'],
            'description' => ['en' => 'Dynamic description remains.'],
        ]);

        $system->seo->update(['title' => 'Custom Admin Title', 'description' => null]);

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();
        assertPageTitle($response, 'Custom Admin Title');
        expect(extractMetaDescription($response->content()))->toContain('Dynamic description remains.');
    });
});

// ── Event Override Precedence ─────────────────────────

describe('Event Admin Override Precedence', function () {
    it('overrides event title via admin SEO override', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'Dynamic Event Title'],
            'status' => 'registration_open',
            'is_public' => true,
        ]);
        $event->seo->update(['title' => 'Admin Event Override Title']);

        $response = get(route('events.detail', $event->slug));
        $response->assertOk();
        assertPageTitle($response, 'Admin Event Override Title');
    });

    it('restores dynamic event data when override is cleared', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'Event Dynamic Title'],
            'status' => 'registration_open',
            'is_public' => true,
        ]);

        $event->seo->update(['title' => 'Override Title']);
        $event->seo->update(['title' => null]);

        $response = get(route('events.detail', $event->slug));
        $response->assertOk();
        assertPageTitle($response, 'Event Dynamic Title');
    });
});

// ── Game Override Precedence ──────────────────────────

describe('Game Admin Override Precedence', function () {
    it('overrides game description via admin SEO override', function () {
        $game = Game::factory()->create([
            'name' => ['en' => 'Dynamic Game Title'],
            'description' => ['en' => 'Dynamic game description text.'],
            'visibility' => 'public',
        ]);
        $game->seo->update(['description' => 'Admin game description override.']);

        $description = extractMetaDescription(get(route('games.detail', $game->id))->content());
        expect($description)->toContain('Admin game description override.');
    });

    it('restores dynamic game data when override is cleared', function () {
        $game = Game::factory()->create([
            'name' => ['en' => 'Game Restore Title'],
            'description' => ['en' => 'Original game dynamic description.'],
            'visibility' => 'public',
        ]);

        $game->seo->update(['title' => 'Game Override', 'description' => 'Override description.']);
        $game->seo->update(['title' => null, 'description' => null]);

        $response = get(route('games.detail', $game->id));
        $response->assertOk();
        assertPageTitle($response, 'Game Restore Title');
    });
});

// ── Campaign Override Precedence ──────────────────────

describe('Campaign Admin Override Precedence', function () {
    it('overrides campaign title via admin SEO override', function () {
        $campaign = Campaign::factory()->create([
            'name' => ['en' => 'Dynamic Campaign Title'],
            'visibility' => 'public',
            'status' => 'active',
        ]);
        $campaign->seo->update(['title' => 'Admin Campaign Title']);

        $response = get(route('campaigns.detail', $campaign->id));
        $response->assertOk();
        assertPageTitle($response, 'Admin Campaign Title');
    });

    it('restores dynamic campaign data when override is cleared', function () {
        $campaign = Campaign::factory()->create([
            'name' => ['en' => 'Campaign Dynamic Title'],
            'visibility' => 'public',
            'status' => 'active',
        ]);

        $campaign->seo->update(['title' => 'Override Title']);
        $campaign->seo->update(['title' => null]);

        $response = get(route('campaigns.detail', $campaign->id));
        $response->assertOk();
        assertPageTitle($response, 'Campaign Dynamic Title');
    });
});

// ── Team Override Precedence ──────────────────────────

describe('Team Admin Override Precedence', function () {
    it('overrides team title via admin SEO override', function () {
        $team = Team::factory()->create(['name' => 'Dynamic Team Name', 'is_active' => true]);
        $team->seo->update(['title' => 'Admin Team Override']);

        $response = get(route('teams.detail', $team->slug));
        $response->assertOk();
        assertPageTitle($response, 'Admin Team Override');
    });

    it('restores dynamic team data when override is cleared', function () {
        $team = Team::factory()->create(['name' => 'Team Dynamic Title', 'is_active' => true]);

        $team->seo->update(['title' => 'Override Title']);
        $team->seo->update(['title' => null]);

        $response = get(route('teams.detail', $team->slug));
        $response->assertOk();
        assertPageTitle($response, 'Team Dynamic Title');
    });
});

// ── User Profile Override Precedence ──────────────────

describe('User Profile Admin Override Precedence', function () {
    it('overrides user profile title via admin SEO override', function () {
        $user = User::factory()->create([
            'name' => 'Dynamic User Name',
            'profile_complete' => true,
            'is_disabled' => false,
        ]);
        $user->seo->update(['title' => 'Admin Profile Title']);

        $response = get(route('profile.public', $user->slug));
        $response->assertOk();
        assertPageTitle($response, 'Admin Profile Title');
    });

    it('restores dynamic profile data when override is cleared', function () {
        $user = User::factory()->create([
            'name' => 'User Dynamic Name',
            'profile_complete' => true,
            'is_disabled' => false,
        ]);

        $user->seo->update(['title' => 'Override Title']);
        $user->seo->update(['title' => null]);

        $response = get(route('profile.public', $user->slug));
        $response->assertOk();
        assertPageTitle($response, 'User Dynamic Name');
    });
});

// ── Cache Invalidation on Override ────────────────────

describe('Cache Invalidation on Admin Override', function () {
    it('clears sitemap cache when SEO override is saved', function () {
        Cache::flush();
        $system = GameSystem::factory()->create(['name' => ['en' => 'Cache Test System']]);

        get('/sitemap-game-systems.xml')->assertOk();
        expect(Cache::get('seo:sitemap:game-systems'))->not->toBeNull();

        $system->seo->update(['title' => 'Cache Override Title']);
        app(SeoCacheService::class)->forgetByModel($system);

        expect(Cache::get('seo:sitemap:game-systems'))->toBeNull();
    });

    it('clears sitemap index cache when SEO override is saved', function () {
        Cache::flush();
        $system = GameSystem::factory()->create(['name' => ['en' => 'Index Cache System']]);

        get('/sitemap-game-systems.xml')->assertOk();
        get('/sitemap.xml')->assertOk();

        $system->seo->update(['title' => 'Index Override Title']);
        app(SeoCacheService::class)->forgetByModel($system);

        expect(Cache::get('seo:sitemap:game-systems'))->toBeNull();
        expect(Cache::get('seo:sitemap:index'))->toBeNull();
    });

    it('public page reflects override after cache clear', function () {
        Cache::flush();
        $system = GameSystem::factory()->create([
            'name' => ['en' => 'Cache Reflect System'],
            'description' => ['en' => 'Original description.'],
        ]);

        get('/sitemap-game-systems.xml')->assertOk();

        $system->seo->update(['title' => 'Reflected Override Title']);
        app(SeoCacheService::class)->forgetByModel($system);

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();
        assertPageTitle($response, 'Reflected Override Title');

        get('/sitemap-game-systems.xml')->assertOk();
    });

    // Per-model cache clearing is unit-tested in SeoCacheServiceTest::forgetByModel.
    // The GameSystem test above serves as the integration smoke test.
});

// ── SEO Model Direct Override Tests ──────────────────

describe('SEO Model prepareForUsage Precedence', function () {
    it('SEO model override beats getDynamicSEOData for all fields', function () {
        $system = GameSystem::factory()->create([
            'name' => ['en' => 'Dynamic All Fields'],
            'description' => ['en' => 'Dynamic description text.'],
        ]);

        $system->seo->update([
            'title' => 'Override Title',
            'description' => 'Override Description',
            'image' => 'https://example.com/override.jpg',
            'robots' => 'noindex, follow',
            'canonical_url' => 'https://example.com/canonical',
        ]);

        $seoData = $system->seo->fresh()->prepareForUsage();

        expect($seoData->title)->toBe('Override Title');
        expect($seoData->description)->toBe('Override Description');
        expect($seoData->image)->toBe('https://example.com/override.jpg');
        expect($seoData->robots)->toBe('noindex, follow');
        expect($seoData->canonical_url)->toBe('https://example.com/canonical');
    });

    it('null SEO fields fall through to dynamic data', function () {
        $system = GameSystem::factory()->create([
            'name' => ['en' => 'Dynamic Fallthrough'],
            'description' => ['en' => 'Dynamic desc falls through.'],
        ]);

        $seoData = $system->seo->prepareForUsage();

        expect($seoData->title)->toBe('Dynamic Fallthrough');
        expect($seoData->description)->toContain('Dynamic desc falls through.');
        expect($seoData->robots)->toBe('index, follow');
    });

    it('mixed null and set fields correctly merge', function () {
        $system = GameSystem::factory()->create([
            'name' => ['en' => 'Mixed Merge System'],
            'description' => ['en' => 'Dynamic description stays.'],
        ]);

        $system->seo->update([
            'title' => 'Only Title Override',
            'description' => null,
            'robots' => 'noindex, nofollow',
        ]);

        $seoData = $system->seo->fresh()->prepareForUsage();

        expect($seoData->title)->toBe('Only Title Override');
        expect($seoData->description)->toContain('Dynamic description stays.');
        expect($seoData->robots)->toBe('noindex, nofollow');
    });
});

// ── Override Persistence Across Refresh ───────────────

describe('Override Persistence', function () {
    it('persists override after model refresh', function () {
        $system = GameSystem::factory()->create(['name' => ['en' => 'Persist System']]);
        $system->seo->update(['title' => 'Persisted Title']);

        $freshSystem = GameSystem::with('seo')->find($system->id);
        $seoData = $freshSystem->seo->prepareForUsage();

        expect($seoData->title)->toBe('Persisted Title');
    });

    it('restores dynamic data after deleting override row fields', function () {
        $system = GameSystem::factory()->create([
            'name' => ['en' => 'Delete Override System'],
            'description' => ['en' => 'Dynamic comes back.'],
        ]);

        $system->seo->update([
            'title' => 'To Be Deleted',
            'description' => 'To be deleted desc.',
        ]);

        $system->seo->update(['title' => null, 'description' => null]);

        $freshSystem = GameSystem::with('seo')->find($system->id);
        $seoData = $freshSystem->seo->prepareForUsage();

        expect($seoData->title)->toBe('Delete Override System');
        expect($seoData->description)->toContain('Dynamic comes back.');
    });
});

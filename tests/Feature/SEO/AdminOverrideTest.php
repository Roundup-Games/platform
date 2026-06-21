<?php

use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Team;
use App\Models\User;
use App\Services\SeoCacheService;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\get;

// Per-entity admin override behaviour. Each SEO-enabled entity exposes the
// same two contracts: (1) admin-set SEO fields override dynamic data, and
// (2) clearing the override restores the dynamic value. Both contracts are
// driven here by Pest datasets over the entity types. GameSystem-specific
// field overrides (description / image / robots / canonical / partial) and
// cache-invalidation behaviour are kept as separate it(...) blocks since
// they cover genuinely different ground.

// Each dataset row is wrapped in an outer array so the descriptor binds to a
// single `$d` closure parameter.
dataset('seo_entity_title_override', [
    'GameSystem' => [[
        'make' => fn () => GameSystem::factory()->create(['name' => ['en' => 'Dynamic Title System']]),
        'route' => fn ($m) => route('game-systems.show', $m->slug),
        'expected' => 'Admin Override Title',
    ]],
    'Event' => [[
        'make' => fn () => Event::factory()->create([
            'name' => ['en' => 'Dynamic Event Title'],
            'status' => 'registration_open',
            'is_public' => true,
        ]),
        'route' => fn ($m) => route('events.detail', $m->slug),
        'expected' => 'Admin Event Override Title',
    ]],
    'Campaign' => [[
        'make' => fn () => Campaign::factory()->create([
            'name' => ['en' => 'Dynamic Campaign Title'],
            'visibility' => 'public',
            'status' => 'active',
        ]),
        'route' => fn ($m) => route('campaigns.detail', $m->id),
        'expected' => 'Admin Campaign Title',
    ]],
    'Team' => [[
        'make' => fn () => Team::factory()->create(['name' => 'Dynamic Team Name', 'is_active' => true]),
        'route' => fn ($m) => route('teams.detail', $m->slug),
        'expected' => 'Admin Team Override',
    ]],
    'User' => [[
        'make' => fn () => User::factory()->create([
            'name' => 'Dynamic User Name',
            'profile_complete' => true,
            'is_disabled' => false,
        ]),
        'route' => fn ($m) => route('profile.public', $m->slug),
        'expected' => 'Admin Profile Title',
    ]],
]);

dataset('seo_entity_restore_on_clear', [
    'GameSystem' => [[
        'make' => fn () => GameSystem::factory()->create(['name' => ['en' => 'Restore Dynamic Title']]),
        'route' => fn ($m) => route('game-systems.show', $m->slug),
        'expected' => 'Restore Dynamic Title',
    ]],
    'Event' => [[
        'make' => fn () => Event::factory()->create([
            'name' => ['en' => 'Event Dynamic Title'],
            'status' => 'registration_open',
            'is_public' => true,
        ]),
        'route' => fn ($m) => route('events.detail', $m->slug),
        'expected' => 'Event Dynamic Title',
    ]],
    'Game' => [[
        'make' => fn () => Game::factory()->create([
            'name' => ['en' => 'Game Restore Title'],
            'description' => ['en' => 'Original game dynamic description.'],
            'visibility' => 'public',
        ]),
        'route' => fn ($m) => route('games.detail', $m->id),
        'expected' => 'Game Restore Title',
    ]],
    'Campaign' => [[
        'make' => fn () => Campaign::factory()->create([
            'name' => ['en' => 'Campaign Dynamic Title'],
            'visibility' => 'public',
            'status' => 'active',
        ]),
        'route' => fn ($m) => route('campaigns.detail', $m->id),
        'expected' => 'Campaign Dynamic Title',
    ]],
    'Team' => [[
        'make' => fn () => Team::factory()->create(['name' => 'Team Dynamic Title', 'is_active' => true]),
        'route' => fn ($m) => route('teams.detail', $m->slug),
        'expected' => 'Team Dynamic Title',
    ]],
    'User' => [[
        'make' => fn () => User::factory()->create([
            'name' => 'User Dynamic Name',
            'profile_complete' => true,
            'is_disabled' => false,
        ]),
        'route' => fn ($m) => route('profile.public', $m->slug),
        'expected' => 'User Dynamic Name',
    ]],
]);

describe('Admin Override Precedence (parameterized)', function () {
    it('overrides title via admin SEO override across entity types', function (array $d) {
        $model = ($d['make'])();
        $model->seo->update(['title' => $d['expected']]);

        $response = get(($d['route'])($model));
        $response->assertOk();
        assertPageTitle($response, $d['expected']);
    })->with('seo_entity_title_override');

    it('restores dynamic title when override is cleared across entity types', function (array $d) {
        $model = ($d['make'])();
        $model->seo->update(['title' => 'Override Title']);
        $model->seo->update(['title' => null]);

        $response = get(($d['route'])($model));
        $response->assertOk();
        assertPageTitle($response, $d['expected']);
    })->with('seo_entity_restore_on_clear');
});

// ── GameSystem-specific field overrides (each field asserts its own contract) ──

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

// ── Game description override (Game has no title-override test, only description) ──

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
});

// ── SEO Model prepareForUsage Precedence ──────────────

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

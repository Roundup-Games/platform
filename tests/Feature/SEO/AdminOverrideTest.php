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

// Per-entity admin override behaviour on the D172 standard: translatable
// seo_title/seo_description columns resolved through
// HasEntitySeo::entitySeoData(locale) over the model's generated
// getDynamicSEOData() output. Each SEO-enabled entity exposes the same
// two contracts: (1) a curated override beats the generated metadata, and
// (2) clearing the override restores the generated value. Both contracts
// are driven by Pest datasets over the entity types.
//
// The retired seo-table row layer also allowed image/canonical/robots
// overrides — dropped by D172 (never used on the live deployment; images,
// robots, and JSON-LD schema are generator-owned). Cross-locale semantics
// (no leak between locales) are covered by EntitySeoTest.

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
    it('overrides title via curated column across entity types', function (array $d) {
        $model = ($d['make'])();
        $model->setTranslation('seo_title', 'en', $d['expected']);
        $model->save();

        $response = get(($d['route'])($model));
        $response->assertOk();
        assertPageTitle($response, $d['expected']);
    })->with('seo_entity_title_override');

    it('restores dynamic title when override is cleared across entity types', function (array $d) {
        $model = ($d['make'])();
        $model->setTranslation('seo_title', 'en', 'Override Title');
        $model->save();

        // Clearing the locale override restores the generated value.
        $model->setTranslation('seo_title', 'en', null);
        $model->save();

        $response = get(($d['route'])($model));
        $response->assertOk();
        assertPageTitle($response, $d['expected']);
    })->with('seo_entity_restore_on_clear');
});

// ── GameSystem-specific field contracts ───────────────

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

    it('overrides description via curated column', function () {
        $system = GameSystem::factory()->create([
            'name' => ['en' => 'Desc Override System'],
            'description' => ['en' => 'Dynamic description.'],
        ]);
        $system->setTranslation('seo_description', 'en', 'Admin override description for SEO.');
        $system->save();

        $content = get(route('game-systems.show', $system->slug))->content();
        expect(extractMetaDescription($content))->toContain('Admin override description for SEO.');
    });

    it('allows partial overrides: curated title with generated description', function () {
        $system = GameSystem::factory()->create([
            'name' => ['en' => 'Partial Override System'],
            'description' => ['en' => 'Dynamic description remains.'],
        ]);

        $system->setTranslation('seo_title', 'en', 'Custom Admin Title');
        $system->save();

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();
        assertPageTitle($response, 'Custom Admin Title');
        expect(extractMetaDescription($response->content()))->toContain('Dynamic description remains.');
    });

    it('keeps robots generator-owned: a title override never changes indexing', function () {
        $system = GameSystem::factory()->create(['name' => ['en' => 'Robots Boundary System']]);

        $system->setTranslation('seo_title', 'en', 'Curated Title');
        $system->save();

        $seoData = $system->entitySeoData('en');

        expect($seoData->title)->toBe('Curated Title')
            ->and($seoData->robots)->toBe($system->getDynamicSEOData()->robots); // generator value, untouched by curation
    });
});

// ── Game description override ─────────────────────────

describe('Game Admin Override Precedence', function () {
    it('overrides game description via curated column', function () {
        $game = Game::factory()->create([
            'name' => ['en' => 'Dynamic Game Title'],
            'description' => ['en' => 'Dynamic game description text.'],
            'visibility' => 'public',
        ]);
        $game->setTranslation('seo_description', 'en', 'Admin game description override.');
        $game->save();

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

        $system->setTranslation('seo_title', 'en', 'Cache Override Title');
        $system->save();
        app(SeoCacheService::class)->forgetByModel($system);

        expect(Cache::get('seo:sitemap:game-systems'))->toBeNull();
    });

    it('clears sitemap index cache when SEO override is saved', function () {
        Cache::flush();
        $system = GameSystem::factory()->create(['name' => ['en' => 'Index Cache System']]);

        get('/sitemap-game-systems.xml')->assertOk();
        get('/sitemap.xml')->assertOk();

        $system->setTranslation('seo_title', 'en', 'Index Override Title');
        $system->save();
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

        $system->setTranslation('seo_title', 'en', 'Reflected Override Title');
        $system->save();
        app(SeoCacheService::class)->forgetByModel($system);

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();
        assertPageTitle($response, 'Reflected Override Title');

        get('/sitemap-game-systems.xml')->assertOk();
    });
});

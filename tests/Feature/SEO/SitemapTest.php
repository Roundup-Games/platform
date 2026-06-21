<?php

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\get;

// Sitemap index + sub-sitemap contracts. Per-type positive inclusion and
// per-type exclusion are each consolidated into a single Pest dataset
// (covers + excludes below); per-type changefreq/priority assertions were
// dropped because they re-state static config values. The 'XML Well-Formedness'
// dataset exercises every sitemap route through simplexml_load_string.

// ── Sitemap Index (/sitemap.xml) ───────────────────────

describe('Sitemap Index', function () {
    it('returns 200 with application/xml content type', function () {
        get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml');
    });

    it('returns valid XML sitemap index', function () {
        $content = get('/sitemap.xml')->content();

        expect($content)->toStartWith('<?xml version="1.0" encoding="UTF-8"?>');
        expect($content)->toContain('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">');
        expect($content)->toContain('</sitemapindex>');
    });

    it('lists all expected sub-sitemaps', function () {
        $content = get('/sitemap.xml')->content();

        foreach (['static', 'game-systems', 'events', 'games', 'campaigns', 'teams', 'profiles', 'venues'] as $type) {
            expect($content)->toContain("/sitemap-{$type}.xml");
        }
    });

    it('includes lastmod for each sub-sitemap', function () {
        $content = get('/sitemap.xml')->content();

        preg_match_all('/<sitemap>(.*?)<\/sitemap>/s', $content, $blocks);
        expect($blocks[0])->toHaveCount(8);

        foreach ($blocks[0] as $block) {
            expect($block)->toContain('<lastmod>');
        }
    });

    it('caches the index on second request', function () {
        Cache::flush();

        get('/sitemap.xml')->assertOk();
        $firstContent = get('/sitemap.xml')->content();

        $secondContent = get('/sitemap.xml')->content();
        expect($secondContent)->toBe($firstContent);
    });
});

// ── Sub-Sitemap Routing ───────────────────────────────

describe('Sub-Sitemap Routing', function () {
    it('returns non-200 for invalid sitemap type', function () {
        $response = get('/sitemap-invalid.xml');
        expect($response->status())->not->toBe(200);
        expect($response->content())->not->toContain('<urlset');
    });

    it('returns non-200 for arbitrary type', function () {
        $response = get('/sitemap-foo.xml');
        expect($response->status())->not->toBe(200);
        expect($response->content())->not->toContain('<urlset');
    });

    it('returns 200 with XML content for each valid type', function () {
        foreach (['static', 'game-systems', 'events', 'games', 'campaigns', 'teams', 'profiles', 'venues'] as $type) {
            get("/sitemap-{$type}.xml")
                ->assertOk()
                ->assertHeader('Content-Type', 'application/xml');
        }
    });
});

// ── Static Pages Sitemap ──────────────────────────────

describe('Static Pages Sitemap', function () {
    it('contains urlset with correct namespace', function () {
        $content = get('/sitemap-static.xml')->content();

        expect($content)->toContain('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">');
        expect($content)->toContain('</urlset>');
    });

    it('includes all static paths for both en and de locales', function () {
        $content = get('/sitemap-static.xml')->content();
        $baseUrl = config('app.url');

        $paths = ['/', '/about', '/how-it-works', '/for-organizers', '/contact', '/safety-tools', '/gms', '/game-systems', '/our-pledge', '/our-pledge/algorithms'];

        foreach (['en', 'de'] as $locale) {
            foreach ($paths as $path) {
                expect($content)->toContain("{$baseUrl}/{$locale}{$path}");
            }
        }
    });

    it('includes changefreq and priority for each url', function () {
        $content = get('/sitemap-static.xml')->content();

        preg_match_all('/<url>(.*?)<\/url>/s', $content, $blocks);
        // 10 paths × 2 locales = 20 entries
        expect($blocks[0])->toHaveCount(20);

        foreach ($blocks[0] as $block) {
            expect($block)->toContain('<changefreq>monthly</changefreq>');
            expect($block)->toContain('<priority>0.8</priority>');
        }
    });
});

// ── Positive inclusion: each indexable entity appears with both locale URLs ──

dataset('sitemap_indexed_entities', [
    'GameSystem' => [[
        'sitemap' => '/sitemap-game-systems.xml',
        'make' => fn () => GameSystem::factory()->create(['name' => ['en' => 'Sitemap Test System']]),
        'url' => fn ($m) => "/game-systems/{$m->slug}",
    ]],
    'Event' => [[
        'sitemap' => '/sitemap-events.xml',
        'make' => fn () => Event::factory()->create([
            'status' => 'published',
            'is_public' => true,
            'slug' => 'published-event-sitemap',
        ]),
        'url' => fn ($m) => "/events/{$m->slug}",
    ]],
    'Game' => [[
        'sitemap' => '/sitemap-games.xml',
        'make' => fn () => Game::factory()->create([
            'visibility' => Visibility::Public,
            'status' => 'scheduled',
        ]),
        'url' => fn ($m) => "/games/{$m->id}",
    ]],
    'Campaign' => [[
        'sitemap' => '/sitemap-campaigns.xml',
        'make' => fn () => Campaign::factory()->create([
            'visibility' => Visibility::Public,
            'status' => CampaignStatus::Active->value,
        ]),
        'url' => fn ($m) => "/campaigns/{$m->id}",
    ]],
    'Team' => [[
        'sitemap' => '/sitemap-teams.xml',
        'make' => fn () => Team::factory()->create(['is_active' => true]),
        'url' => fn ($m) => "/teams/{$m->slug}",
    ]],
    'Profile' => [[
        'sitemap' => '/sitemap-profiles.xml',
        'make' => fn () => User::factory()->create([
            'profile_complete' => true,
            'slug' => 'test-user-profile',
            'is_disabled' => false,
        ]),
        'url' => fn ($m) => "/u/{$m->slug}",
    ]],
]);

describe('Sub-sitemap positive inclusion', function () {
    it('includes each indexable entity with both en and de locale URLs', function (array $d) {
        $model = ($d['make'])();
        $baseUrl = config('app.url');
        $url = ($d['url'])($model);

        $content = get($d['sitemap'])->content();

        expect($content)->toContain("{$baseUrl}/en{$url}");
        expect($content)->toContain("{$baseUrl}/de{$url}");
    })->with('sitemap_indexed_entities');

    it('includes every game system record in the sitemap', function () {
        $systems = GameSystem::factory()->count(3)->create();

        $content = get('/sitemap-game-systems.xml')->content();

        foreach ($systems as $system) {
            expect($content)->toContain("/game-systems/{$system->slug}");
        }
    });

    it('includes events with valid public statuses', function ($status) {
        $event = Event::factory()->create([
            'status' => $status,
            'is_public' => true,
        ]);

        $content = get('/sitemap-events.xml')->content();

        expect($content)->toContain("/events/{$event->slug}");
    })->with(['registration_open', 'registration_closed', 'in_progress']);
});

// ── Negative inclusion: each non-indexable state is excluded ────────────────

dataset('sitemap_excluded_states', [
    'Event draft' => [[
        'sitemap' => '/sitemap-events.xml',
        'make' => fn () => Event::factory()->create(['status' => 'draft', 'is_public' => true]),
        'url' => fn ($m) => "/events/{$m->slug}",
    ]],
    'Event cancelled' => [[
        'sitemap' => '/sitemap-events.xml',
        'make' => fn () => Event::factory()->create(['status' => 'cancelled', 'is_public' => true]),
        'url' => fn ($m) => "/events/{$m->slug}",
    ]],
    'Event non-public' => [[
        'sitemap' => '/sitemap-events.xml',
        'make' => fn () => Event::factory()->create(['status' => 'published', 'is_public' => false]),
        'url' => fn ($m) => "/events/{$m->slug}",
    ]],
    'Game private' => [[
        'sitemap' => '/sitemap-games.xml',
        'make' => fn () => Game::factory()->create(['visibility' => Visibility::Private]),
        'url' => fn ($m) => "/games/{$m->id}",
    ]],
    'Game protected' => [[
        'sitemap' => '/sitemap-games.xml',
        'make' => fn () => Game::factory()->create(['visibility' => Visibility::Protected]),
        'url' => fn ($m) => "/games/{$m->id}",
    ]],
    'Game canceled' => [[
        'sitemap' => '/sitemap-games.xml',
        'make' => fn () => Game::factory()->create([
            'visibility' => Visibility::Public,
            'status' => GameStatus::Canceled->value,
        ]),
        'url' => fn ($m) => "/games/{$m->id}",
    ]],
    'Campaign private' => [[
        'sitemap' => '/sitemap-campaigns.xml',
        'make' => fn () => Campaign::factory()->create(['visibility' => Visibility::Private]),
        'url' => fn ($m) => "/campaigns/{$m->id}",
    ]],
    'Campaign protected' => [[
        'sitemap' => '/sitemap-campaigns.xml',
        'make' => fn () => Campaign::factory()->create(['visibility' => Visibility::Protected]),
        'url' => fn ($m) => "/campaigns/{$m->id}",
    ]],
    'Campaign cancelled' => [[
        'sitemap' => '/sitemap-campaigns.xml',
        'make' => fn () => Campaign::factory()->create([
            'visibility' => Visibility::Public,
            'status' => CampaignStatus::Cancelled->value,
        ]),
        'url' => fn ($m) => "/campaigns/{$m->id}",
    ]],
    'Team inactive' => [[
        'sitemap' => '/sitemap-teams.xml',
        'make' => fn () => Team::factory()->create(['is_active' => false]),
        'url' => fn ($m) => "/teams/{$m->slug}",
    ]],
    'Profile incomplete' => [[
        'sitemap' => '/sitemap-profiles.xml',
        'make' => fn () => User::factory()->create(['profile_complete' => false, 'is_disabled' => false]),
        'url' => fn ($m) => "/u/{$m->slug}",
    ]],
    'Profile disabled' => [[
        'sitemap' => '/sitemap-profiles.xml',
        'make' => fn () => User::factory()->create(['profile_complete' => true, 'is_disabled' => true]),
        'url' => fn ($m) => "/u/{$m->slug}",
    ]],
]);

describe('Sub-sitemap exclusion', function () {
    it('excludes each non-indexable entity state from its sitemap', function (array $d) {
        $model = ($d['make'])();
        $url = ($d['url'])($model);

        $content = get($d['sitemap'])->content();

        expect($content)->not->toContain($url);
    })->with('sitemap_excluded_states');

    it('excludes users without a slug', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'is_disabled' => false,
            'slug' => null,
        ]);

        $content = get('/sitemap-profiles.xml')->content();

        expect($content)->not->toContain("/u/{$user->name}");
    });
});

// ── Cache Invalidation ────────────────────────────────

describe('Cache Invalidation', function () {
    it('serves fresh content after cache invalidation', function () {
        Cache::flush();

        $system1 = GameSystem::factory()->create(['name' => ['en' => 'Before Invalidation']]);
        $firstContent = get('/sitemap-game-systems.xml')->content();
        expect($firstContent)->toContain("/game-systems/{$system1->slug}");

        Cache::forget('seo:sitemap:game-systems');

        $system2 = GameSystem::factory()->create(['name' => ['en' => 'After Invalidation']]);

        $secondContent = get('/sitemap-game-systems.xml')->content();
        expect($secondContent)->toContain("/game-systems/{$system2->slug}");
        expect($secondContent)->toContain("/game-systems/{$system1->slug}");
    });
});

// ── XML Well-Formedness ───────────────────────────────

describe('XML Well-Formedness', function () {
    it('all sitemap types produce well-formed XML with correct root element', function ($path, $expectedRoot) {
        if (str_contains($path, 'game-systems')) {
            GameSystem::factory()->create();
        } elseif (str_contains($path, 'events')) {
            Event::factory()->create(['status' => 'published', 'is_public' => true]);
        } elseif (str_contains($path, 'games')) {
            Game::factory()->create(['visibility' => Visibility::Public]);
        } elseif (str_contains($path, 'campaigns')) {
            Campaign::factory()->create(['visibility' => Visibility::Public]);
        } elseif (str_contains($path, 'teams')) {
            Team::factory()->create(['is_active' => true]);
        } elseif (str_contains($path, 'profiles')) {
            User::factory()->create(['profile_complete' => true, 'is_disabled' => false]);
        }

        $content = get($path)->content();

        $doc = simplexml_load_string($content);
        expect($doc)->not->toBeFalse("Failed to parse XML from {$path}");
        expect($doc->getName())->toBe($expectedRoot);
    })->with([
        ['/sitemap.xml', 'sitemapindex'],
        ['/sitemap-static.xml', 'urlset'],
        ['/sitemap-game-systems.xml', 'urlset'],
        ['/sitemap-events.xml', 'urlset'],
        ['/sitemap-games.xml', 'urlset'],
        ['/sitemap-campaigns.xml', 'urlset'],
        ['/sitemap-teams.xml', 'urlset'],
        ['/sitemap-profiles.xml', 'urlset'],
        ['/sitemap-venues.xml', 'urlset'],
    ]);
});

<?php

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\Visibility;
use App\Http\Controllers\SitemapController;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
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
    })->group('smoke');

    it('returns valid XML sitemap index', function () {
        $content = get('/sitemap.xml')->content();

        expect($content)->toStartWith('<?xml version="1.0" encoding="UTF-8"?>');
        expect($content)->toContain('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">');
        expect($content)->toContain('</sitemapindex>');
    });

    it('lists all expected sub-sitemaps', function () {
        $content = get('/sitemap.xml')->content();

        foreach (['static', 'game-systems', 'events', 'games', 'campaigns', 'profiles', 'venues', 'cities'] as $type) {
            expect($content)->toContain("/sitemap-{$type}.xml");
        }
    });

    it('omits the retired teams sub-sitemap from the index (M066/S06/T02)', function () {
        $content = get('/sitemap.xml')->content();

        // Teams pages sit behind login — no longer an indexable SEO surface.
        expect($content)->not->toContain('/sitemap-teams.xml');
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

    it('no longer serves the retired teams sitemap (M066/S06/T02)', function () {
        // 'teams' is dropped from the route constraint, so the URL falls
        // through to the locale catch-all (the /sitemap-invalid.xml path)
        // and never serves a urlset. The final locale-prefixed hop 404s.
        $response = get('/sitemap-teams.xml');

        expect($response->status())->not->toBe(200);
        expect($response->content())->not->toContain('<urlset');
    });

    it('returns 200 with XML content for each valid type', function () {
        foreach (['static', 'game-systems', 'events', 'games', 'campaigns', 'profiles', 'venues', 'cities'] as $type) {
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

        $paths = ['/', '/discover', '/discover/board-games', '/discover/adventures', '/about', '/how-it-works', '/for-organizers', '/pricing', '/contact', '/faq', '/safety-tools', '/safety/code-of-conduct', '/gms', '/game-systems', '/cities', '/our-pledge', '/our-pledge/algorithms', '/our-pledge/roadmap'];

        foreach (['en', 'de'] as $locale) {
            foreach ($paths as $path) {
                $expected = $path === '/'
                    ? "<loc>{$baseUrl}/{$locale}</loc>"
                    : "{$baseUrl}/{$locale}{$path}";
                expect($content)->toContain($expected);
            }
        }
    });

    it('emits locale roots in canonical form without a trailing slash', function () {
        $content = get('/sitemap-static.xml')->content();
        $baseUrl = config('app.url');

        expect($content)
            ->toContain("<loc>{$baseUrl}/en</loc>")
            ->toContain("<loc>{$baseUrl}/de</loc>")
            ->not->toContain("{$baseUrl}/en/</loc>")
            ->not->toContain("{$baseUrl}/de/</loc>");
    });

    it('includes changefreq and priority for each url', function () {
        $content = get('/sitemap-static.xml')->content();

        preg_match_all('/<url>(.*?)<\/url>/s', $content, $blocks);
        // Derived from the controller's path list (SEO 2026-10-15 added the
        // /discover forks — the core product surface previously absent),
        // so the count can never desync from STATIC_PATHS again.
        expect($blocks[0])->toHaveCount(count(SitemapController::STATIC_PATHS) * 2);

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
        } elseif (str_contains($path, 'profiles')) {
            User::factory()->create(['profile_complete' => true, 'is_disabled' => false]);
        } elseif (str_contains($path, 'cities')) {
            // A sessions-qualified cluster (3 upcoming public games) so the
            // cities urlset carries a real entry, not just an empty shell.
            $cologne = Location::factory()->create([
                'city' => 'Koeln',
                'country' => 'DEU',
                'latitude' => 50.9375,
                'longitude' => 6.9603,
            ]);
            Game::factory()->count(3)->create([
                'location_id' => $cologne->id,
                'date_time' => now()->addDays(3),
            ]);
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
        ['/sitemap-profiles.xml', 'urlset'],
        ['/sitemap-venues.xml', 'urlset'],
        ['/sitemap-cities.xml', 'urlset'],
    ]);
});

// ── Robots policy: crawler posture and sitemap discovery ──

it('serves a robots.txt with sitemap directive and open crawler policy', function () {
    // robots.txt is served by the webserver as a static public file (never
    // routed through Laravel), so pin the deployed artifact directly.
    $body = (string) file_get_contents(public_path('robots.txt'));

    expect($body)
        ->toContain('Sitemap: https://roundup.games/sitemap.xml')
        ->toContain('User-agent: *')
        ->not->toContain('Disallow: /')
        // AI-crawler posture is explicit (llms.txt is served alongside)
        ->toContain('User-agent: GPTBot')
        ->toContain('User-agent: ClaudeBot')
        ->toContain('User-agent: PerplexityBot');
});

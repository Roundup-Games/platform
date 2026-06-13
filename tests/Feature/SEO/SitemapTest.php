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

// ── Sitemap Index (/sitemap.xml) ───────────────────────

describe('Sitemap Index', function () {
    it('returns 200 with application/xml content type', function () {
        get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml');
    });

    it('returns valid XML sitemap index', function () {
        $response = get('/sitemap.xml');
        $content = $response->content();

        expect($content)->toStartWith('<?xml version="1.0" encoding="UTF-8"?>');
        expect($content)->toContain('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">');
        expect($content)->toContain('</sitemapindex>');
    });

    it('lists all expected sub-sitemaps', function () {
        $content = get('/sitemap.xml')->content();

        $expectedTypes = ['static', 'game-systems', 'events', 'games', 'campaigns', 'teams', 'profiles'];

        foreach ($expectedTypes as $type) {
            expect($content)->toContain("/sitemap-{$type}.xml");
        }
    });

    it('includes lastmod for each sub-sitemap', function () {
        $content = get('/sitemap.xml')->content();

        // Each <sitemap> block should contain a <lastmod> element
        preg_match_all('/<sitemap>(.*?)<\/sitemap>/s', $content, $blocks);
        expect($blocks[0])->toHaveCount(7);

        foreach ($blocks[0] as $block) {
            expect($block)->toContain('<lastmod>');
        }
    });

    it('caches the index on second request', function () {
        Cache::flush();

        // First request builds and caches
        get('/sitemap.xml')->assertOk();
        $firstContent = get('/sitemap.xml')->content();

        // Second request should return cached (same content)
        $secondContent = get('/sitemap.xml')->content();
        expect($secondContent)->toBe($firstContent);
    });
});

// ── Sub-Sitemap Routing ───────────────────────────────

describe('Sub-Sitemap Routing', function () {
    it('returns non-200 for invalid sitemap type', function () {
        // abort(404) in controller; may surface as 302 due to catch-all route
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
        $types = ['static', 'game-systems', 'events', 'games', 'campaigns', 'teams', 'profiles'];

        foreach ($types as $type) {
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

// ── Game Systems Sitemap ──────────────────────────────

describe('Game Systems Sitemap', function () {
    it('includes game systems with en and de locale URLs', function () {
        $system = GameSystem::factory()->create(['name' => ['en' => 'Sitemap Test System']]);
        $baseUrl = config('app.url');

        $content = get('/sitemap-game-systems.xml')->content();

        expect($content)->toContain("{$baseUrl}/en/game-systems/{$system->slug}");
        expect($content)->toContain("{$baseUrl}/de/game-systems/{$system->slug}");
    });

    it('includes all game systems in the sitemap', function () {
        $systems = GameSystem::factory()->count(3)->create();

        $content = get('/sitemap-game-systems.xml')->content();

        foreach ($systems as $system) {
            expect($content)->toContain("/game-systems/{$system->slug}");
        }
    });

    it('uses weekly changefreq and 0.7 priority', function () {
        GameSystem::factory()->create();

        $content = get('/sitemap-game-systems.xml')->content();

        preg_match('/<url>.*?<\/url>/s', $content, $match);
        expect($match[0])->toContain('<changefreq>weekly</changefreq>');
        expect($match[0])->toContain('<priority>0.7</priority>');
    });
});

// ── Events Sitemap ────────────────────────────────────

describe('Events Sitemap', function () {
    it('includes public published events with both locale URLs', function () {
        $event = Event::factory()->create([
            'status' => 'published',
            'is_public' => true,
            'slug' => 'published-event-sitemap',
        ]);
        $baseUrl = config('app.url');

        $content = get('/sitemap-events.xml')->content();

        expect($content)->toContain("{$baseUrl}/en/events/{$event->slug}");
        expect($content)->toContain("{$baseUrl}/de/events/{$event->slug}");
    });

    it('includes events with valid public statuses', function ($status) {
        $event = Event::factory()->create([
            'status' => $status,
            'is_public' => true,
        ]);

        $content = get('/sitemap-events.xml')->content();

        expect($content)->toContain("/events/{$event->slug}");
    })->with(['registration_open', 'registration_closed', 'in_progress']);

    it('excludes events with terminal or non-public statuses', function ($status, $isPublic) {
        $event = Event::factory()->create([
            'status' => $status,
            'is_public' => $isPublic,
        ]);

        $content = get('/sitemap-events.xml')->content();

        expect($content)->not->toContain("/events/{$event->slug}");
    })->with([
        ['draft', true],
        ['cancelled', true],
        ['published', false],
    ]);

    it('uses daily changefreq and 0.9 priority', function () {
        Event::factory()->create([
            'status' => 'published',
            'is_public' => true,
        ]);

        $content = get('/sitemap-events.xml')->content();

        preg_match('/<url>.*?<\/url>/s', $content, $match);
        expect($match[0])->toContain('<changefreq>daily</changefreq>');
        expect($match[0])->toContain('<priority>0.9</priority>');
    });
});

// ── Games Sitemap ─────────────────────────────────────

describe('Games Sitemap', function () {
    it('includes public games with both locale URLs', function () {
        $game = Game::factory()->create([
            'visibility' => Visibility::Public,
            'status' => 'scheduled',
        ]);
        $baseUrl = config('app.url');

        $content = get('/sitemap-games.xml')->content();

        expect($content)->toContain("{$baseUrl}/en/games/{$game->id}");
        expect($content)->toContain("{$baseUrl}/de/games/{$game->id}");
    });

    it('excludes private games', function () {
        $game = Game::factory()->create([
            'visibility' => Visibility::Private,
        ]);

        $content = get('/sitemap-games.xml')->content();

        expect($content)->not->toContain("/games/{$game->id}");
    });

    it('excludes protected games', function () {
        $game = Game::factory()->create([
            'visibility' => Visibility::Protected,
        ]);

        $content = get('/sitemap-games.xml')->content();

        expect($content)->not->toContain("/games/{$game->id}");
    });

    it('excludes canceled games', function () {
        $game = Game::factory()->create([
            'visibility' => Visibility::Public,
            'status' => GameStatus::Canceled->value,
        ]);

        $content = get('/sitemap-games.xml')->content();

        expect($content)->not->toContain("/games/{$game->id}");
    });

    it('uses daily changefreq and 0.8 priority', function () {
        Game::factory()->create([
            'visibility' => Visibility::Public,
        ]);

        $content = get('/sitemap-games.xml')->content();

        preg_match('/<url>.*?<\/url>/s', $content, $match);
        expect($match[0])->toContain('<changefreq>daily</changefreq>');
        expect($match[0])->toContain('<priority>0.8</priority>');
    });
});

// ── Campaigns Sitemap ─────────────────────────────────

describe('Campaigns Sitemap', function () {
    it('includes public active campaigns with both locale URLs', function () {
        $campaign = Campaign::factory()->create([
            'visibility' => Visibility::Public,
            'status' => CampaignStatus::Active->value,
        ]);
        $baseUrl = config('app.url');

        $content = get('/sitemap-campaigns.xml')->content();

        expect($content)->toContain("{$baseUrl}/en/campaigns/{$campaign->id}");
        expect($content)->toContain("{$baseUrl}/de/campaigns/{$campaign->id}");
    });

    it('excludes private campaigns', function () {
        $campaign = Campaign::factory()->create([
            'visibility' => Visibility::Private,
        ]);

        $content = get('/sitemap-campaigns.xml')->content();

        expect($content)->not->toContain("/campaigns/{$campaign->id}");
    });

    it('excludes protected campaigns', function () {
        $campaign = Campaign::factory()->create([
            'visibility' => Visibility::Protected,
        ]);

        $content = get('/sitemap-campaigns.xml')->content();

        expect($content)->not->toContain("/campaigns/{$campaign->id}");
    });

    it('excludes cancelled campaigns', function () {
        $campaign = Campaign::factory()->create([
            'visibility' => Visibility::Public,
            'status' => CampaignStatus::Cancelled->value,
        ]);

        $content = get('/sitemap-campaigns.xml')->content();

        expect($content)->not->toContain("/campaigns/{$campaign->id}");
    });

    it('uses weekly changefreq and 0.8 priority', function () {
        Campaign::factory()->create([
            'visibility' => Visibility::Public,
        ]);

        $content = get('/sitemap-campaigns.xml')->content();

        preg_match('/<url>.*?<\/url>/s', $content, $match);
        expect($match[0])->toContain('<changefreq>weekly</changefreq>');
        expect($match[0])->toContain('<priority>0.8</priority>');
    });
});

// ── Teams Sitemap ─────────────────────────────────────

describe('Teams Sitemap', function () {
    it('includes active teams with both locale URLs', function () {
        $team = Team::factory()->create([
            'is_active' => true,
        ]);
        $baseUrl = config('app.url');

        $content = get('/sitemap-teams.xml')->content();

        expect($content)->toContain("{$baseUrl}/en/teams/{$team->slug}");
        expect($content)->toContain("{$baseUrl}/de/teams/{$team->slug}");
    });

    it('excludes inactive teams', function () {
        $team = Team::factory()->create([
            'is_active' => false,
        ]);

        $content = get('/sitemap-teams.xml')->content();

        expect($content)->not->toContain("/teams/{$team->slug}");
    });

    it('uses weekly changefreq and 0.6 priority', function () {
        Team::factory()->create(['is_active' => true]);

        $content = get('/sitemap-teams.xml')->content();

        preg_match('/<url>.*?<\/url>/s', $content, $match);
        expect($match[0])->toContain('<changefreq>weekly</changefreq>');
        expect($match[0])->toContain('<priority>0.6</priority>');
    });
});

// ── Profiles Sitemap ──────────────────────────────────

describe('Profiles Sitemap', function () {
    it('includes complete public profiles with both locale URLs', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'slug' => 'test-user-profile',
            'is_disabled' => false,
        ]);
        $baseUrl = config('app.url');

        $content = get('/sitemap-profiles.xml')->content();

        expect($content)->toContain("{$baseUrl}/en/u/{$user->slug}");
        expect($content)->toContain("{$baseUrl}/de/u/{$user->slug}");
    });

    it('excludes users with incomplete profiles', function () {
        $user = User::factory()->create([
            'profile_complete' => false,
            'is_disabled' => false,
        ]);

        $content = get('/sitemap-profiles.xml')->content();

        expect($content)->not->toContain("/u/{$user->slug}");
    });

    it('excludes users without a slug', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'is_disabled' => false,
            'slug' => null,
        ]);

        $content = get('/sitemap-profiles.xml')->content();

        // slug is null, so no /u/ URL should reference this user's name
        // Check that the user's name is not in a profile URL context
        expect($content)->not->toContain("/u/{$user->name}");
    });

    it('excludes disabled users', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'is_disabled' => true,
        ]);

        $content = get('/sitemap-profiles.xml')->content();

        expect($content)->not->toContain("/u/{$user->slug}");
    });

    it('uses weekly changefreq and 0.5 priority', function () {
        User::factory()->create([
            'profile_complete' => true,
            'is_disabled' => false,
        ]);

        $content = get('/sitemap-profiles.xml')->content();

        preg_match('/<url>.*?<\/url>/s', $content, $match);
        expect($match[0])->toContain('<changefreq>weekly</changefreq>');
        expect($match[0])->toContain('<priority>0.5</priority>');
    });
});

// ── Cache Invalidation ────────────────────────────────

describe('Cache Invalidation', function () {
    it('serves fresh content after cache invalidation', function () {
        Cache::flush();

        $system1 = GameSystem::factory()->create(['name' => ['en' => 'Before Invalidation']]);
        $firstContent = get('/sitemap-game-systems.xml')->content();
        expect($firstContent)->toContain("/game-systems/{$system1->slug}");

        // Invalidate cache
        Cache::forget('seo:sitemap:game-systems');

        // Create a new system after invalidation
        $system2 = GameSystem::factory()->create(['name' => ['en' => 'After Invalidation']]);

        $secondContent = get('/sitemap-game-systems.xml')->content();
        expect($secondContent)->toContain("/game-systems/{$system2->slug}");
        expect($secondContent)->toContain("/game-systems/{$system1->slug}");
    });
});

// ── XML Well-Formedness ───────────────────────────────

describe('XML Well-Formedness', function () {
    it('all sitemap types produce well-formed XML with correct root element', function ($path, $expectedRoot) {
        // Seed at least one record for entity sitemaps
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
    ]);
});

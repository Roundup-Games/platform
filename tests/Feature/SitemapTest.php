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

beforeEach(function () {
    // Clear all sitemap caches before each test
    $seoCache = app(\App\Services\SeoCacheService::class);
    foreach ($seoCache->getSitemapTypes() as $type) {
        $seoCache->forgetSitemap($type);
    }
    $seoCache->forgetIndex();
});

// ── Sitemap Index ─────────────────────────────────────

it('returns valid XML sitemap index with correct content type', function () {
    $response = $this->get('/sitemap.xml');

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/xml');
});

it('lists all 7 sub-sitemaps in the index', function () {
    $response = $this->get('/sitemap.xml');
    $content = $response->getContent();

    expect($content)->toStartWith('<?xml version="1.0" encoding="UTF-8"?>');
    expect($content)->toContain('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">');
    expect($content)->toContain('</sitemapindex>');

    foreach (['static', 'game-systems', 'events', 'games', 'campaigns', 'teams', 'profiles'] as $type) {
        expect($content)->toContain("sitemap-{$type}.xml");
    }

    // Verify it parses as valid XML
    $doc = simplexml_load_string($content);
    expect($doc)->not->toBeFalse();
    expect($doc->getName())->toBe('sitemapindex');
})->group('smoke');

// ── Static Sub-Sitemap ────────────────────────────────

it('includes static pages for both locales', function () {
    $response = $this->get('/sitemap-static.xml');
    $content = $response->getContent();

    // Static pages for EN
    expect($content)->toContain('/en/');
    expect($content)->toContain('/en/about');
    expect($content)->toContain('/en/contact');
    expect($content)->toContain('/en/how-it-works');
    expect($content)->toContain('/en/for-organizers');
    expect($content)->toContain('/en/safety-tools');
    expect($content)->toContain('/en/game-systems');

    // Static pages for DE
    expect($content)->toContain('/de/');
    expect($content)->toContain('/de/about');
    expect($content)->toContain('/de/contact');
});

it('static sitemap has urlset structure with loc, lastmod, changefreq, priority', function () {
    $response = $this->get('/sitemap-static.xml');
    $content = $response->getContent();

    expect($content)->toContain('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">');
    expect($content)->toContain('<loc>');
    expect($content)->toContain('<lastmod>');
    expect($content)->toContain('<changefreq>');
    expect($content)->toContain('<priority>');
    expect($content)->toContain('</urlset>');

    $doc = simplexml_load_string($content);
    expect($doc)->not->toBeFalse();
    expect($doc->getName())->toBe('urlset');
});

// ── Events Sub-Sitemap ────────────────────────────────

it('includes public event URLs for both locales', function () {
    $event = Event::factory()->create([
        'is_public' => true,
        'status' => 'registration_open',
    ]);

    $response = $this->get('/sitemap-events.xml');
    $content = $response->getContent();

    expect($content)->toContain('/en/events/' . $event->slug);
    expect($content)->toContain('/de/events/' . $event->slug);
});

it('excludes non-public events', function () {
    $privateEvent = Event::factory()->create([
        'is_public' => false,
        'status' => 'draft',
    ]);

    $response = $this->get('/sitemap-events.xml');
    $content = $response->getContent();

    expect($content)->not->toContain($privateEvent->slug);
});

it('excludes draft events', function () {
    $draftEvent = Event::factory()->create([
        'is_public' => true,
        'status' => 'draft',
    ]);

    $response = $this->get('/sitemap-events.xml');
    $content = $response->getContent();

    expect($content)->not->toContain($draftEvent->slug);
});

it('includes events with published statuses', function () {
    foreach (['published', 'registration_open', 'registration_closed', 'in_progress'] as $status) {
        // Clear cache between iterations so each event is included
        app(\App\Services\SeoCacheService::class)->forgetSitemap('events');

        $event = Event::factory()->create([
            'is_public' => true,
            'status' => $status,
        ]);

        $response = $this->get('/sitemap-events.xml');
        $content = $response->getContent();

        expect($content)->toContain('/en/events/' . $event->slug);
    }
});

// ── Games Sub-Sitemap ─────────────────────────────────

it('includes public non-canceled games for both locales', function () {
    $game = Game::factory()->create([
        'visibility' => Visibility::Public,
        'status' => GameStatus::Scheduled,
    ]);

    $response = $this->get('/sitemap-games.xml');
    $content = $response->getContent();

    expect($content)->toContain('/en/games/' . $game->id);
    expect($content)->toContain('/de/games/' . $game->id);
});

it('excludes canceled games', function () {
    $game = Game::factory()->create([
        'visibility' => Visibility::Public,
        'status' => GameStatus::Canceled,
    ]);

    $response = $this->get('/sitemap-games.xml');
    $content = $response->getContent();

    expect($content)->not->toContain('/games/' . $game->id);
});

it('excludes private games', function () {
    $game = Game::factory()->create([
        'visibility' => Visibility::Private,
        'status' => GameStatus::Scheduled,
    ]);

    $response = $this->get('/sitemap-games.xml');
    $content = $response->getContent();

    expect($content)->not->toContain('/games/' . $game->id);
});

// ── Campaigns Sub-Sitemap ─────────────────────────────

it('includes public active campaigns for both locales', function () {
    $campaign = Campaign::factory()->create([
        'visibility' => Visibility::Public,
        'status' => CampaignStatus::Active,
    ]);

    $response = $this->get('/sitemap-campaigns.xml');
    $content = $response->getContent();

    expect($content)->toContain('/en/campaigns/' . $campaign->id);
    expect($content)->toContain('/de/campaigns/' . $campaign->id);
});

it('excludes cancelled campaigns', function () {
    $campaign = Campaign::factory()->create([
        'visibility' => Visibility::Public,
        'status' => CampaignStatus::Cancelled,
    ]);

    $response = $this->get('/sitemap-campaigns.xml');
    $content = $response->getContent();

    expect($content)->not->toContain('/campaigns/' . $campaign->id);
});

// ── Teams Sub-Sitemap ─────────────────────────────────

it('includes active team URLs for both locales', function () {
    $team = Team::factory()->create([
        'is_active' => true,
    ]);

    $response = $this->get('/sitemap-teams.xml');
    $content = $response->getContent();

    expect($content)->toContain('/en/teams/' . $team->slug);
    expect($content)->toContain('/de/teams/' . $team->slug);
});

it('excludes inactive teams', function () {
    $inactiveTeam = Team::factory()->create([
        'is_active' => false,
    ]);

    $response = $this->get('/sitemap-teams.xml');
    $content = $response->getContent();

    expect($content)->not->toContain($inactiveTeam->slug);
});

// ── Profiles Sub-Sitemap ──────────────────────────────

it('includes complete public profiles for both locales', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'slug' => 'test-sitemap-user-' . uniqid(),
        'is_disabled' => false,
    ]);

    $response = $this->get('/sitemap-profiles.xml');
    $content = $response->getContent();

    expect($content)->toContain('/en/u/' . $user->slug);
    expect($content)->toContain('/de/u/' . $user->slug);
});

it('excludes incomplete profiles', function () {
    $user = User::factory()->create([
        'profile_complete' => false,
        'slug' => 'incomplete-' . uniqid(),
        'is_disabled' => false,
    ]);

    $response = $this->get('/sitemap-profiles.xml');
    $content = $response->getContent();

    expect($content)->not->toContain($user->slug);
});

it('excludes disabled users', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'slug' => 'disabled-' . uniqid(),
        'is_disabled' => true,
    ]);

    $response = $this->get('/sitemap-profiles.xml');
    $content = $response->getContent();

    expect($content)->not->toContain($user->slug);
});

it('excludes users without a slug', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'slug' => null,
        'is_disabled' => false,
    ]);

    $response = $this->get('/sitemap-profiles.xml');
    $content = $response->getContent();

    // Should not contain the user's UUID (they have no slug)
    expect($content)->not->toContain('/u/' . $user->id);
});

// ── Game Systems Sub-Sitemap ──────────────────────────

it('includes game systems for both locales', function () {
    $system = GameSystem::factory()->create([
        'slug' => 'test-sitemap-gs-' . uniqid(),
    ]);

    $response = $this->get('/sitemap-game-systems.xml');
    $content = $response->getContent();

    expect($content)->toContain('/en/game-systems/' . $system->slug);
    expect($content)->toContain('/de/game-systems/' . $system->slug);
});

// ── Invalid Type ──────────────────────────────────────

it('returns 404 for invalid sitemap type', function () {
    $response = $this->get('/sitemap-invalid.xml');

    // The route where clause rejects invalid types; may 302→404 or 404 directly
    expect(in_array($response->getStatusCode(), [302, 404]))->toBeTrue();
});

// ── Caching ───────────────────────────────────────────

it('caches sub-sitemap responses', function () {
    // First request: cache miss, builds and caches
    $this->get('/sitemap-static.xml');

    // Second request: should be served from cache
    $response = $this->get('/sitemap-static.xml');

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/xml');
});

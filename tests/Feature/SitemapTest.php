<?php

use App\Models\Event;
use App\Models\Team;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::forget('sitemap');
});

// smoke: sitemap returns valid XML
it('returns valid XML with correct content type', function () {
    $response = $this->get('/sitemap.xml');

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/xml');
})->group('smoke');

it('includes static pages for both locales', function () {
    $response = $this->get('/sitemap.xml');

    $content = $response->getContent();

    // Static pages for EN
    expect($content)->toContain('/en/');
    expect($content)->toContain('/en/about');
    expect($content)->toContain('/en/contact');

    // Static pages for DE
    expect($content)->toContain('/de/');
    expect($content)->toContain('/de/about');
    expect($content)->toContain('/de/contact');
});

it('includes public event URLs for both locales', function () {
    $event = Event::factory()->create([
        'is_public' => true,
        'status' => 'registration_open',
    ]);

    $response = $this->get('/sitemap.xml');
    $content = $response->getContent();

    expect($content)->toContain('/en/events/' . $event->slug);
    expect($content)->toContain('/de/events/' . $event->slug);
});

it('excludes non-public events', function () {
    $privateEvent = Event::factory()->create([
        'is_public' => false,
        'status' => 'draft',
    ]);

    $response = $this->get('/sitemap.xml');
    $content = $response->getContent();

    expect($content)->not->toContain($privateEvent->slug);
});

it('includes active team URLs for both locales', function () {
    $team = Team::factory()->create([
        'is_active' => true,
    ]);

    $response = $this->get('/sitemap.xml');
    $content = $response->getContent();

    expect($content)->toContain('/en/teams/' . $team->slug);
    expect($content)->toContain('/de/teams/' . $team->slug);
});

it('excludes inactive teams', function () {
    $inactiveTeam = Team::factory()->create([
        'is_active' => false,
    ]);

    $response = $this->get('/sitemap.xml');
    $content = $response->getContent();

    expect($content)->not->toContain($inactiveTeam->slug);
});

it('returns well-formed XML', function () {
    $response = $this->get('/sitemap.xml');
    $content = $response->getContent();

    expect($content)->toStartWith('<?xml version="1.0" encoding="UTF-8"?>');
    expect($content)->toContain('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">');
    expect($content)->toContain('</urlset>');

    // Verify it parses as valid XML
    $doc = simplexml_load_string($content);
    expect($doc)->not->toBeFalse();
    expect($doc->getName())->toBe('urlset');
});

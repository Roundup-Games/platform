<?php

use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Team;
use App\Models\User;
use App\Services\SeoCacheService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    // Use the array cache driver for predictable test behavior
    Cache::flush();
});

// ── GameSystem observer ────────────────────────────────

it('clears game-systems sitemap cache when a game system is saved', function () {
    $service = app(SeoCacheService::class);
    $service->setSitemap('game-systems', '<test>xml</test>');
    $service->setIndex('<sitemapindex>test</sitemapindex>');

    $system = GameSystem::factory()->create();

    expect(Cache::get('seo:sitemap:game-systems'))->toBeNull();
    expect(Cache::get('seo:sitemap:index'))->toBeNull();
});

it('clears game-systems sitemap cache when a game system is updated', function () {
    $system = GameSystem::factory()->create();
    $service = app(SeoCacheService::class);
    $service->setSitemap('game-systems', '<test>xml</test>');
    $service->setIndex('<sitemapindex>test</sitemapindex>');

    $system->update(['name' => 'Updated Name']);

    expect(Cache::get('seo:sitemap:game-systems'))->toBeNull();
    expect(Cache::get('seo:sitemap:index'))->toBeNull();
});

it('clears game-systems sitemap cache when a game system is deleted', function () {
    $system = GameSystem::factory()->create();
    $service = app(SeoCacheService::class);
    $service->setSitemap('game-systems', '<test>xml</test>');
    $service->setIndex('<sitemapindex>test</sitemapindex>');

    $system->delete();

    expect(Cache::get('seo:sitemap:game-systems'))->toBeNull();
    expect(Cache::get('seo:sitemap:index'))->toBeNull();
});

// ── Event observer ─────────────────────────────────────

it('clears events sitemap cache when an event is saved', function () {
    $service = app(SeoCacheService::class);
    $service->setSitemap('events', '<test>xml</test>');
    $service->setIndex('<sitemapindex>test</sitemapindex>');

    $event = Event::factory()->create();

    expect(Cache::get('seo:sitemap:events'))->toBeNull();
    expect(Cache::get('seo:sitemap:index'))->toBeNull();
});

it('clears events sitemap cache when an event is deleted', function () {
    $event = Event::factory()->create();
    $service = app(SeoCacheService::class);
    $service->setSitemap('events', '<test>xml</test>');
    $service->setIndex('<sitemapindex>test</sitemapindex>');

    $event->delete();

    expect(Cache::get('seo:sitemap:events'))->toBeNull();
});

// ── Team observer ──────────────────────────────────────

it('clears teams sitemap cache when a team is saved', function () {
    $service = app(SeoCacheService::class);
    $service->setSitemap('teams', '<test>xml</test>');
    $service->setIndex('<sitemapindex>test</sitemapindex>');

    $team = Team::factory()->create();

    expect(Cache::get('seo:sitemap:teams'))->toBeNull();
    expect(Cache::get('seo:sitemap:index'))->toBeNull();
});

it('clears teams sitemap cache when a team is deleted', function () {
    $team = Team::factory()->create();
    $service = app(SeoCacheService::class);
    $service->setSitemap('teams', '<test>xml</test>');

    $team->delete();

    expect(Cache::get('seo:sitemap:teams'))->toBeNull();
});

// ── User observer (selective invalidation) ─────────────

it('does not clear profiles cache when a new user is created (not yet in sitemap)', function () {
    $service = app(SeoCacheService::class);
    $service->setSitemap('profiles', '<test>xml</test>');
    $service->setIndex('<sitemapindex>test</sitemapindex>');

    User::factory()->create();

    // New users don't appear in the sitemap (profile_complete is false by default),
    // so no invalidation needed. Cache remains intact.
    expect(Cache::get('seo:sitemap:profiles'))->not->toBeNull();
    expect(Cache::get('seo:sitemap:index'))->not->toBeNull();
});

it('clears profiles cache when user profile_complete changes to true', function () {
    $user = User::factory()->create();
    $service = app(SeoCacheService::class);
    $service->setSitemap('profiles', '<test>xml</test>');
    $service->setIndex('<sitemapindex>test</sitemapindex>');

    // Completing profile makes user appear in sitemap → invalidate
    $user->update(['profile_complete' => true]);

    expect(Cache::get('seo:sitemap:profiles'))->toBeNull();
    expect(Cache::get('seo:sitemap:index'))->toBeNull();
});

it('clears profiles sitemap cache when user slug changes', function () {
    $user = User::factory()->create(['slug' => 'old-slug']);
    $service = app(SeoCacheService::class);
    $service->setSitemap('profiles', '<test>xml</test>');

    $user->update(['slug' => 'new-slug']);

    expect(Cache::get('seo:sitemap:profiles'))->toBeNull();
});

it('clears profiles sitemap cache when user profile_complete changes', function () {
    $user = User::factory()->create(['profile_complete' => false]);
    $service = app(SeoCacheService::class);
    $service->setSitemap('profiles', '<test>xml</test>');

    $user->update(['profile_complete' => true]);

    expect(Cache::get('seo:sitemap:profiles'))->toBeNull();
});

it('clears profiles sitemap cache when user is_disabled changes', function () {
    $user = User::factory()->create(['is_disabled' => false]);
    $service = app(SeoCacheService::class);
    $service->setSitemap('profiles', '<test>xml</test>');

    $user->update(['is_disabled' => true]);

    expect(Cache::get('seo:sitemap:profiles'))->toBeNull();
});

it('does NOT clear profiles cache on unrelated user update', function () {
    $user = User::factory()->create();
    $service = app(SeoCacheService::class);
    $service->setSitemap('profiles', '<test>xml</test>');

    $user->update(['name' => 'New Name']);

    expect(Cache::get('seo:sitemap:profiles'))->not->toBeNull();
});

it('clears profiles sitemap cache when a user is deleted', function () {
    $user = User::factory()->create();
    $service = app(SeoCacheService::class);
    $service->setSitemap('profiles', '<test>xml</test>');

    $user->delete();

    expect(Cache::get('seo:sitemap:profiles'))->toBeNull();
});

// ── Does not affect unrelated caches ───────────────────

it('only clears the relevant sitemap type, not other types', function () {
    $service = app(SeoCacheService::class);
    $service->setSitemap('game-systems', '<gs>xml</gs>');
    $service->setSitemap('teams', '<teams>xml</teams>');
    $service->setIndex('<sitemapindex>test</sitemapindex>');

    GameSystem::factory()->create();

    // game-systems cache cleared
    expect(Cache::get('seo:sitemap:game-systems'))->toBeNull();
    // teams cache should remain
    expect(Cache::get('seo:sitemap:teams'))->not->toBeNull();
    // index always cleared
    expect(Cache::get('seo:sitemap:index'))->toBeNull();
});

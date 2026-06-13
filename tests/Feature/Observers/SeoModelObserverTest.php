<?php

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

// ── Simple model observers (create/update/delete triggers cache clear) ──

it('clears sitemap cache when a model is created', function ($sitemapType, $factory) {
    $service = app(SeoCacheService::class);
    $service->setSitemap($sitemapType, '<test>xml</test>');
    $service->setIndex('<sitemapindex>test</sitemapindex>');

    $factory();

    expect(Cache::get("seo:sitemap:{$sitemapType}"))->toBeNull();
    expect(Cache::get('seo:sitemap:index'))->toBeNull();
})->with([
    'GameSystem' => ['game-systems', fn () => GameSystem::factory()->create()],
    'Event' => ['events',      fn () => Event::factory()->create()],
    'Team' => ['teams',        fn () => Team::factory()->create()],
]);

it('clears sitemap cache when a model is updated', function ($sitemapType, $createAndModify) {
    $service = app(SeoCacheService::class);
    $service->setSitemap($sitemapType, '<test>xml</test>');
    $service->setIndex('<sitemapindex>test</sitemapindex>');

    $createAndModify();

    expect(Cache::get("seo:sitemap:{$sitemapType}"))->toBeNull();
    expect(Cache::get('seo:sitemap:index'))->toBeNull();
})->with([
    'GameSystem' => ['game-systems', fn () => tap(GameSystem::factory()->create())->update(['name' => 'Updated'])],
]);

it('clears sitemap cache when a model is deleted', function ($sitemapType, $createAndDelete) {
    $service = app(SeoCacheService::class);
    $service->setSitemap($sitemapType, '<test>xml</test>');
    $service->setIndex('<sitemapindex>test</sitemapindex>');

    $createAndDelete();

    expect(Cache::get("seo:sitemap:{$sitemapType}"))->toBeNull();
})->with([
    'GameSystem' => ['game-systems', fn () => tap(GameSystem::factory()->create())->delete()],
    'Event' => ['events',      fn () => tap(Event::factory()->create())->delete()],
    'Team' => ['teams',        fn () => tap(Team::factory()->create())->delete()],
]);

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

<?php

use App\Models\GameSystem;

use function Pest\Laravel\get;

//
// BGG duplicate consolidation rendering (SEO audit 2026-10-15):
// a row marked as a duplicate keeps serving its own page, but
// canonicalizes to the primary row's per-locale URLs and leaves
// the sitemap, so one URL per game accumulates search signals.
//

it('canonicalizes a duplicate detail page to the primary row per locale', function () {
    $primary = GameSystem::factory()->create([
        'name' => ['en' => 'Puerto Rico'],
        'slug' => 'puerto-rico',
    ]);
    GameSystem::factory()->create([
        'name' => ['en' => 'Puerto Rico'],
        'slug' => 'puerto-rico-165332',
        'canonical_game_system_id' => $primary->id,
    ]);

    $response = get('/en/game-systems/puerto-rico-165332');

    $response->assertOk();
    $html = $response->content();

    expect($html)
        ->toContain('<link rel="canonical" href="'.config('app.url').'/en/game-systems/puerto-rico">')
        ->toContain('hreflang="en" href="'.config('app.url').'/en/game-systems/puerto-rico"')
        ->toContain('hreflang="de" href="'.config('app.url').'/de/game-systems/puerto-rico"')
        ->toContain('hreflang="x-default" href="'.config('app.url').'/en/game-systems/puerto-rico"')
        ->not->toContain('/en/game-systems/puerto-rico-165332">');
});

it('keeps the canonical row self-canonicalizing', function () {
    $primary = GameSystem::factory()->create([
        'name' => ['en' => 'Puerto Rico'],
        'slug' => 'puerto-rico',
    ]);

    $response = get('/de/game-systems/puerto-rico');

    $response->assertOk();
    $response->content();

    expect($response->content())
        ->toContain('<link rel="canonical" href="'.config('app.url').'/de/game-systems/puerto-rico">');
});

it('excludes duplicates from the game-systems sitemap', function () {
    $primary = GameSystem::factory()->create([
        'name' => ['en' => 'Puerto Rico'],
        'slug' => 'puerto-rico',
    ]);
    GameSystem::factory()->create([
        'name' => ['en' => 'Puerto Rico'],
        'slug' => 'puerto-rico-165332',
        'canonical_game_system_id' => $primary->id,
    ]);

    $content = get('/sitemap-game-systems.xml')->content();

    expect($content)
        ->toContain('/game-systems/puerto-rico<')
        ->not->toContain('/game-systems/puerto-rico-165332');
});

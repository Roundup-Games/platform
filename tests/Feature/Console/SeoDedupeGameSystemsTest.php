<?php

use App\Models\GameSystem;

use function Pest\Laravel\artisan;

//
// seo:dedupe-game-systems reconciles BGG duplicate catalog rows
// (SEO audit 2026-10-15). Semantics under test:
//   1. Dry-run (default) reports without writing.
//   2. --execute assigns every same-name non-primary row to the group
//      primary — the base-slug holder wins.
//   3. Existing assignments to the computed primary are left alone
//      (idempotent); assignments to a stale target are re-pointed.
//

it('reports duplicate groups without writing in dry-run mode', function () {
    GameSystem::factory()->create(['name' => ['en' => 'Puerto Rico'], 'slug' => 'puerto-rico']);
    GameSystem::factory()->create(['name' => ['en' => 'Puerto Rico'], 'slug' => 'puerto-rico-165332']);

    artisan('seo:dedupe-game-systems')->assertSuccessful();

    expect(GameSystem::where('slug', 'puerto-rico-165332')->first()->canonical_game_system_id)->toBeNull();
});

it('marks suffixed-slug rows as duplicates of the base-slug holder on execute', function () {
    $primary = GameSystem::factory()->create(['name' => ['en' => 'Puerto Rico'], 'slug' => 'puerto-rico']);
    GameSystem::factory()->create(['name' => ['en' => 'Puerto Rico'], 'slug' => 'puerto-rico-165332']);
    GameSystem::factory()->create(['name' => ['en' => 'Puerto Rico'], 'slug' => 'puerto-rico-108687']);

    artisan('seo:dedupe-game-systems', ['--execute' => true])->assertSuccessful();

    expect(GameSystem::where('slug', 'puerto-rico-165332')->first()->canonical_game_system_id)->toBe($primary->id)
        ->and(GameSystem::where('slug', 'puerto-rico-108687')->first()->canonical_game_system_id)->toBe($primary->id)
        ->and($primary->fresh()->canonical_game_system_id)->toBeNull();
});

it('is idempotent when assignments already match the computed primary', function () {
    $primary = GameSystem::factory()->create(['name' => ['en' => 'Puerto Rico'], 'slug' => 'puerto-rico']);
    GameSystem::factory()->create([
        'name' => ['en' => 'Puerto Rico'],
        'slug' => 'puerto-rico-165332',
        'canonical_game_system_id' => $primary->id,
    ]);

    artisan('seo:dedupe-game-systems', ['--execute' => true])->assertSuccessful();

    expect(GameSystem::where('slug', 'puerto-rico-165332')->first()->canonical_game_system_id)->toBe($primary->id);
});

it('re-points a stale assignment to the computed primary', function () {
    $primary = GameSystem::factory()->create(['name' => ['en' => 'Puerto Rico'], 'slug' => 'puerto-rico']);
    $stale = GameSystem::factory()->create(['name' => ['en' => 'Puerto Rico'], 'slug' => 'puerto-rico-999999']);
    GameSystem::factory()->create([
        'name' => ['en' => 'Puerto Rico'],
        'slug' => 'puerto-rico-165332',
        'canonical_game_system_id' => $stale->id,
    ]);

    artisan('seo:dedupe-game-systems', ['--execute' => true])->assertSuccessful();

    expect(GameSystem::where('slug', 'puerto-rico-165332')->first()->canonical_game_system_id)->toBe($primary->id)
        ->and($stale->fresh()->canonical_game_system_id)->toBe($primary->id);
});

it('ignores unique names entirely', function () {
    GameSystem::factory()->create(['name' => ['en' => 'Gloomhaven'], 'slug' => 'gloomhaven']);

    artisan('seo:dedupe-game-systems', ['--execute' => true])->assertSuccessful();

    expect(GameSystem::where('slug', 'gloomhaven')->first()->canonical_game_system_id)->toBeNull();
});

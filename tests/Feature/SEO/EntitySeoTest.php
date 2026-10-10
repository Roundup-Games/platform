<?php

use App\Filament\Resources\GameResource\Pages\EditGame;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

//
// HasEntitySeo (D172): per-locale curated SEO overrides layered over the
// model's generated getDynamicSEOData() output. The platform standard for
// every SEO-enabled entity — city hubs implement it through CitySummary
// (reference), these cover the seven HasEntitySeo models.
//
// Semantics under test (the changed decisions):
//   1. entitySeoData(locale) returns the generator's base metadata
//      (title, description, robots, schema) when no override is curated.
//   2. A curated override for the active locale wins; other locales keep
//      the generated output — one locale's curated text never leaks.
//   3. Whitespace-only values count as absent.
//   4. Render path: seo()->for(entitySeoData()) — the venue detail page
//      serves the curated title/description per locale.
//   5. The Filament form writes the translatable columns per locale.
beforeEach(function () {
    if (! Schema::hasColumn('games', 'seo_title')) {
        $this->markTestSkipped('entity SEO columns not migrated');
    }
});

it('returns the generated base when nothing is curated', function () {
    $team = Team::factory()->create(['name' => 'Berlin Board Nights', 'is_active' => true]);

    $seo = $team->entitySeoData('en');

    expect($seo->title)->toBe('Berlin Board Nights')
        ->and($seo->robots)->toBeString();
});

it('layers the curated override for the active locale without leaking across locales', function () {
    $team = Team::factory()->create(['name' => 'English Name', 'is_active' => true]);
    $team->setTranslations('seo_title', ['en' => 'Curated EN title']);
    $team->setTranslations('seo_description', ['en' => '  ', 'de' => 'Kuratierte DE-Beschreibung.']); // whitespace EN = absent
    $team->save();

    $en = $team->entitySeoData('en');
    expect($en->title)->toBe('Curated EN title')
        ->and($en->description)->toBe($team->getDynamicSEOData()->description); // EN description falls back

    $de = $team->entitySeoData('de');
    expect($de->title)->toBe('English Name') // generated scalar name, never the EN override
        ->and($de->description)->toBe('Kuratierte DE-Beschreibung.');
});

it('works on models whose translatable surface is only the SEO columns (User, Location)', function () {
    $user = User::factory()->create(['name' => 'Ada GM']);
    $user->setTranslation('seo_title', 'en', 'Ada GM — curated profile title');
    $user->save();

    expect($user->entitySeoData('en')->title)->toBe('Ada GM — curated profile title')
        ->and($user->entitySeoData('de')->title)->toBe($user->getDynamicSEOData()->title);

    $location = Location::factory()->verifiedVenue()->create([
        'name' => 'The Dice Tower',
        'city' => 'Berlin',
        'slug' => 'the-dice-tower',
        'latitude' => 52.52,
        'longitude' => 13.405,
    ]);
    $location->setTranslation('seo_title', 'de', 'Würfelturm Berlin — kuratiert');
    $location->save();

    expect($location->entitySeoData('de')->title)->toBe('Würfelturm Berlin — kuratiert')
        ->and($location->entitySeoData('en')->title)->toBe($location->getDynamicSEOData()->title);
});

it('serves curated per-locale SEO on the venue detail page with generated fallback', function () {
    $venue = Location::factory()->verifiedVenue()->create([
        'name' => 'Yorckschlösschen',
        'city' => 'Berlin',
        'latitude' => 52.52,
        'longitude' => 13.405,
    ]);
    $venue->setTranslations('seo_title', ['en' => 'Curated venue SEO title']);
    $venue->save();

    get("/en/venue/{$venue->slug}")
        ->assertOk()
        ->assertSee('Curated venue SEO title', false);

    // German locale: generated metadata, never the English override.
    $response = get("/de/venue/{$venue->slug}");
    $response->assertOk();
    expect($response->content())->not->toContain('Curated venue SEO title');
});

it('saves SEO overrides per locale through the admin form (Game)', function () {
    seedRoles();
    $admin = User::factory()->create();
    $admin->assignRole('Platform Admin');
    $system = GameSystem::factory()->create();
    $game = Game::factory()->create(['game_system_id' => $system->id]);

    actingAs($admin);
    Filament\Facades\Filament::setCurrentPanel('admin');

    Livewire\Livewire::test(EditGame::class, ['record' => $game->getKey()])
        ->fillForm(['name' => 'Test Game', 'description' => 'A test game.', 'seo_title' => 'Curated game SEO EN'])
        ->set('activeLocale', 'de')
        ->fillForm(['name' => 'Testspiel', 'description' => 'Ein Testspiel.', 'seo_title' => 'Kuratiertes Spiel DE'])
        ->call('save')
        ->assertHasNoErrors();

    $game->refresh();

    expect($game->getTranslation('seo_title', 'en'))->toBe('Curated game SEO EN')
        ->and($game->getTranslation('seo_title', 'de'))->toBe('Kuratiertes Spiel DE')
        ->and($game->entitySeoData('de')->title)->toBe('Kuratiertes Spiel DE');
});

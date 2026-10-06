<?php

use App\Filament\Resources\CityResource;
use App\Filament\Resources\CityResource\Pages\CreateCity;
use App\Filament\Resources\CityResource\Pages\EditCity;
use App\Models\City;
use App\Models\Location;
use App\Models\User;
use App\Services\CityDirectoryService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\get;

//
// CityResource (M062/62-04-T04): the admin curation surface for city hubs.
//
// The form's contract is "derived, never typed": the city is chosen from
// real Location city values, the slug derives from it via Str::slug
// (München → munchen — ASCII-folds, never "oe"), and collisions surface as
// admin-visible validation errors instead of DB constraint fatals. The
// translatable intro follows the LaraZeus pattern proven by GameSystemResource,
// and every City write path flushes the affected summary cache via
// CityHubCacheObserver — the same observer the smoke-tested Location/Game/
// Event saves ride.
//
// geohash_4 is recomputed from lat/lng on save, so locations are seeded via
// coordinates and geohash_4 is never set directly (PG trigger, known gotcha).
// Fixed DACH coordinates pin cluster regions (CityHubPageTest convention):
//   Berlin  52.5200/13.4050 -> u33 region
//   Hamburg 53.5511/9.9937  -> u1x region
//   Munich  48.1351/11.5820 -> u28 region
//
// Helpers carry a cityResource prefix: CityHubPageTest and the observer/
// curation tests define the same shapes under other prefixes — same-named
// globals in one Pest process fatal ("cannot redeclare function").
function cityResourceLocation(string $city, float $lat, float $lng, array $overrides = []): Location
{
    return Location::factory()->create(array_merge([
        'city' => $city,
        'country' => 'DEU',
        'latitude' => $lat,
        'longitude' => $lng,
    ], $overrides));
}

beforeEach(function () {
    seedRoles();

    setPermissionsTeamId(null);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('Platform Admin');
    $this->platformAdmin->unsetRelations();

    $this->regularUser = User::factory()->create();

    Filament::setCurrentPanel('admin');

    // City resolutions (positive AND negative) are cached; flush per test
    // so the observer-flush assertions observe only their own writes.
    Cache::flush();
});

// ── Access ────────────────────────────────────────────

describe('CityResource — access', function () {
    test('renders the List, Create, and Edit pages as Platform Admin', function () {
        $city = City::factory()->create(['slug' => 'berlin', 'city' => 'Berlin']);

        actingAs($this->platformAdmin);

        get('/admin/cities')->assertSuccessful();
        get('/admin/cities/create')->assertSuccessful();
        get("/admin/cities/{$city->getKey()}/edit")->assertSuccessful();
    });

    test('denies regular users access to the admin surface', function () {
        actingAs($this->regularUser);

        get('/admin/cities')->assertForbidden();
        get('/admin/cities/create')->assertForbidden();
    });
});

// ── Derived slug ──────────────────────────────────────

describe('CityResource — derived slug', function () {
    test('creates a city with the slug derived from the selected location city (München → munchen)', function () {
        cityResourceLocation('München', 48.1351, 11.5820);

        actingAs($this->platformAdmin);

        Livewire\Livewire::test(CreateCity::class)
            ->fillForm(['city' => 'München'])
            ->call('create')
            ->assertHasNoErrors();

        assertDatabaseHas('cities', [
            'city' => 'München',
            'slug' => 'munchen',
        ]);
    });

    test('rejects a duplicate derived slug with a validation error instead of a constraint fatal', function () {
        cityResourceLocation('München', 48.1351, 11.5820);
        City::factory()->create(['slug' => 'munchen', 'city' => 'München']);

        actingAs($this->platformAdmin);

        Livewire\Livewire::test(CreateCity::class)
            ->fillForm(['city' => 'München'])
            ->call('create')
            ->assertHasFormErrors(['city']);

        expect(City::query()->where('slug', 'munchen')->count())->toBe(1);
    });

    test('rejects editing a city onto another curated slug, ignoring its own row otherwise', function () {
        cityResourceLocation('Berlin', 52.5200, 13.4050);
        cityResourceLocation('Hamburg', 53.5511, 9.9937);
        $berlin = City::factory()->create(['slug' => 'berlin', 'city' => 'Berlin']);
        City::factory()->create(['slug' => 'hamburg', 'city' => 'Hamburg']);

        actingAs($this->platformAdmin);

        // Re-selecting the row's own city keeps its own slug — no collision.
        Livewire\Livewire::test(EditCity::class, ['record' => $berlin->getKey()])
            ->fillForm(['city' => 'Berlin'])
            ->call('save')
            ->assertHasNoErrors();

        // Re-pointing at Hamburg derives the already-curated 'hamburg' slug.
        Livewire\Livewire::test(EditCity::class, ['record' => $berlin->getKey()])
            ->fillForm(['city' => 'Hamburg'])
            ->call('save')
            ->assertHasFormErrors(['city']);

        expect($berlin->fresh()->slug)->toBe('berlin');
    });
});

// ── Region prefix options ─────────────────────────────

describe('CityResource — region prefix candidates', function () {
    test('groups a city\'s locations by three-char geohash prefix with location counts', function () {
        // Neustadt in two regions: 2 locations around Berlin (u33), 1 around Munich (u28).
        cityResourceLocation('Neustadt', 52.5200, 13.4050);
        cityResourceLocation('Neustadt', 52.5200, 13.4050);
        cityResourceLocation('Neustadt', 48.1351, 11.5820);

        expect(CityResource::regionPrefixOptions('Neustadt'))->toBe([
            'u28' => 'u28 — 1 location',
            'u33' => 'u33 — 2 locations',
        ]);
    });

    test('offers no candidates for an unknown or blank city', function () {
        expect(CityResource::regionPrefixOptions('Nowhere'))->toBe([])
            ->and(CityResource::regionPrefixOptions(null))->toBe([])
            ->and(CityResource::regionPrefixOptions(''))->toBe([]);
    });
});

// ── Translatable intro ────────────────────────────────

describe('CityResource — translatable intro', function () {
    test('saves the intro per locale across an active-locale switch', function () {
        $city = City::factory()->create(['slug' => 'berlin', 'city' => 'Berlin']);

        actingAs($this->platformAdmin);

        Livewire\Livewire::test(EditCity::class, ['record' => $city->getKey()])
            ->fillForm(['intro' => 'Board game nights in Berlin.'])
            ->set('activeLocale', 'de')
            ->fillForm(['intro' => 'Brettspielabende in Berlin.'])
            ->call('save')
            ->assertHasNoErrors();

        $city->refresh();

        expect($city->getTranslation('intro', 'en'))->toBe('Board game nights in Berlin.')
            ->and($city->getTranslation('intro', 'de'))->toBe('Brettspielabende in Berlin.');
    });
});

// ── Cache invalidation ────────────────────────────────

describe('CityResource — cache invalidation', function () {
    test('a City save flushes a previously-warmed summary cache', function () {
        cityResourceLocation('Berlin', 52.5200, 13.4050);

        app(CityDirectoryService::class)->resolveCity('berlin'); // warm (positive or negative — both cache)
        expect(Cache::has('city-hubs:summary:berlin'))->toBeTrue();

        City::factory()->create(['slug' => 'berlin', 'city' => 'Berlin']);

        expect(Cache::missing('city-hubs:summary:berlin'))->toBeTrue();
    });

    test('a slug change flushes both the old and the new summary cache', function () {
        cityResourceLocation('Berlin', 52.5200, 13.4050);
        cityResourceLocation('Hamburg', 53.5511, 9.9937);

        $service = app(CityDirectoryService::class);
        $service->resolveCity('berlin');
        $service->resolveCity('hamburg');

        $city = City::factory()->create(['slug' => 'berlin', 'city' => 'Berlin']);
        $city->update(['city' => 'Hamburg', 'slug' => 'hamburg']);

        expect(Cache::missing('city-hubs:summary:berlin'))->toBeTrue()
            ->and(Cache::missing('city-hubs:summary:hamburg'))->toBeTrue();
    });

    test('a City save also flushes the cities sitemap and sitemap index', function () {
        cityResourceLocation('Berlin', 52.5200, 13.4050);
        Cache::set('seo:sitemap:cities', '<test>xml</test>');
        Cache::set('seo:sitemap:index', '<sitemapindex>test</sitemapindex>');

        City::factory()->create(['slug' => 'berlin', 'city' => 'Berlin']);

        expect(Cache::get('seo:sitemap:cities'))->toBeNull()
            ->and(Cache::get('seo:sitemap:index'))->toBeNull();
    });
});

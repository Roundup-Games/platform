<?php

use App\Filament\Resources\CityResource;
use App\Filament\Resources\CityResource\Pages\EditCity;
use App\Models\City;
use App\Models\Location;
use App\Models\User;
use App\Services\CityDirectoryService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\get;

//
// CityResource (D171): the admin surface over the city hub REGISTRY.
//
// The contract flipped from "create rows with derived slugs" to "curate
// auto-provisioned rows": there is no create page, identity fields are
// read-only, and curation (intro/featured/hidden) promotes a discovered
// row to curated via the City::saving hook. Every City write still
// flushes the affected summary cache via CityHubCacheObserver.
//
// geohash_4 is recomputed from lat/lng on save, so locations are seeded
// via coordinates and geohash_4 is never set directly (PG trigger, known
// gotcha). Fixed DACH coordinates pin cluster regions (CityHubPageTest
// convention):
//   Berlin  52.5200/13.4050 -> u33 region
//   Hamburg 53.5511/9.9937  -> u1x region
//
// Helpers carry a cityResource prefix — same-named globals in one Pest
// process fatal ("cannot redeclare function").
function cityResourceLocation(string $city, float $lat, float $lng, array $overrides = []): Location
{
    return Location::factory()->create(array_merge([
        'city' => $city,
        'country' => 'DEU',
        'latitude' => $lat,
        'longitude' => $lng,
    ], $overrides));
}

/** Curate the registry row for a cluster (mirrors the product flow). */
function cityResourceCurate(string $slug, array $attributes): City
{
    return City::updateOrCreate(['slug' => $slug], $attributes);
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
    test('renders the List and Edit pages as Platform Admin', function () {
        $city = City::factory()->create(['slug' => 'berlin', 'city' => 'Berlin']);

        actingAs($this->platformAdmin);

        get('/admin/cities')->assertSuccessful();
        get("/admin/cities/{$city->getKey()}/edit")->assertSuccessful();
    });

    test('has no create route — registry rows are auto-provisioned, never hand-created', function () {
        actingAs($this->platformAdmin);

        get('/admin/cities/create')->assertNotFound();
    });

    test('denies regular users access to the admin surface', function () {
        actingAs($this->regularUser);

        get('/admin/cities')->assertForbidden();
    });
});

// ── Registry surfacing ────────────────────────────────

describe('CityResource — registry surfacing', function () {
    test('lists auto-provisioned hubs with the triage badge counting discovered rows', function () {
        expect(CityResource::getNavigationBadge())->toBeNull(); // empty registry: no badge

        cityResourceLocation('Berlin', 52.5200, 13.4050);
        cityResourceLocation('Hamburg', 53.5511, 9.9937);

        // Both clusters auto-provisioned as discovered — triage queue is 2.
        expect(City::query()->where('curation_state', 'discovered')->count())->toBe(2)
            ->and(CityResource::getNavigationBadge())->toBe('2');

        // Curation drains the queue.
        cityResourceCurate('berlin', ['city' => 'Berlin', 'featured' => true]);
        expect(CityResource::getNavigationBadge())->toBe('1');
    });
});

// ── Curation form ─────────────────────────────────────

describe('CityResource — curation', function () {
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

    test('saves SEO overrides per locale across an active-locale switch', function () {
        cityResourceLocation('Berlin', 52.5200, 13.4050);
        $city = City::query()->where('slug', 'berlin')->firstOrFail();

        actingAs($this->platformAdmin);

        Livewire\Livewire::test(EditCity::class, ['record' => $city->getKey()])
            ->fillForm(['seo_title' => 'Berlin SEO EN'])
            ->fillForm(['seo_description' => 'Berlin meta EN.'])
            ->set('activeLocale', 'de')
            ->fillForm(['seo_title' => 'Berlin SEO DE'])
            ->call('save')
            ->assertHasNoErrors();

        $city->refresh();

        expect($city->getTranslation('seo_title', 'en'))->toBe('Berlin SEO EN')
            ->and($city->getTranslation('seo_title', 'de'))->toBe('Berlin SEO DE')
            ->and($city->getTranslation('seo_description', 'en'))->toBe('Berlin meta EN.')
            ->and($city->curation_state)->toBe('curated');
    });

    test('promotes a discovered row to curated on first curation and never demotes', function () {
        cityResourceLocation('Berlin', 52.5200, 13.4050);

        $city = City::query()->where('slug', 'berlin')->firstOrFail();
        expect($city->curation_state)->toBe('discovered');

        actingAs($this->platformAdmin);

        Livewire\Livewire::test(EditCity::class, ['record' => $city->getKey()])
            ->fillForm(['featured' => true])
            ->call('save')
            ->assertHasNoErrors();

        assertDatabaseHas('cities', ['id' => $city->id, 'curation_state' => 'curated', 'featured' => true]);

        // Reverting the flag does not demote — the row stays curated.
        Livewire\Livewire::test(EditCity::class, ['record' => $city->getKey()])
            ->fillForm(['featured' => false])
            ->call('save')
            ->assertHasNoErrors();

        assertDatabaseHas('cities', ['id' => $city->id, 'curation_state' => 'curated', 'featured' => false]);
    });

    test('cannot delete a hub that still has linked locations (FK RESTRICT)', function () {
        cityResourceLocation('Berlin', 52.5200, 13.4050);

        $city = City::query()->where('slug', 'berlin')->firstOrFail();

        expect($city->locations()->exists())->toBeTrue()
            ->and(fn () => $city->delete())->toThrow(QueryException::class);
    });
});

// ── Cache invalidation ────────────────────────────────

describe('CityResource — cache invalidation', function () {
    test('a City save flushes a previously-warmed summary cache', function () {
        cityResourceLocation('Berlin', 52.5200, 13.4050);

        app(CityDirectoryService::class)->resolveCity('berlin'); // warm (positive or negative — both cache)
        expect(Cache::has('city-hubs:summary:berlin'))->toBeTrue();

        cityResourceCurate('berlin', ['city' => 'Berlin', 'hidden' => true]);

        expect(Cache::missing('city-hubs:summary:berlin'))->toBeTrue();
    });

    test('a City save also flushes the cities sitemap and sitemap index', function () {
        cityResourceLocation('Berlin', 52.5200, 13.4050);
        Cache::set('seo:sitemap:cities', '<test>xml</test>');
        Cache::set('seo:sitemap:index', '<sitemapindex>test</sitemapindex>');

        cityResourceCurate('berlin', ['city' => 'Berlin', 'featured' => true]);

        expect(Cache::get('seo:sitemap:cities'))->toBeNull()
            ->and(Cache::get('seo:sitemap:index'))->toBeNull();
    });
});

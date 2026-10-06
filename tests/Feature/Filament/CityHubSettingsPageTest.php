<?php

use App\Filament\Pages\System\CityHubSettingsPage;
use App\Models\CityHubSetting;
use App\Models\Game;
use App\Models\Location;
use App\Models\User;
use App\Services\CityDirectoryService;
use App\Services\CityHubSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\get;

//
// CityHubSettingsPage (62-04-T05): the Filament surface over the DB-backed
// city hub thresholds — the "adjust activity threshold without a deploy"
// knob. The storage layer (city_hub_settings rows + the cached
// CityHubSettings accessors + forgetAll) is pinned by CityHubSettingsTest;
// these tests pin the page contract: admin-only access, the form seeded
// from the currently enforced thresholds, the save path persisting both
// rows and flushing every downstream cache a threshold change re-rates
// (per-city resolutions, the cities sub-sitemap, the sitemap index), and
// the end-to-end re-rate — a borderline city flipping 404 → 200 in the
// same process after a save.
//
// geohash_4 is recomputed from lat/lng on save, so tests control
// coordinates and never set geohash_4 directly (PG trigger, known gotcha).
// Fixed DACH coordinates pin cluster regions (CityHubPageTest convention):
//   Berlin 52.5200/13.4050 -> u33 region
//
// Helpers carry a cityHubSettings prefix: CityHubPageTest, CityResourceTest,
// and the service tests define the same shapes under other prefixes —
// same-named globals in one Pest process fatal ("cannot redeclare function").
function cityHubSettingsLocation(string $city, float $lat, float $lng): Location
{
    return Location::factory()->create([
        'city' => $city,
        'country' => 'DEU',
        'latitude' => $lat,
        'longitude' => $lng,
    ]);
}

function cityHubSettingsUpcomingGame(Location $location): Game
{
    return Game::factory()->create([
        'location_id' => $location->id,
        'date_time' => now()->addDays(3),
    ]);
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

    // City resolutions (positive AND negative), the settings entry, and
    // sitemap caches are all warmable — flush per test for isolation.
    Cache::flush();
});

// ═══════════════════════════════════════════════════════════
// ACCESS + INITIAL STATE
// ═══════════════════════════════════════════════════════════

describe('CityHubSettingsPage — access', function () {
    test('renders for a Platform Admin and denies regular users', function () {
        actingAs($this->platformAdmin);
        get('/admin/city-hub-settings')->assertSuccessful();

        actingAs($this->regularUser);
        get('/admin/city-hub-settings')->assertForbidden();
    });
});

describe('CityHubSettingsPage — initial form state', function () {
    test('fills the form from the currently enforced thresholds', function () {
        actingAs($this->platformAdmin);

        Livewire\Livewire::test(CityHubSettingsPage::class)
            ->assertFormSet([
                'min_upcoming_sessions' => 3,
                'min_verified_venues' => 2,
            ]);
    });

    test('shows the stored DB values once rows exist', function () {
        app(CityHubSettings::class)->set(4, 1);

        actingAs($this->platformAdmin);

        Livewire\Livewire::test(CityHubSettingsPage::class)
            ->assertFormSet([
                'min_upcoming_sessions' => 4,
                'min_verified_venues' => 1,
            ]);
    });
});

// ═══════════════════════════════════════════════════════════
// SAVE — PERSISTENCE + INVALIDATION
// ═══════════════════════════════════════════════════════════

describe('CityHubSettingsPage — save', function () {
    test('persists both city_hub_settings rows and flushes every re-rated cache', function () {
        // A Berlin location makes 'berlin' a known slug for forgetAll().
        cityHubSettingsLocation('Berlin', 52.5200, 13.4050);

        // Warm every cache the save path must invalidate: the per-city
        // resolution (positive or negative — both cache), the cities
        // sub-sitemap, and the sitemap index.
        app(CityDirectoryService::class)->resolveCity('berlin');
        Cache::put('seo:sitemap:cities', '<sitemap/>', now()->addHour());
        Cache::put('seo:sitemap:index', '<sitemapindex/>', now()->addHour());

        expect(Cache::has('city-hubs:summary:berlin'))->toBeTrue();

        actingAs($this->platformAdmin);

        Livewire\Livewire::test(CityHubSettingsPage::class)
            ->fillForm([
                'min_upcoming_sessions' => 5,
                'min_verified_venues' => 4,
            ])
            ->call('save')
            ->assertHasNoErrors();

        assertDatabaseHas('city_hub_settings', ['key' => 'min_upcoming_sessions', 'value' => 5]);
        assertDatabaseHas('city_hub_settings', ['key' => 'min_verified_venues', 'value' => 4]);

        expect(Cache::missing('city-hubs:summary:berlin'))->toBeTrue()
            ->and(Cache::missing('seo:sitemap:cities'))->toBeTrue()
            ->and(Cache::missing('seo:sitemap:index'))->toBeTrue()
            // The accessors observe the new values immediately (set()
            // forgets the shared settings entry).
            ->and(app(CityHubSettings::class)->minUpcomingSessions())->toBe(5)
            ->and(app(CityHubSettings::class)->minVerifiedVenues())->toBe(4);
    });

    test('re-rates a borderline city from 404 to 200 in the same process after a save', function () {
        // Two upcoming games, plain (unslugged, unverified) locations:
        // qualifies on neither path at the 3/2 config defaults.
        $berlin = cityHubSettingsLocation('Berlin', 52.5200, 13.4050);
        cityHubSettingsUpcomingGame($berlin);
        cityHubSettingsUpcomingGame($berlin);

        get(route('city-hubs.show', ['slug' => 'berlin']))->assertNotFound();

        actingAs($this->platformAdmin);

        // Lowering the session threshold to 2 flips the cluster to
        // qualifying — and the save's forgetAll() clears the cached
        // 404 resolution, so the very next request re-rates: DB beats
        // config with no deploy and no cache TTL wait.
        Livewire\Livewire::test(CityHubSettingsPage::class)
            ->fillForm([
                'min_upcoming_sessions' => 2,
                'min_verified_venues' => 2,
            ])
            ->call('save')
            ->assertHasNoErrors();

        get(route('city-hubs.show', ['slug' => 'berlin']))->assertOk();
    });

    test('rejects a negative threshold with a validation error and persists nothing', function () {
        actingAs($this->platformAdmin);

        Livewire\Livewire::test(CityHubSettingsPage::class)
            ->fillForm([
                'min_upcoming_sessions' => -1,
                'min_verified_venues' => 2,
            ])
            ->call('save')
            ->assertHasFormErrors(['min_upcoming_sessions']);

        expect(CityHubSetting::query()->count())->toBe(0);
    });
});

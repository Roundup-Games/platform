<?php

use App\Filament\Resources\LocationResource;
use App\Filament\Resources\LocationResource\Pages\EditLocation;
use App\Livewire\Venues\ProposeVenue;
use App\Models\City;
use App\Models\Location;
use App\Models\User;
use App\Support\Country;

use function Pest\Laravel\actingAs;

//
// Location-intake data discipline (D173): country is ISO 3166-1 alpha-3
// end to end, and city intake goes through the registry combobox — an
// explicit hub attachment in the admin, autocomplete over known clusters
// on the public proposal form.
//
// Storage normalization (mutators) and the 190000 data migration share
// App\Support\Country as the single home for the standard.
beforeEach(function () {
    //
});

describe('Country standard', function () {
    it('normalizes case and whitespace on write', function () {
        $location = Location::factory()->create([
            'name' => 'Norm Venue',
            'country' => '  deu ',
            'city' => 'Normcity',
            'latitude' => 52.52,
            'longitude' => 13.405,
        ]);

        expect($location->fresh()->country)->toBe('DEU');
    });

    it('exposes localized options for selects, keyed by alpha-3', function () {
        $en = Country::options('en');
        $de = Country::options('de');

        expect($en)->toBeArray()->toHaveKey('DEU')
            ->and($en['DEU'])->toBe('Germany')
            ->and($de['DEU'])->toBe('Deutschland')
            ->and(Country::isValid('DEU'))->toBeTrue()
            ->and(Country::isValid('DE'))->toBeFalse()  // alpha-2 is not the standard
            ->and(Country::isValid('XX'))->toBeFalse();
    });

    it('upgrades legacy alpha-2 values but never invents codes', function () {
        expect(Country::upgradeToAlpha3('de'))->toBe('DEU')
            ->and(Country::upgradeToAlpha3('CH'))->toBe('CHE')
            ->and(Country::upgradeToAlpha3('deu'))->toBe('DEU')
            ->and(Country::upgradeToAlpha3('ZZ'))->toBe('ZZ')  // unknown passes through
            ->and(Country::upgradeToAlpha3(null))->toBeNull();
    });
});

describe('migration 2026_10_09_190000 country normalization', function () {
    it('upgrades ISO-2 and mixed-case values, leaves unknowns untouched', function () {
        // Raw inserts: factories would run the country mutator.
        $rows = [
            ['id' => (string) Str::uuid7(), 'name' => 'A', 'country' => 'DE', 'latitude' => 52.52, 'longitude' => 13.405],
            ['id' => (string) Str::uuid7(), 'name' => 'B', 'country' => 'deu', 'latitude' => 52.52, 'longitude' => 13.405],
            ['id' => (string) Str::uuid7(), 'name' => 'C', 'country' => 'XX', 'latitude' => 52.52, 'longitude' => 13.405],
        ];
        DB::table('locations')->insert($rows);

        $migration = require database_path('migrations/2026_10_09_190000_normalize_country_codes.php');
        $migration->up();

        $byName = DB::table('locations')->whereIn('id', array_column($rows, 'id'))->get()->keyBy('name');

        expect($byName['A']->country)->toBe('DEU')     // ISO-2 upgraded
            ->and($byName['B']->country)->toBe('DEU')   // mixed case normalized
            ->and($byName['C']->country)->toBe('XX');   // unknown left for review
    });
});

describe('admin city registry combobox', function () {
    it('lists registry clusters as options and shows the linked hub on edit', function () {
        seedRoles();
        $admin = User::factory()->create();
        $admin->assignRole('Platform Admin');
        actingAs($admin);
        Filament\Facades\Filament::setCurrentPanel('admin');

        $location = Location::factory()->create([
            'name' => 'Combobox Venue',
            'city' => 'Combocity',
            'country' => 'DEU',
            'latitude' => 52.52,
            'longitude' => 13.405,
        ]);

        $city = City::query()->where('slug', 'combocity')->firstOrFail();
        expect($location->fresh()->city_id)->toBe($city->id)
            ->and(LocationResource::registryCityOptions())->toHaveKey('Combocity');

        // The edit form makes the hub attachment explicit.
        Livewire\Livewire::test(EditLocation::class, ['record' => $location->getKey()])
            ->assertOk()
            ->assertSee('/cities/combocity');
    });
});

describe('public venue proposal intake', function () {
    it('rejects non-ISO-3 countries and offers registry city suggestions', function () {
        $user = User::factory()->create();

        // ISO-2 country ('DE'): the historical leak — submit must reject it.
        Livewire\Livewire::actingAs($user)->test(ProposeVenue::class)
            ->set('name', 'Proposal Venue')
            ->set('address', 'Teststrasse 1')
            ->set('city', 'Berlin')
            ->set('postal_code', '10115')
            ->set('venue_type', 'cafe')
            ->set('country', 'DE')
            ->call('submit')
            ->assertHasErrors(['country']);

        // A fresh render shows the ISO-3 select and registry city suggestions.
        $html = Livewire\Livewire::actingAs($user)->test(ProposeVenue::class)->html();

        expect($html)->toContain('registry-cities')
            ->and($html)->toContain('<option value="DEU">');
    });
});

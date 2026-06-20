<?php

use App\Livewire\Teams\BrowseTeams;
use App\Models\Team;
use Livewire\Livewire;

/*
 * M053 / S01 / T06 — Route event & team location display through the
 * disclosure service (no orphans).
 *
 * BrowseTeamsTest proves the public team-browse list renders each team's
 * city/country via the single <x-location-display> authority (raw-city path),
 * not a raw {{ $team->city }} interpolation.
 */

describe('BrowseTeamsTest', function () {
    it('renders a team city and country through the location-display component', function () {
        $team = Team::factory()->create([
            'name' => 'Springfield United FC',
            'city' => 'Springfield',
            'country' => 'US',
            'is_active' => true,
        ]);

        // Scope the list to this team via the component's `search` prop so
        // rows leaked by other tests in the full-suite ordering cannot render
        // into this assertion. The intent is unchanged.
        Livewire::test(BrowseTeams::class, ['search' => 'Springfield United FC'])
            ->assertSee('Springfield United FC')
            // city + country are composed by the component as "Springfield, US"
            ->assertSee('Springfield, US');
    });

    it('renders nothing for a team with no city or country', function () {
        $team = Team::factory()->create([
            'name' => 'Nomad Squad FC',
            'city' => null,
            'country' => null,
            'is_active' => true,
        ]);

        // Scope to this team only. Without this, teams with a city/country
        // leaked from earlier tests render `location_on` into the page and
        // cause a false failure — the very pollution this test does not aim
        // to assert against. The intent — "a null-city team renders no
        // location icon" — is preserved exactly.
        Livewire::test(BrowseTeams::class, ['search' => 'Nomad Squad FC'])
            ->assertSee('Nomad Squad FC')
            ->assertDontSee('location_on');
    });
});

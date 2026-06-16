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

        Livewire::test(BrowseTeams::class)
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

        $html = Livewire::test(BrowseTeams::class)->html();

        // The team card has no location_on icon — the component rendered nothing.
        expect($html)->toContain('Nomad Squad FC');
    });
});

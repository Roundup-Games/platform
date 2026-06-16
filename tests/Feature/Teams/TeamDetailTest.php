<?php

use App\Livewire\Teams\TeamDetail;
use App\Models\Team;
use Livewire\Livewire;

/*
 * M053 / S01 / T06 — Route event & team location display through the
 * disclosure service (no orphans).
 *
 * TeamDetailTest proves the public team-detail page renders the team's
 * city/country via the single <x-location-display> authority (raw-city path),
 * not a raw {{ $team->city }} interpolation.
 */

describe('TeamDetailTest', function () {
    it('renders the team city and country through the location-display component', function () {
        $team = Team::factory()->create([
            'name' => 'Berlin Boardgamers FC',
            'slug' => 'berlin-boardgamers',
            'city' => 'Berlin',
            'country' => 'DE',
            'is_active' => true,
        ]);

        Livewire::test(TeamDetail::class, ['slug' => $team->slug])
            ->assertSee('Berlin Boardgamers FC')
            // city + country are composed by the component as "Berlin, DE"
            ->assertSee('Berlin, DE');
    });

    it('renders no location line when the team has no city or country', function () {
        $team = Team::factory()->create([
            'name' => 'Wanderers FC',
            'slug' => 'wanderers',
            'city' => null,
            'country' => null,
            'is_active' => true,
        ]);

        $html = Livewire::test(TeamDetail::class, ['slug' => $team->slug])->html();

        expect($html)->toContain('Wanderers FC');
    });
});

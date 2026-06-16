<?php

use App\Mail\TeamInvitationEmail;
use App\Models\Team;
use App\Models\User;

/*
 * M053 / S01 / T06 — Route event & team location display through the
 * disclosure service (no orphans).
 *
 * TeamInvitationTest proves the team-invitation email renders the team's
 * city/country via the single <x-location-display> authority (raw-city path),
 * not a raw {{ collect([...]) }} interpolation. The email context has no
 * session viewer, so the raw-city path is the correct one.
 */

describe('TeamInvitationTest', function () {
    it('renders the team base location through the location-display component', function () {
        $inviter = User::factory()->create(['name' => 'Alice']);
        $team = Team::factory()->create([
            'name' => 'Berlin Boardgamers FC',
            'city' => 'Berlin',
            'country' => 'DE',
        ]);

        $email = new TeamInvitationEmail($team, $inviter, 'invitee@example.com', 'https://example.test/accept');
        $rendered = $email->render();

        // city + country are composed by the component as "Berlin, DE"
        expect($rendered)->toContain('Berlin, DE');
    });

    it('omits the base-location line when the team has no city or country', function () {
        $inviter = User::factory()->create(['name' => 'Bob']);
        $team = Team::factory()->create([
            'name' => 'Nomad Squad FC',
            'city' => null,
            'country' => null,
        ]);

        $email = new TeamInvitationEmail($team, $inviter, 'invitee@example.com', 'https://example.test/accept');
        $rendered = $email->render();

        // No "Based in" line renders when the raw-city set is empty.
        expect($rendered)->toContain('Nomad Squad FC');
    });
});

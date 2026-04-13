<?php

use App\Models\Team;
use App\Models\User;

test('team slug is auto-generated on create', function () {
    $team = Team::factory()->create(['name' => 'Eagles', 'slug' => null]);

    expect($team->slug)->not->toBeEmpty()
        ->and($team->slug)->toStartWith('eagles-');
});

test('team slug includes random suffix', function () {
    $team = Team::factory()->create(['name' => 'Eagles FC']);

    // Pattern: sluggified name + dash + 6 random alphanumeric chars (upper + lower + digits)
    expect($team->slug)->toMatch('/^eagles-fc-[a-zA-Z0-9]{6}$/');
});

test('duplicate team names create unique slugs', function () {
    $user = User::factory()->create();

    $team1 = Team::factory()->create(['name' => 'Eagles', 'created_by' => $user->id]);
    $team2 = Team::factory()->create(['name' => 'Eagles', 'created_by' => $user->id]);

    expect($team1->slug)->not->toBe($team2->slug)
        ->and($team1->id)->not->toBe($team2->id);
});

test('explicit slug is not overwritten', function () {
    $team = Team::factory()->create(['name' => 'Eagles', 'slug' => 'custom-slug']);

    expect($team->slug)->toBe('custom-slug');
});

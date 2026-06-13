<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;

it('deletes orphan manual locations with zero references', function () {
    // Create an orphan (no games, events, campaigns, users referencing it)
    Location::factory()->create([
        'source' => 'manual',
        'name' => 'Orphan Location',
        'city' => 'Nowhere',
        'created_at' => now()->subDays(2),
    ]);

    // Create a referenced manual location (should NOT be deleted)
    $referenced = Location::factory()->create([
        'source' => 'manual',
        'name' => 'Referenced Location',
        'city' => 'Somewhere',
        'created_at' => now()->subDays(2),
    ]);
    Game::factory()->create(['location_id' => $referenced->id]);

    // Create a recent orphan (should NOT be deleted — too recent)
    Location::factory()->create([
        'source' => 'manual',
        'name' => 'Recent Orphan',
        'city' => 'Recent',
        'created_at' => now()->subHours(1),
    ]);

    // Create a non-manual orphan (should NOT be deleted)
    Location::factory()->create([
        'source' => 'session',
        'name' => 'Session Orphan',
        'city' => 'Session',
        'created_at' => now()->subDays(2),
    ]);

    $this->artisan('locations:prune-orphans')
        ->expectsOutput('Deleted 1 orphan location(s).')
        ->assertSuccessful();

    expect(Location::where('name', 'Orphan Location')->exists())->toBeFalse();
    expect(Location::where('name', 'Referenced Location')->exists())->toBeTrue();
    expect(Location::where('name', 'Recent Orphan')->exists())->toBeTrue();
    expect(Location::where('name', 'Session Orphan')->exists())->toBeTrue();
});

it('respects hours option for age cutoff', function () {
    // Create an orphan that's 12 hours old
    Location::factory()->create([
        'source' => 'manual',
        'name' => '12h Orphan',
        'city' => 'Old',
        'created_at' => now()->subHours(12),
    ]);

    // Default cutoff is 24h — should NOT be deleted
    $this->artisan('locations:prune-orphans')
        ->expectsOutput('No orphan locations found.')
        ->assertSuccessful();

    // With 10h cutoff — should be deleted
    $this->artisan('locations:prune-orphans', ['--hours' => 10])
        ->expectsOutput('Deleted 1 orphan location(s).')
        ->assertSuccessful();
});

it('dry-run does not delete anything', function () {
    Location::factory()->create([
        'source' => 'manual',
        'name' => 'Dry Run Orphan',
        'city' => 'Test',
        'created_at' => now()->subDays(2),
    ]);

    $this->artisan('locations:prune-orphans', ['--dry-run' => true])
        ->expectsOutputToContain('[DRY-RUN]')
        ->assertSuccessful();

    expect(Location::where('name', 'Dry Run Orphan')->exists())->toBeTrue();
});

it('does not delete locations referenced by campaigns', function () {
    $owner = User::factory()->create();
    $system = GameSystem::factory()->create();

    $campaignLocation = Location::factory()->create([
        'source' => 'manual',
        'name' => 'Campaign Location',
        'city' => 'Test',
        'created_at' => now()->subDays(2),
    ]);

    Campaign::create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'name' => ['en' => 'Test Campaign'],
        'description' => ['en' => 'Test'],
        'session_duration' => 2,
        'status' => 'active',
        'recurrence' => 'weekly',
        'time_of_day' => '19:00',
        'language' => 'en',
        'location_id' => $campaignLocation->id,
    ]);

    $this->artisan('locations:prune-orphans')
        ->expectsOutput('No orphan locations found.')
        ->assertSuccessful();

    expect(Location::where('name', 'Campaign Location')->exists())->toBeTrue();
});

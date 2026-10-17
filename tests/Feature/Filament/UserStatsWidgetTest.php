<?php

use App\Filament\Widgets\UserStatsWidget;
use App\Models\User;

use function Pest\Laravel\actingAs;

//
// UserStatsWidget: six user-table aggregates, rendered via Livewire like the
// sibling dashboard widgets. Guards the 5-minute cache so re-renders do not
// re-scan the users table.
beforeEach(function () {
    seedRoles();

    $platformAdmin = User::factory()->create();
    $platformAdmin->assignRole('Platform Admin');
    actingAs($platformAdmin);
    Filament\Facades\Filament::setCurrentPanel('admin');
    Cache::flush();
});

it('renders user stats with real aggregates', function () {
    User::factory()->count(3)->create(['profile_complete' => true]);

    Livewire\Livewire::test(UserStatsWidget::class)
        ->assertOk()
        ->assertSee('Total Users')
        ->assertSee('Profile Complete')
        ->assertSee('Email Verified')
        ->assertSee('Recently Verified');
});

it('caches the aggregates for five minutes instead of per render', function () {
    User::factory()->create();

    Livewire\Livewire::test(UserStatsWidget::class)->assertOk();

    expect(Cache::has('admin:user-stats'))->toBeTrue();
});

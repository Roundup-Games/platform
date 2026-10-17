<?php

use App\Filament\Widgets\DatabaseHealthWidget;
use App\Filament\Widgets\PlatformPulseWidget;
use App\Filament\Widgets\RedisHealthWidget;
use App\Models\City;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Redis;

use function Pest\Laravel\actingAs;

//
// Admin dashboard truth widgets (replacing the framework-version +
// dead-counter defaults): platform pulse, Redis server stats, and
// Postgres health. Smoke-rendered as a Platform Admin with real seeded
// data so the aggregate queries are exercised, not just the view.
beforeEach(function () {
    seedRoles();

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('Platform Admin');
    actingAs($this->platformAdmin);
    Filament\Facades\Filament::setCurrentPanel('admin');
    Cache::flush();
});

it('renders the platform pulse with real aggregates', function () {
    GameSystem::factory()->create();
    Location::factory()->create(['is_verified' => true, 'city' => 'Dashcity', 'country' => 'DEU', 'latitude' => 52.52, 'longitude' => 13.405]);
    City::query()->where('slug', 'dashcity')->update(['curation_state' => 'curated']);

    Livewire\Livewire::test(PlatformPulseWidget::class)
        ->assertOk()
        ->assertSee('Upcoming public sessions')
        ->assertSee('Open tickets')
        ->assertSee('City hub triage')
        ->assertSee('New users (7d)');
});

it('renders redis health and fails soft when redis is unreachable', function () {
    // Force the outage branch deterministically — a dev machine with a
    // local Redis running would otherwise take the healthy path and break
    // this test (exactly what happened on this workstation). The healthy
    // branch renders server INFO stats and is verified on the live
    // deployment (admin dashboard).
    Redis::shouldReceive('info')->andThrow(new RuntimeException('connection refused'));

    Livewire\Livewire::test(RedisHealthWidget::class)
        ->assertOk()
        ->assertSee('unreachable');
});

it('renders database health with ratio, size, and bloat watch', function () {
    Livewire\Livewire::test(DatabaseHealthWidget::class)
        ->assertOk()
        ->assertSee('Buffer cache hit ratio')
        ->assertSee('Bloat watch');
});

it('no longer ships the framework-version system info widget', function () {
    expect(file_exists(app_path('Filament/Widgets/SystemInfoWidget.php')))->toBeFalse();
});

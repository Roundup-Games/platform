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

it('expires the cached aggregates after five minutes so stats cannot drift stale indefinitely', function () {
    User::factory()->create();

    Livewire\Livewire::test(UserStatsWidget::class)->assertOk();
    expect(Cache::has('admin:user-stats'))->toBeTrue();

    // Still inside the TTL window: the cached entry survives.
    $this->travel(4)->minutes();
    expect(Cache::has('admin:user-stats'))->toBeTrue();

    // Past the TTL the entry is gone, and the next render recomputes it.
    $this->travel(2)->minutes();
    expect(Cache::has('admin:user-stats'))->toBeFalse();

    Livewire\Livewire::test(UserStatsWidget::class)->assertOk();
    expect(Cache::has('admin:user-stats'))->toBeTrue();
});

it('serves the cached snapshot between refreshes instead of re-scanning the users table', function () {
    // No absolute user counts: parallel workers share one Testcontainers
    // database, and pre-existing PHPUnit-style tests without
    // DatabaseTransactions can commit rows mid-run (MEM755). The guarded
    // property is snapshot-vs-table divergence, which survives pollution.
    User::factory()->create(); // admin + 1 = at least 2 users

    Livewire\Livewire::test(UserStatsWidget::class)->assertOk();
    $snapshot = Cache::get('admin:user-stats');
    expect($snapshot)->not->toBeNull()
        ->and($snapshot['total'])->toBeGreaterThanOrEqual(2);

    // A user created mid-window stays invisible until the TTL lapses: the
    // table moves ahead of the cached snapshot...
    User::factory()->create();
    expect(User::count())->toBeGreaterThan($snapshot['total']);

    // ...so a re-scan would produce a different snapshot than the first render.
    Livewire\Livewire::test(UserStatsWidget::class)->assertOk();
    expect(Cache::get('admin:user-stats'))->toBe($snapshot);
});

it('keeps the 60-second polling interval so re-renders stay cheaper than the 5-minute cache refreshes', function () {
    $defaults = (new ReflectionClass(UserStatsWidget::class))->getDefaultProperties();

    $this->assertSame('60s', $defaults['pollingInterval']);
});

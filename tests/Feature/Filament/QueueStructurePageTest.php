<?php

use App\Filament\Pages\System\QueueStructurePage;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

//
// Queue Structure admin page: worker topology + expected job inventory.
// No live Redis dependency — this is the static view; Horizon owns the
// live one.
beforeEach(function () {
    seedRoles();

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('Platform Admin');
    actingAs($this->platformAdmin);
    Filament\Facades\Filament::setCurrentPanel('admin');
    Cache::flush();
});

it('renders worker topology and the job inventory for admins', function () {
    Livewire\Livewire::test(QueueStructurePage::class)
        ->assertOk()
        ->assertSee('Worker Topology')
        ->assertSee('Queues served')
        ->assertSee('Queue: discovery')
        ->assertSee('WarmDashboardCache')
        ->assertSee('ComputePlatformScores')
        ->assertSeeText('Scheduled');
});

it('is admin-only', function () {
    $member = User::factory()->create();
    actingAs($member);

    // Filament 4 drops canAccess()===false pages from the panel entirely.
    get('/admin/queue-structure')->assertNotFound();
});

<?php

use App\Filament\Pages\Escalated\EscalatedEmailSettings;
use App\Filament\Pages\Escalated\EscalatedManagePlugins;
use App\Filament\Pages\Escalated\EscalatedReports;
use App\Filament\Pages\Escalated\EscalatedSettings;
use App\Filament\Pages\Escalated\EscalatedSsoSettings;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

//
// Escalated admin pages smoke tests (M058/S04).
//
// The 5 highest-privilege admin surfaces (Settings, EmailSettings, SsoSettings,
// Reports, ManagePlugins) had ZERO tests. Each overrides canAccess() to gate on
// a Gate (escalated-admin for most; escalated-agent for Reports) — so the gate
// IS the security contract. These tests invoke each page's real canAccess()
// under an acting user, so a broken or mis-wired gate is caught PER PAGE rather
// than collapsed into a single permission assertion. (EscalatedReports gates on
// escalated-agent, not escalated-admin — checking the one permission uniformly
// would have masked a Reports regression.)
//
// NOTE: full page-render coverage is deferred — these pages are routed by the
// Escalated package's own Livewire routing (support/admin/*) and 404 on a bare
// GET without package-internal panel context. canAccess() is the security-
// critical control; render regressions are caught by AdminResourceSmokeTest.
//

beforeEach(function () {
    seedRoles();

    setPermissionsTeamId(null);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('Platform Admin');
    $this->platformAdmin->unsetRelations();

    $this->regularUser = User::factory()->create();
});

$pages = [
    EscalatedSettings::class,
    EscalatedEmailSettings::class,
    EscalatedSsoSettings::class,
    EscalatedReports::class,        // gates on escalated-agent (Platform Admin | Service Admin)
    EscalatedManagePlugins::class,  // config('escalated.plugins.enabled') AND escalated-admin
];

it('allows Platform Admin to access every escalated page', function () use ($pages) {
    // ManagePlugins is also config-gated; turn the feature flag on so the
    // authorization check is what the assertion actually exercises (otherwise
    // the parent's config() false short-circuits before the Gate runs).
    config(['escalated.plugins.enabled' => true]);
    $this->actingAs($this->platformAdmin);

    foreach ($pages as $page) {
        expect($page::canAccess())
            ->toBeTrue("Platform Admin should be able to access {$page}");
    }
})->group('smoke');

it('denies regular users access to every escalated page', function () use ($pages) {
    config(['escalated.plugins.enabled' => true]);
    $this->actingAs($this->regularUser);

    foreach ($pages as $page) {
        expect($page::canAccess())
            ->toBeFalse("Regular users must not be able to access {$page}");
    }
})->group('smoke');

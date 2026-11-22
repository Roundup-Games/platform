<?php

use App\Models\User;
use Filament\Facades\Filament;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

//
// Panel Livewire runtime presence (2026-11-22).
//
// The app layout's single-boot fix bundles Livewire+Alpine into app.js and
// disables Livewire's auto-injection globally (config/livewire.php
// inject_assets=false). The Filament panel layout never loads app.js, and
// @filamentScripts ships only Filament's own bundle — so without the panel
// render hooks in AdminPanelProvider, admin pages have NO Livewire runtime
// and the content area never hydrates (the empty-right-pane outage that
// shipped silently on 2026-11-21 because the js.runtime_error reporter also
// rides app.js). These assertions pin the restoration: config + core script
// present exactly once, and the app-surface bundle never leaks into the
// panel (a second Livewire would double-boot Alpine there).
//

beforeEach(function () {
    seedRoles();

    setPermissionsTeamId(null);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $admin = User::factory()->create();
    $admin->assignRole('Platform Admin');
    $admin->unsetRelations();

    actingAs($admin);
    Filament::setCurrentPanel('admin');
});

it('renders the admin dashboard with the Livewire runtime present exactly once', function () {
    $html = get('/admin')->assertOk()->content();

    // The stock tag self-configures via data attributes — one tag, no
    // window.livewireScriptConfig (that is the bundled-ESM flow's need and
    // would consume the once-guard).
    // Exactly one Livewire core tag, debug-agnostic: the endpoint path
    // carries a hashed prefix (EndpointResolver) and the filename flips
    // between livewire.js (APP_DEBUG, CI mirror) and livewire.min.js.
    expect(preg_match_all('#src="[^"]*/livewire[^"?]*\.js#', $html, $matches))->toBe(1)
        ->and($html)->toContain('data-update-uri=');
});

it('never loads the app-surface Livewire bundle on panel pages', function () {
    $html = get('/admin')->assertOk()->content();

    expect($html)->not->toContain('build/assets/app-');
});

it('emits the Livewire base stylesheet so wire:loading indicators stay hidden until in flight', function () {
    $html = get('/admin')->assertOk()->content();

    // The stock wire:loading display:none block — without it every loading
    // indicator on panel pages renders permanently visible (the indefinite-
    // swirlies regression, 2026-11-22). Assert the signature selector
    // exactly once, plus the x-cloak guard from the same block.
    expect(substr_count($html, '[wire\\:loading][wire\\:loading]'))->toBe(1)
        ->and($html)->toContain('[x-cloak=');
});

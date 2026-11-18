<?php

use App\Filament\Pages\System\PhpRuntimePage;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

// PHP Runtime admin page: OPcache + FPM pool visibility (scaling-prep
// 2026-11-13). The page renders through FPM in production; under CLI
// SAPIs the metrics MAY be unavailable — the page must degrade to its
// explanatory banner, never 500, mirroring the ScheduledTasksPage rule.
// Never assume CLI = degraded, though: CI boots pest with opcache enabled
// for speed, so opcache_get_status() reports live data there — the tests
// branch on the page's real inputs instead of hardcoding a SAPI verdict.

beforeEach(function () {
    seedRoles();

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('Platform Admin');
    actingAs($this->platformAdmin);
    Filament\Facades\Filament::setCurrentPanel('admin');
});

function phpRuntimeRenders(): Testable
{
    return Livewire\Livewire::test(PhpRuntimePage::class);
}

it('shows the configuration card in every SAPI', function () {
    phpRuntimeRenders()
        ->assertOk()
        ->assertSee('PHP Runtime')
        ->assertSee('Configuration')
        ->assertSee('max_accelerated_files');
});

it('shows runtime metrics or the degraded banner — never an error', function () {
    $page = phpRuntimeRenders();
    $page->assertOk();

    // Mirror the page's own availability probe: the blade gates the
    // degradation banner on $opcache === null alone (FPM only adds its
    // pool card), so branch on the same live SAPI state the page read.
    $opcacheLive = function_exists('opcache_get_status') && is_array(opcache_get_status(false));
    $fpmLive = function_exists('fpm_get_status') && is_array(fpm_get_status());

    if ($opcacheLive) {
        // The stats card renders — and the degradation banner must not.
        $page
            ->assertSee('OPcache')
            ->assertDontSee('Runtime metrics unavailable');
    } else {
        $page->assertSee('Runtime metrics unavailable');
    }

    if ($fpmLive) {
        $page->assertSee('FPM pool');
    }

    // Whatever the SAPI, the OPcache verdict is coherent: either the
    // stats card or the explanatory banner, and never both missing.
    $page->assertSee('OPcache');
});

it('redirects guests', function () {
    auth()->logout();

    get('/admin/php-runtime-page')->assertRedirect();
});

it('is unreachable for non-admins', function () {
    $user = User::factory()->create();
    actingAs($user);

    get('/admin/php-runtime-page')->assertForbidden();
});

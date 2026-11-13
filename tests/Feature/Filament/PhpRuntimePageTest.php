<?php

use App\Filament\Pages\System\PhpRuntimePage;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

// PHP Runtime admin page: OPcache + FPM pool visibility (scaling-prep
// 2026-11-13). The page renders through FPM in production, but the test
// runner executes under the CLI SAPI where opcache_get_status()/
// fpm_get_status() return false — the page must degrade to its
// explanatory banner, never 500, mirroring the ScheduledTasksPage rule.

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

    $underFpm = function_exists('fpm_get_status') && fpm_get_status() !== false;

    if ($underFpm) {
        $page->assertSee('FPM pool');
    } else {
        $page->assertSee('Runtime metrics unavailable');
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

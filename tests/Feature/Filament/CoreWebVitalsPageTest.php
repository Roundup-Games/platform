<?php

use App\Filament\Pages\System\CoreWebVitalsPage;
use App\Filament\Pages\System\SearchPerformancePage;
use App\Models\CwvMeasurement;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

//
// CoreWebVitalsPage — the visitor-experience trend view over the
// append-only cwv_measurements history (2026-11-12): fleet KPIs, the
// per-check heat grid, per-template desktop/mobile lanes with
// sparklines, and the click-through drill-down (locale filterable).
// Admin blade copy is asserted literally — EN-only admin surface, not
// lang/ values, so the K004 locale-decoupling rule does not apply.
//
// Seeding follows the run shape of CwvCheckCommand: one row per
// (template, locale, strategy) per run, locale encoded in the checked
// URL exactly like the command builds them.
beforeEach(function () {
    seedRoles();

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('Platform Admin');
    actingAs($this->platformAdmin);
    Filament\Facades\Filament::setCurrentPanel('admin');
});

/**
 * Seed one full cwv:check run at $date with an explicit score per
 * template (default 98 for anything unlisted) across every locale and
 * strategy.
 *
 * @param  array<string, int>  $scores  template => performance_score
 */
/** @param array<string, int> $scores */
function seedCwvRun(string $date, array $scores = []): void
{
    /** @var array<string, string> $templates */
    $templates = config('cwv.templates');
    /** @var list<string> $locales */
    $locales = config('cwv.locales');
    /** @var list<string> $strategies */
    $strategies = config('cwv.strategies');

    foreach ($templates as $template => $path) {
        foreach ($locales as $locale) {
            foreach ($strategies as $strategy) {
                CwvMeasurement::factory()->create([
                    'template' => $template,
                    'url' => 'https://roundup.games/'.$locale.$path,
                    'strategy' => $strategy,
                    'performance_score' => $scores[$template] ?? 98,
                    'fetched_at' => $date,
                ]);
            }
        }
    }
}

describe('CoreWebVitalsPage — access', function () {
    test('denies non-admin users', function () {
        $user = User::factory()->create();
        actingAs($user);

        get('/admin/core-web-vitals')->assertForbidden();
    });

    test('admins can open the page', function () {
        get('/admin/core-web-vitals')->assertOk();
    });
});

describe('CoreWebVitalsPage — empty state', function () {
    test('explains the weekly cadence before any measurement exists', function () {
        Livewire\Livewire::test(CoreWebVitalsPage::class)
            ->assertOk()
            ->assertSee('No Core Web Vitals measurements stored yet')
            ->assertSee('cwv:check')
            ->assertDontSee('<svg');
    });
});

describe('CoreWebVitalsPage — trend surface', function () {
    test('renders fleet KPIs, heat grid, and template lanes from stored runs', function () {
        seedCwvRun('2026-11-02 05:00:00', ['home' => 95]);
        seedCwvRun('2026-11-09 05:00:00', ['home' => 99, 'game-system' => 87]);

        Livewire\Livewire::test(CoreWebVitalsPage::class)
            ->assertOk()
            // Fleet: 24 checks, all ≥ 80; home improved 95 → 99.
            ->assertSee('24')
            ->assertSee('▲ 4')
            // Lanes render worst-of-locales as the headline number.
            ->assertSee('Game system')
            ->assertSee('worst of EN 87 / DE 87')
            ->assertSee('watch')
            // Sparklines are server-rendered SVG.
            ->assertSee('<svg', escape: false);
    });

    test('flags checks below the alert threshold as failing the fleet count', function () {
        seedCwvRun('2026-11-02 05:00:00', ['home' => 99]);
        seedCwvRun('2026-11-09 05:00:00', ['home' => 70]);

        Livewire\Livewire::test(CoreWebVitalsPage::class)
            ->assertOk()
            ->assertSee('23')
            ->assertSee('needs work')
            ->assertSee('▼ 29');
    });
});

describe('CoreWebVitalsPage — drill-down', function () {
    beforeEach(function () {
        seedCwvRun('2026-11-02 05:00:00', ['home' => 95]);
        seedCwvRun('2026-11-09 05:00:00', ['home' => 99]);
    });

    test('selecting a template renders the parallel history panels and run table', function () {
        Livewire\Livewire::test(CoreWebVitalsPage::class)
            ->call('selectTemplate', 'home')
            ->assertSet('selectedTemplate', 'home')
            ->assertOk()
            ->assertSee('Home — history')
            ->assertSee('Desktop EN')
            ->assertSee('Mobile DE')
            ->assertSee('Real-visitor vitals')
            ->assertSee('awaiting visitor data…')
            ->assertSee('02 Nov')
            ->assertSee('09 Nov');
    });

    test('the locale filter narrows the history panels', function () {
        Livewire\Livewire::test(CoreWebVitalsPage::class)
            ->call('selectTemplate', 'home')
            ->call('setLocaleFilter', 'de')
            ->assertSet('localeFilter', 'de')
            ->assertOk()
            ->assertSee('Desktop DE')
            ->assertDontSee('Desktop EN');
    });

    test('clearTemplate closes the drill-down', function () {
        Livewire\Livewire::test(CoreWebVitalsPage::class)
            ->call('selectTemplate', 'home')
            ->call('clearTemplate')
            ->assertSet('selectedTemplate', '')
            ->assertOk()
            ->assertDontSee('Home — history');
    });

    test('renders field-metric values with band verdicts once CrUX data exists', function () {
        seedCwvRun('2026-11-02 05:00:00', ['home' => 99]);
        seedCwvRun('2026-11-09 05:00:00', ['home' => 99]);

        // Newest home rows carry field data (the simulated-CrUX shape).
        CwvMeasurement::query()
            ->where('template', 'home')
            ->whereDate('fetched_at', '2026-11-09')
            ->update([
                'lcp_ms' => 2760,
                'inp_ms' => 190,
                'cls' => 0.06,
                'field_data_state' => 'sufficient',
            ]);

        Livewire\Livewire::test(CoreWebVitalsPage::class)
            ->call('selectTemplate', 'home')
            ->assertOk()
            ->assertSee('Latest CrUX field percentiles')
            ->assertSee('2.8s')       // 2760ms LCP, worst locale per device
            ->assertSee('needs improvement')
            ->assertSee('190ms')
            ->assertDontSee('awaiting visitor data…');
    });

    test('an unknown template key clears the selection instead of erroring', function () {
        Livewire\Livewire::test(CoreWebVitalsPage::class)
            ->call('selectTemplate', 'not-a-template')
            ->assertSet('selectedTemplate', '')
            ->assertOk();
    });
});

describe('SearchPerformancePage — CWV hand-off', function () {
    test('embeds the fleet summary and links to the trend page', function () {
        seedCwvRun('2026-11-02 05:00:00', ['home' => 99]);

        Livewire\Livewire::test(SearchPerformancePage::class)
            ->assertOk()
            ->assertSee('checks ≥ 80')
            ->assertSee('Desktop average 98')
            ->assertSee('Mobile average 98')
            ->assertSee(CoreWebVitalsPage::getUrl());
    });

    test('stays quiet about Core Web Vitals before any measurement exists', function () {
        Livewire\Livewire::test(SearchPerformancePage::class)
            ->assertOk()
            ->assertDontSee('checks ≥');
    });
});

<?php

use App\Filament\Pages\System\SearchPerformancePage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

//
// SearchPerformancePage — AI Surfaces section (78-05-T01): documents
// the generative-engine measurement gap (the GSC Search Analytics API
// exposes no AI-surface metrics; Google's Generative AI performance
// report is Search Console UI-only), the manual monthly spot-check
// checklist, and the agent-crawler posture links (llms.txt/robots.txt
// on config app.url). T02 adds the first-party llms.txt fetch count
// (cache-bucketed hourly counters incremented by the serve path,
// summed over 7 days). Admin blade copy is asserted literally — EN-only
// admin surface, not lang/ values, so the K004 locale-decoupling rule
// does not apply here.
beforeEach(function () {
    seedRoles();

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('Platform Admin');
    actingAs($this->platformAdmin);
    Filament\Facades\Filament::setCurrentPanel('admin');
});

describe('SearchPerformancePage — AI surfaces', function () {
    test('renders the AI Surfaces section with the GSC API gap statement', function () {
        Livewire\Livewire::test(SearchPerformancePage::class)
            ->assertOk()
            ->assertSee('AI Surfaces')
            ->assertSee('Search Analytics API')
            ->assertSee('exposes no')
            ->assertSee('Generative AI performance report')
            ->assertSee('https://support.google.com/webmasters/answer/16984139')
            ->assertSee('https://developers.google.com/search/docs/fundamentals/ai-optimization-guide');
    });

    test('renders the manual monthly spot-check checklist', function () {
        Livewire\Livewire::test(SearchPerformancePage::class)
            ->assertOk()
            ->assertSee('Manual monthly spot-check')
            ->assertSee('brand + seed queries')
            ->assertSee('ChatGPT')
            ->assertSee('AI Overviews')
            ->assertSee('Perplexity')
            ->assertSee('team notes');
    });

    test('renders absolute llms.txt and robots.txt posture links on config app.url', function () {
        $base = rtrim((string) config('app.url'), '/');

        Livewire\Livewire::test(SearchPerformancePage::class)
            ->assertOk()
            ->assertSee('Agent-crawler posture')
            ->assertSee($base.'/llms.txt')
            ->assertSee($base.'/robots.txt');
    });

    test('renders the llms.txt fetch-count section heading', function () {
        Livewire\Livewire::test(SearchPerformancePage::class)
            ->assertOk()
            ->assertSee('llms.txt fetches (last 7 days)');
    });

    test('shows the live 7-day llms.txt fetch count from first-party telemetry', function () {
        Cache::flush();

        for ($fetches = 0; $fetches < 42; $fetches++) {
            get('/llms.txt')->assertOk();
        }

        Livewire\Livewire::test(SearchPerformancePage::class)
            ->assertOk()
            ->assertSee('llms.txt fetches (last 7 days)')
            ->assertSee(number_format(42))
            ->assertSee('First-party count');
    });
});

describe('SearchPerformancePage — AI surfaces access', function () {
    test('denies non-admin users the section', function () {
        $member = User::factory()->create();
        actingAs($member);

        get('/admin/search-performance')->assertForbidden();
    });

    test('redirects guests', function () {
        auth()->logout();

        get('/admin/search-performance')->assertRedirect();
    });
});

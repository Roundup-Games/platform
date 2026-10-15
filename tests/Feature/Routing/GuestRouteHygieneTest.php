<?php

use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\get;

// ═══════════════════════════════════════════════════════════
// M066/S06/T05 — GUEST ROUTE HYGIENE
//
// Four contracts for the rebuilt guest surface:
//   1. Guest pages render 200 — no authenticatedUser() assert can
//      fire for a guest (the boards at /dashboard/games and
//      /dashboard/campaigns must sit behind the auth wall, not crash).
//   2. Redirects are intentional: 301 old→new board paths (T01),
//      410 Gone for unpublished pledge aliases, 302 login for every
//      demoted surface (teams, boards, member flows).
//   3. Sitemap statics expose the new pages (cities index, roadmap)
//      and every static <loc> round-trips to a guest 200 — the
//      STATIC_PATHS list can never drift into dead URLs.
//   4. No orphans: every public directory is linked from the guest
//      nav/footer layout.
// ═══════════════════════════════════════════════════════════

// ── 1. Guest pages render 200 ──────────────────────────

dataset('guest_route_hygiene_public_pages', [
    'home' => '/en/',
    'about' => '/en/about',
    'how-it-works' => '/en/how-it-works',
    'for-organizers' => '/en/for-organizers',
    'pricing (S08)' => '/en/pricing',
    'contact' => '/en/contact',
    'faq (S08)' => '/en/faq',
    'safety-tools' => '/en/safety-tools',
    'code of conduct (S08)' => '/en/safety/code-of-conduct',
    'privacy' => '/en/privacy',
    'terms' => '/en/terms',
    'impressum' => '/en/impressum',
    'gm directory' => '/en/gms',
    'game systems' => '/en/game-systems',
    'discover' => '/en/discover',
    'discover board games' => '/en/discover/board-games',
    'discover adventures' => '/en/discover/adventures',
    'events' => '/en/events',
    'venue directory' => '/en/venues',
    'cities index (T04)' => '/en/cities',
    'our pledge' => '/en/our-pledge',
    'pledge algorithms' => '/en/our-pledge/algorithms',
    'pledge roadmap' => '/en/our-pledge/roadmap',
    'login' => '/en/login',
    'register' => '/en/register',
    'forgot password' => '/en/forgot-password',
]);

describe('GuestRouteHygiene — public pages', function () {
    it('renders every guest page 200 for an anonymous visitor', function (string $path) {
        get($path)->assertOk();
    })->with('guest_route_hygiene_public_pages');

    it('answers 410 Gone for the unpublished pledge aliases', function (string $path) {
        // D179 honesty policy — never a redirect loop back to the hub.
        get($path)->assertStatus(410);
    })->with([
        'pledge finances' => '/en/our-pledge/finances',
        'pledge operations' => '/en/our-pledge/operations',
    ]);

    it('redirects locale-less paths to the locale-prefixed equivalent', function () {
        // The locale-less catch-all hands bare paths to the preferred
        // locale (en with no Accept-Language header) — a 302, not a 404.
        get('/discover')->assertRedirect('/en/discover');
    });
});

// ── 2. Auth wall — guests redirect to login, never crash ──

dataset('guest_route_hygiene_auth_walled', [
    'dashboard games board (T01)' => '/en/dashboard/games',
    'dashboard campaigns board (T01)' => '/en/dashboard/campaigns',
    'teams browse (T02)' => '/en/teams',
    'dashboard' => '/en/dashboard',
    'profile' => '/en/profile',
    'plan' => '/en/plan',
    'games create' => '/en/games/create',
    'campaigns create' => '/en/campaigns/create',
    'events create' => '/en/events/create',
    'people' => '/en/people',
    'notifications' => '/en/notifications',
    'gm workspace' => '/en/gm-workspace',
    'onboarding' => '/en/onboarding',
    'membership' => '/en/membership',
]);

describe('GuestRouteHygiene — auth wall', function () {
    it('redirects guests to login instead of rendering an authenticated page', function (string $path) {
        // A missing auth wall would render the Livewire component and
        // authenticatedUser()'s assert would abort into a 500 — the
        // redirect contract is the crash guard for the T01 boards.
        get($path)->assertRedirect(route('login'));
    })->with('guest_route_hygiene_auth_walled');
});

// ── 3. Permanent redirects — old paths 301 to their new homes ──

describe('GuestRouteHygiene — permanent redirects', function () {
    it('permanently redirects legacy board paths to the dashboard (locale-preserving)', function (string $old, string $new) {
        get($old)->assertStatus(301)->assertRedirect($new);
    })->with([
        'en games' => ['/en/games', '/en/dashboard/games'],
        'de games' => ['/de/games', '/de/dashboard/games'],
        'en campaigns' => ['/en/campaigns', '/en/dashboard/campaigns'],
        'de campaigns' => ['/de/campaigns', '/de/dashboard/campaigns'],
        'en near' => ['/en/near', '/en/discover'],
    ]);
});

// ── 4. Dynamic sweep — every parameterless locale GET route answers ──

describe('GuestRouteHygiene — dynamic sweep', function () {
    it('serves every parameterless {locale} GET route as 200/301/302/410 for a guest', function () {
        // The curated datasets above pin intent for the known surface;
        // this sweep adds the "every" guarantee: any future guest GET
        // route that 404s or explodes (missing seed, auth-wall gap,
        // authenticatedUser() crash) fails here even if no dataset row
        // was added for it.
        $paths = collect(Route::getRoutes())
            ->filter(fn ($route) => in_array('GET', $route->methods(), true))
            ->filter(fn ($route) => str_starts_with($route->uri(), '{locale}/'))
            ->filter(fn ($route) => ! str_contains(substr($route->uri(), strlen('{locale}/')), '{'))
            ->map(fn ($route) => str_replace('{locale}', 'en', $route->uri()))
            ->unique()
            ->values();

        expect($paths)->not->toBeEmpty();

        $allowed = [200, 301, 302, 410];
        foreach ($paths as $path) {
            $status = get($path)->status();
            test()->assertContains(
                $status,
                $allowed,
                "{$path} answered {$status} for a guest — expected an intentional 200, 301, 302 or 410.",
            );
        }
    });
});

// ── 5. Sitemap statics — new pages present, every entry reachable ──

describe('GuestRouteHygiene — sitemap statics', function () {
    it('exposes the new static pages for both locales', function () {
        Cache::flush();

        $content = get('/sitemap-static.xml')->content();

        // cities index landed with T04; roadmap with S05; pricing with
        // S08/T01; faq with S08/T02; code-of-conduct with S08/T03. This list
        // grows with STATIC_PATHS as new statics land.
        foreach (['en', 'de'] as $locale) {
            expect($content)->toContain("/{$locale}/cities")
                ->toContain("/{$locale}/our-pledge/roadmap")
                ->toContain("/{$locale}/pricing")
                ->toContain("/{$locale}/faq")
                ->toContain("/{$locale}/safety/code-of-conduct");
        }
    });

    it('renders every static sitemap URL 200 for a guest', function () {
        Cache::flush();

        $content = get('/sitemap-static.xml')->content();

        preg_match_all('/<loc>(.*?)<\/loc>/', $content, $matches);
        $locs = $matches[1];

        // Derived from the controller's own path list — a smaller set
        // means STATIC_PATHS lost an entry; round-tripping a stale cache
        // would fail the 200s.
        expect($locs)->toHaveCount(count(SitemapController::STATIC_PATHS) * 2);

        $baseUrl = config('app.url');
        foreach ($locs as $loc) {
            $path = str_replace((string) $baseUrl, '', $loc);
            $status = get($path)->status();
            test()->assertSame(
                200,
                $status,
                "Sitemap static {$loc} must render 200 for guests, got {$status}.",
            );
        }
    });
});

// ── 6. No orphans — every public directory linked from the guest chrome ──

describe('GuestRouteHygiene — no orphan directories', function () {
    it('links every public directory from the guest nav/footer', function () {
        // Any page rendering the public layout carries the same nav +
        // footer chrome; /about is the cheapest neutral host page.
        $html = get('/en/about')->assertOk()->content();

        $directories = [
            'discover' => route('discover'),
            'gm.directory' => route('gm.directory'),
            'events.index' => route('events.index'),
            'game-systems' => route('game-systems'),
            'venues.directory' => route('venues.directory'),
            'city-hubs.index' => route('city-hubs.index', ['locale' => 'en']),
        ];

        foreach ($directories as $name => $url) {
            test()->assertStringContainsString(
                'href="'.$url.'"',
                $html,
                "Public directory [{$name}] must be linked from the guest nav/footer — found an orphaned directory.",
            );
        }
    });
});

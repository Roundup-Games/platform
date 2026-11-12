<?php

use App\Enums\GameStatus;
use App\Enums\Visibility;
use App\Http\Controllers\MarkdownAlternateController;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\SEO\LlmsTxtService;
use Illuminate\Support\Facades\Cache;
use Tests\Traits\CapturesLogRecords;

use function Pest\Laravel\get;

uses(CapturesLogRecords::class);

// ── LlmsTxtService (M078/S01/T01) ─────────────────────
//
// Covers the llms.txt v2 generator contract at the service level: body
// shape (H1 → blockquote → info → stats → H2 file-list sections with
// `[name](url): note` rows), live stats truthfulness against a seeded
// control set (distinct counts per metric pin the right query), the
// app.url-absolute URL inventory (data-driven from the INVENTORY const),
// and the cache set/hit logging contract.
//
// K004: assertions are structural (line prefixes, regex shapes, counts,
// config-derived URLs) — never literal prose. The route + link-resolution
// walk (GET every emitted URL) is T02's contract.

beforeEach(function () {
    Cache::flush();
    $this->service = app(LlmsTxtService::class);
});

// Helpers carry an llmsTxt prefix: same-shape helpers exist unprefixed in
// CitySitemapTest, and two same-named globals in one Pest process fatal
// ("cannot redeclare function") under --parallel — see Pest.php.

function llmsTxtLocation(string $city, float $lat, float $lng): Location
{
    return Location::factory()->create([
        'city' => $city,
        'country' => 'DEU',
        'latitude' => $lat,
        'longitude' => $lng,
    ]);
}

function llmsTxtUpcomingGame(?Location $location = null, array $overrides = []): Game
{
    return Game::factory()->create(array_merge([
        'location_id' => $location?->id,
        'date_time' => now()->addDays(5),
    ], $overrides));
}

describe('LlmsTxtService — v2 body shape', function () {
    it('renders the H1/blockquote/H2 skeleton with one [name](url): note row per inventory entry', function () {
        $body = $this->service->generate();
        $lines = explode("\n", $body);

        // v2 skeleton: H1, blank line, one-line blockquote — Markdown
        // requires the blank line between the H1 and the blockquote.
        expect($lines[0])->toStartWith('# ')
            ->and($lines[1])->toBe('')
            ->and($lines[2])->toStartWith('> ')
            ->and($lines[3])->toBe('');

        $headings = preg_grep('/^## /', $lines);
        expect($headings)->not->toBeEmpty();

        preg_match_all('/^- \[[^\]]+\]\([^)]+\): .+$/m', $body, $rows);
        // One row per INVENTORY entry, plus one .md alternate row per
        // PAGES entry (the v2 Optional appendix — M078/S04/T03), plus the
        // /agents.md self-onboarding row appended to the same appendix.
        expect(count($rows[0]))->toBe(count(LlmsTxtService::INVENTORY) + count(MarkdownAlternateController::PAGES) + 1);
    });
});

describe('LlmsTxtService — URL inventory', function () {
    it('renders every inventory entry as an absolute URL on the app.url host', function () {
        $body = $this->service->generate();
        $base = rtrim((string) config('app.url'), '/');

        foreach (LlmsTxtService::INVENTORY as $label => $path) {
            expect($body)->toContain(sprintf('[%s](%s%s):', $label, $base, $path));
        }

        preg_match_all('/\]\(([^)]+)\)/', $body, $urls);
        expect($urls[1])->not->toBeEmpty();

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        foreach ($urls[1] as $url) {
            expect(parse_url($url, PHP_URL_HOST))->toBe($host);
        }
    });

    it('keeps the redirecting bare root out of the inventory', function () {
        $paths = array_values(LlmsTxtService::INVENTORY);

        expect($paths)->not->toContain('/')
            ->and($paths)->not->toContain('');

        foreach ($paths as $path) {
            expect($path)->toStartWith('/en');
        }
    });
});

describe('LlmsTxtService — markdown alternates appendix', function () {
    it('lists exactly one app.url-absolute .md row per PAGES entry under the v2 Optional section', function () {
        $body = $this->service->generate();
        $base = rtrim((string) config('app.url'), '/');

        // The v2 convention's heading for supplementary files closes the
        // map — pinned structurally as its own H2 line.
        expect($body)->toContain("\n## Optional\n");

        // Single-sourced from the controller whitelist: every pinned
        // pillar gets its config-absolute .md row, note included.
        foreach (array_keys(MarkdownAlternateController::PAGES) as $page) {
            expect($body)->toContain(sprintf('](%s/en/%s.md):', $base, $page));
        }

        // The agent self-onboarding doc rides the same Optional section
        // (not a PAGES pillar: root path, EN-only, no HTML twin).
        expect($body)->toContain(sprintf('](%s%s):', $base, LlmsTxtService::AGENTS_DOC_PATH));

        // And nothing beyond the whitelist plus that row: the emitted
        // .md link set is exactly PAGES + /agents.md, in order — no
        // stray .md URL leaks into the map.
        preg_match_all('/\]\(([^)]+\.md)\)/', $body, $mdUrls);
        expect($mdUrls[1])->toHaveCount(count(MarkdownAlternateController::PAGES) + 1)
            ->toEqual([
                ...array_map(
                    fn (string $page): string => $base.'/en/'.$page.'.md',
                    array_keys(MarkdownAlternateController::PAGES),
                ),
                $base.LlmsTxtService::AGENTS_DOC_PATH,
            ]);
    });
});

describe('LlmsTxtService — live stats', function () {
    it('counts only upcoming public games, active public campaigns, canonical systems, and qualifying cities', function () {
        // One qualifying city cluster (threshold: 3 upcoming sessions) whose
        // games are also the 3 upcoming public games.
        $cologne = llmsTxtLocation('Koeln', 50.9375, 6.9603);
        llmsTxtUpcomingGame($cologne);
        llmsTxtUpcomingGame($cologne);
        llmsTxtUpcomingGame($cologne);

        // Controls that must NOT count.
        llmsTxtUpcomingGame($cologne, ['status' => GameStatus::Canceled->value]);
        llmsTxtUpcomingGame($cologne, ['date_time' => now()->subDays(2)]);
        Campaign::factory()->create(['visibility' => Visibility::Private->value]);

        Campaign::factory()->count(2)->create();
        GameSystem::factory()->count(4)->create();

        // Game/Campaign factories each attach a fresh GameSystem, so the
        // absolute row count is not seedable-deterministic here — mirror
        // the sitemap's canonical-row condition instead, and pin the
        // duplicate-rows-excluded leg with an explicit control.
        $expectedSystems = (int) GameSystem::query()
            ->whereNull('canonical_game_system_id')
            ->count();
        $canonicalSystem = GameSystem::query()->whereNull('canonical_game_system_id')->firstOrFail();
        GameSystem::factory()->create()->forceFill(
            ['canonical_game_system_id' => $canonicalSystem->id]
        )->save();

        // City resolutions cache per slug — drop anything provisioning
        // cached so stats() computes fresh over the seeded rows.
        Cache::flush();

        $stats = $this->service->stats();

        expect($stats['upcoming_public_games'])->toBe(3)
            ->and($stats['active_public_campaigns'])->toBe(2)
            ->and($stats['game_systems'])->toBe($expectedSystems)
            ->and($stats['active_cities'])->toBe(1)
            ->and($stats['as_of'])->toBe(now()->toDateString());
    });

    it('renders the stats numbers and the as-of date into the body', function () {
        $body = $this->service->generate();

        // The stats line is the one carrying today's date (Y-m-d).
        $statsLine = collect(explode("\n", $body))
            ->first(fn (string $line) => str_contains($line, now()->toDateString()));
        expect($statsLine)->not->toBeNull();

        // Distinct seeded counts (3/2/4/1 shape) pin each metric's number:
        // assert every integer token of stats() appears on that line.
        $stats = $this->service->stats();
        preg_match_all('/\d+/', (string) $statsLine, $tokens);

        foreach ([
            $stats['upcoming_public_games'],
            $stats['active_public_campaigns'],
            $stats['game_systems'],
            $stats['active_cities'],
        ] as $count) {
            expect($tokens[0])->toContain((string) $count);
        }
    });
});

describe('LlmsTxtService — caching', function () {
    it('sets the cache on first render and logs llms.cache_hit on the second', function () {
        Cache::flush();
        $this->captureLogRecords();

        $first = $this->service->render();
        $second = $this->service->render();

        expect($second)->toBe($first)
            ->and(Cache::get(LlmsTxtService::CACHE_KEY))->toBe($first);

        $set = $this->logRecord('llms.cache_set');
        expect($set)->not->toBeNull()
            ->and($set['context']['cache_key'])->toBe(LlmsTxtService::CACHE_KEY)
            ->and($set['context']['ttl'])->toBe(LlmsTxtService::CACHE_TTL);

        $hit = $this->logRecord('llms.cache_hit');
        expect($hit)->not->toBeNull()
            ->and($hit['context']['cache_key'])->toBe(LlmsTxtService::CACHE_KEY);

        // Exactly one set across both renders — the hit path must not
        // regenerate (the Cache::remember pitfall this service avoids).
        $sets = array_filter(
            $this->logRecords(),
            fn (array $record) => $record['message'] === 'llms.cache_set'
        );
        expect(count($sets))->toBe(1);
    });

    it('uses the documented 1h TTL backstop', function () {
        expect(LlmsTxtService::CACHE_TTL)->toBe(3600);
    });
});

// ── GET /llms.txt (M078/S01/T02) ─────────────────────
//
// Route-level contract: the exact /llms.txt path must be served by
// LlmsTxtController (200 + text/markdown + v2 shape) — never hijacked by
// the bare-path /{path} locale-redirect fallback, which would turn it
// into a 302 to /en/llms.txt. The link-resolution walk then holds the
// inventory honest: every emitted [name](url) must be internal to the
// app.url host AND resolve to a live 200 page with no seeded data —
// including every /en/{page}.md row of the Optional appendix (T03,
// additionally pinned explicitly below).

describe('GET /llms.txt — route', function () {
    it('serves 200 text/markdown with the v2 shape — never the locale fallback redirect', function () {
        $response = get('/llms.txt');

        // assertOk pins the fallback-hijack risk: the catch-all would
        // answer 302 -> /en/llms.txt instead.
        $response->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=utf-8');

        $lines = explode("\n", $response->content());
        expect($lines[0])->toStartWith('# ')
            ->and($lines[1])->toBe('')
            ->and($lines[2])->toStartWith('> ');

        preg_match_all('/^## /m', $response->content(), $headings);
        expect($headings[0])->not->toBeEmpty();
    });

    it('is never shadowed by a static public/llms.txt — the file must stay out of the tree', function () {
        // The webserver serves static public/ files before Laravel routing:
        // a static llms.txt would freeze the body and shadow this route —
        // the audit-era failure mode (dead links live in production while
        // CI stayed green, MEM1631). The route above proves the route
        // wins only while no static file exists; this pin keeps it that
        // way (roadmap SC1's `test ! -f` promise).
        expect(file_exists(public_path('llms.txt')))->toBeFalse();
    });

    it('emits only internal URLs on the app.url host, each resolving to 200', function () {
        $body = get('/llms.txt')->content();

        preg_match_all('/\]\(([^)]+)\)/', $body, $urls);
        expect($urls[1])->not->toBeEmpty();

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        foreach ($urls[1] as $url) {
            // Internal-only contract: same host as app.url — no external
            // or absolute-elsewhere links in the inventory.
            expect(parse_url($url, PHP_URL_HOST))->toBe($host);

            // Resolvability: every emitted path must GET 200 directly
            // (no seed data — the inventory is static/directory pages).
            $path = parse_url($url, PHP_URL_PATH);
            expect($path)->not->toBeNull();

            get($path)->assertOk();
        }
    });

    it('logs llms.cache_hit on a second GET within the same process', function () {
        Cache::flush();
        $this->captureLogRecords();

        get('/llms.txt')->assertOk();
        get('/llms.txt')->assertOk();

        $hit = $this->logRecord('llms.cache_hit');
        expect($hit)->not->toBeNull()
            ->and($hit['context']['cache_key'])->toBe(LlmsTxtService::CACHE_KEY);

        // Exactly one set across both GETs — the second request must be
        // served from cache, not regenerated.
        $sets = array_filter(
            $this->logRecords(),
            fn (array $record) => $record['message'] === 'llms.cache_set'
        );
        expect(count($sets))->toBe(1);
    });

    it('resolves every advertised .md alternate to 200', function () {
        $body = get('/llms.txt')->content();

        // The advertised .md set is exactly the PAGES whitelist plus the
        // /agents.md self-onboarding doc, and each URL must be a live
        // route — an advertised-but-dead .md row fails here.
        preg_match_all('/\]\(([^)]+\.md)\)/', $body, $mdUrls);
        expect($mdUrls[1])->not->toBeEmpty()
            ->toHaveCount(count(MarkdownAlternateController::PAGES) + 1);

        foreach ($mdUrls[1] as $url) {
            get((string) parse_url($url, PHP_URL_PATH))->assertOk();
        }
    });
});

// ── Fetch telemetry (M078/S05/T02) ─────────────────────
//
// First-party llms.txt fetch counts: every GET /llms.txt increments
// the current hour's cache bucket (add-first so the TTL sticks —
// increment alone applies no expiry on Redis, so the buckets would
// never expire and the 7-day sum would grow forever), and
// fetchCountsLast7Days() sums exactly the 168-hour window. The array
// cache store in tests makes add+increment deterministic.

describe('llms.txt fetch telemetry', function () {
    it('increments the hourly bucket once per GET and sums it via fetchCountsLast7Days', function () {
        get('/llms.txt')->assertOk();
        get('/llms.txt')->assertOk();
        get('/llms.txt')->assertOk();

        expect(Cache::get(LlmsTxtService::FETCH_COUNTER_PREFIX.now()->format('YmdH')))->toBe(3)
            ->and($this->service->fetchCountsLast7Days())->toBe(3);
    });

    it('sums exactly the 168-hour window — the oldest edge bucket in, the next one out', function () {
        // In-window buckets count (current hour, mid-window, oldest
        // edge at subHours(167)) …
        Cache::put(LlmsTxtService::FETCH_COUNTER_PREFIX.now()->format('YmdH'), 2, 3600);
        Cache::put(LlmsTxtService::FETCH_COUNTER_PREFIX.now()->subHours(5)->format('YmdH'), 5, 3600);
        Cache::put(LlmsTxtService::FETCH_COUNTER_PREFIX.now()->subHours(167)->format('YmdH'), 1, 3600);

        // … and buckets just outside the window never do — the TTL
        // headroom (8 days) also keeps real edge buckets readable, but
        // the sum must never reach past the 7-day boundary.
        Cache::put(LlmsTxtService::FETCH_COUNTER_PREFIX.now()->subHours(168)->format('YmdH'), 999, 3600);
        Cache::put(LlmsTxtService::FETCH_COUNTER_PREFIX.now()->subDays(30)->format('YmdH'), 999, 3600);

        expect($this->service->fetchCountsLast7Days())->toBe(8);
    });

    it('sums numeric-string buckets — the Redis round-trip shape', function () {
        // Production runs CACHE_STORE=redis: RedisStore::unserialize()
        // returns increment results as numeric STRINGS (is_numeric pass-
        // through, no int cast), while tests run the array store (ints).
        // A type-strict is_int() guard reads 0 in production forever —
        // pin that numeric-string buckets (the production shape) count.
        Cache::put(LlmsTxtService::FETCH_COUNTER_PREFIX.now()->format('YmdH'), '6', 3600);
        Cache::put(LlmsTxtService::FETCH_COUNTER_PREFIX.now()->subHours(3)->format('YmdH'), '4', 3600);

        expect($this->service->fetchCountsLast7Days())->toBe(10);
    });

    it('serves 200 and logs a warning when the counter cache backend fails', function () {
        $this->captureLogRecords();

        // Cache outage: the counter's add() throws, the render's get()
        // is stubbed to a cached body (so the broken put() path is
        // never reached), and increment() must not fire after the
        // failed add — the response degrades to an uncounted fetch,
        // never a 500.
        Cache::shouldReceive('add')->once()->andThrow(new RuntimeException('cache down'));
        Cache::shouldReceive('increment')->never();
        Cache::shouldReceive('get')->andReturn('# Roundup Games cached body');
        Cache::shouldReceive('put')->never();

        get('/llms.txt')->assertOk()->assertSee('Roundup Games cached body');

        $warning = $this->logRecord('llms.fetch_counter_failed');
        expect($warning)->not->toBeNull()
            ->and($warning['context']['cache_key'])->toBe(LlmsTxtService::FETCH_COUNTER_PREFIX.now()->format('YmdH'))
            ->and($warning['context']['error'])->toBe('cache down');
    });
});

<?php

namespace App\SEO;

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\Visibility;
use App\Http\Controllers\MarkdownAlternateController;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Services\CityDirectoryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates the /llms.txt v2 markdown map (llmstxt.org): an H1 site name,
 * a one-line blockquote summary, a short info paragraph, a live stats
 * block, then H2 file-list sections whose rows are
 * `[name](absolute-url): note`.
 *
 * The URL inventory is DATA (self::INVENTORY), not inline literals, so
 * future slices can append rows (e.g. per-entity .md exports) without
 * touching layout code. Every path must be locale-absolute and GET 200
 * with no seeded data — the bare root `/` is a 302 locale redirect and
 * MUST stay out of the inventory (the link-resolution walk in
 * LlmsTxtTest GETs every emitted URL).
 *
 * The map closes with a v2 "Optional" appendix advertising the
 * /en/{page}.md markdown alternates, single-sourced from
 * MarkdownAlternateController::PAGES — the same whitelist that gates
 * the routes and the HTML pages' rel=alternate links, so no path
 * literal is restated here.
 *
 * Stats are live counts using the same public-visibility semantics the
 * sitemap builders use, plus CityDirectoryService's qualifying-city guard
 * for city hubs. The whole body is cached (self::CACHE_KEY) for 1 h:
 * stats staleness up to that TTL is an accepted trade-off (no observer
 * invalidation, no warm command) — llms.txt is a discovery aid, not a
 * live dashboard.
 */
class LlmsTxtService
{
    /** Cache key for the rendered body (mirrors seo:sitemap:* naming). */
    public const CACHE_KEY = 'seo:llms:txt';

    /** @var int Cache TTL in seconds — the staleness backstop for stats. */
    public const CACHE_TTL = 3600;

    /**
     * Key prefix for the hourly llms.txt fetch counters (78-05-T02):
     * one bucket per clock hour, incremented on every serve.
     */
    public const FETCH_COUNTER_PREFIX = 'llms:fetches:';

    /** @var int Hourly buckets summed by fetchCountsLast7Days() — 7 days × 24 h. */
    public const FETCH_WINDOW_BUCKETS = 168;

    /**
     * @var int Counter bucket TTL in days: the 7-day window plus
     *          headroom, so an edge bucket has not expired when the sum reads
     *          it. The add-first pattern in recordFetch() carries this TTL —
     *          Cache::increment alone applies no expiry (a Redis INCR creates a
     *          persistent key), so without it the buckets never expire and the
     *          7-day sum accumulates stale counts forever.
     */
    public const FETCH_BUCKET_TTL_DAYS = 8;

    /**
     * Link inventory: label => locale-absolute path. Data, not layout —
     * sections (self::SECTIONS) reference labels, and rows render from
     * this map, so appending an entry is a data change only.
     *
     * Constraints (pinned by LlmsTxtTest):
     *  - locale-absolute paths only; the bare root `/` is a 302 redirect
     *    and must never appear here;
     *  - only routes that serve 200 with NO seeded data, so the
     *    link-resolution walk is deterministic;
     *  - paths are joined onto config('app.url') (request-independent,
     *    mirroring SitemapController::baseUrl()) — never url().
     *
     * @var array<string, string>
     */
    public const INVENTORY = [
        'Home' => '/en',
        'Discover' => '/en/discover',
        'Board games' => '/en/discover/board-games',
        'Adventures' => '/en/discover/adventures',
        'Game systems' => '/en/game-systems',
        'City hubs' => '/en/cities',
        'GM directory' => '/en/gms',
        'Badges' => '/en/badges',
        'About' => '/en/about',
        'How it works' => '/en/how-it-works',
        'For organizers' => '/en/for-organizers',
        'Pricing' => '/en/pricing',
        'FAQ' => '/en/faq',
        'Safety tools' => '/en/safety-tools',
        'Code of conduct' => '/en/safety/code-of-conduct',
        'Our pledge' => '/en/our-pledge',
        'Algorithms' => '/en/our-pledge/algorithms',
        'Roadmap' => '/en/our-pledge/roadmap',
        'Contact' => '/en/contact',
    ];

    /**
     * Section layout: H2 heading => ordered labels into self::INVENTORY.
     *
     * @var array<string, array<int, string>>
     */
    private const SECTIONS = [
        'Core' => [
            'Home',
            'Discover',
            'Board games',
            'Adventures',
            'Game systems',
            'City hubs',
            'GM directory',
            'Badges',
        ],
        'Community & transparency' => [
            'About',
            'How it works',
            'For organizers',
            'Pricing',
            'FAQ',
            'Safety tools',
            'Code of conduct',
            'Our pledge',
            'Algorithms',
            'Roadmap',
            'Contact',
        ],
    ];

    /**
     * One-line note per inventory label (the `: note` half of each row).
     * EN-only: llms.txt is a single English file by design.
     *
     * @var array<string, string>
     */
    private const NOTES = [
        'Home' => 'Landing page with current platform activity and entry points to discovery.',
        'Discover' => 'Search and filter upcoming public games and campaigns.',
        'Board games' => 'Discovery scoped to board games.',
        'Adventures' => 'Discovery scoped to tabletop RPG campaigns and one-shots.',
        'Game systems' => 'Browseable index of the game systems used on the platform.',
        'City hubs' => 'Local overviews of upcoming public games and venues per city.',
        'GM directory' => 'Directory of game masters and hosts offering public games.',
        'Badges' => 'Gallery of every badge players can earn, grouped by category and rarity.',
        'About' => 'Who runs Roundup Games and why.',
        'How it works' => 'How joining, applying, and hosting games works.',
        'For organizers' => 'Tools and workflows for hosting games and campaigns.',
        'Pricing' => 'What is free to use and what paid plans add.',
        'FAQ' => 'Frequently asked questions.',
        'Safety tools' => 'Built-in safety tools for game communication and moderation.',
        'Code of conduct' => 'Community rules, the moderation consequences ladder, and the report and appeal path.',
        'Our pledge' => 'The platform\'s commitments to its community.',
        'Algorithms' => 'How search and discovery ranking works, documented in the open.',
        'Roadmap' => 'Public roadmap of delivered and planned work.',
        'Contact' => 'How to reach the team.',
    ];

    /**
     * H2 heading for the closing markdown-alternates appendix — the
     * llmstxt.org v2 convention's heading for supplementary,
     * non-essential files.
     */
    private const OPTIONAL_HEADING = 'Optional';

    /** One-line blockquote under the H1 (v2 spec position). */
    private const SUMMARY_LINE = 'Free-to-use platform for discovering and hosting tabletop games, from board game sessions to long-running RPG campaigns.';

    /** Short info paragraph: what the platform is, free-to-use, Berlin-based. */
    private const INFO_PARAGRAPH = 'Roundup Games is a free-to-use platform where players find and host tabletop games: one-off board game sessions and ongoing RPG campaigns. Organizers publish games with open or application-based seats, and the platform provides safety tools, transparent discovery algorithms, and a public roadmap. It is run by a small, Berlin-based team.';

    public function __construct(
        private readonly CityDirectoryService $cityDirectory,
    ) {}

    /**
     * Cached read of the llms.txt body. Deliberately NOT Cache::remember:
     * the set/hit distinction is part of the contract — a miss generates,
     * stores, and logs llms.cache_set; a hit logs llms.cache_hit (the
     * structured channel mirrors SeoCacheService's seo.cache_* shape so
     * log tooling treats them alike).
     */
    public function render(): string
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_string($cached)) {
            Log::debug('llms.cache_hit', [
                'section' => 'llms_txt',
                'cache_key' => self::CACHE_KEY,
            ]);

            return $cached;
        }

        $body = $this->generate();

        Cache::put(self::CACHE_KEY, $body, self::CACHE_TTL);

        Log::debug('llms.cache_set', [
            'section' => 'llms_txt',
            'cache_key' => self::CACHE_KEY,
            'ttl' => self::CACHE_TTL,
        ]);

        return $body;
    }

    /**
     * Build the full v2 body. Uncached by design — callers wanting caching
     * go through render().
     */
    public function generate(): string
    {
        $stats = $this->stats();
        $baseUrl = $this->baseUrl();

        $lines = [
            '# '.$this->siteName(),
            '',
            '> '.self::SUMMARY_LINE,
            '',
            self::INFO_PARAGRAPH,
            '',
            sprintf(
                'Current activity: %d upcoming public games, %d active public campaigns, %d game systems, %d active city hubs. Data as of %s.',
                $stats['upcoming_public_games'],
                $stats['active_public_campaigns'],
                $stats['game_systems'],
                $stats['active_cities'],
                $stats['as_of'],
            ),
            '',
        ];

        foreach (self::SECTIONS as $heading => $labels) {
            $lines[] = "## {$heading}";
            $lines[] = '';

            foreach ($labels as $label) {
                $path = self::INVENTORY[$label];
                $lines[] = sprintf('- [%s](%s): %s', $label, $baseUrl.$path, self::NOTES[$label]);
            }

            $lines[] = '';
        }

        // v2 "Optional" appendix: one row per /en/{page}.md alternate.
        // The set is single-sourced from the controller's PAGES whitelist
        // — adding a pillar there adds its route, its rel=alternate link,
        // and this row together; the LlmsTxtTest link walk keeps every
        // emitted .md URL resolving to 200.
        $lines[] = '## '.self::OPTIONAL_HEADING;
        $lines[] = '';

        foreach (array_keys(MarkdownAlternateController::PAGES) as $page) {
            $label = $this->pillarLabel('/en/'.$page);

            $lines[] = sprintf(
                '- [%s](%s): %s',
                $label.' (markdown)',
                $baseUrl.'/en/'.$page.'.md',
                sprintf('Plain-markdown alternate of the %s page.', $label),
            );
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Live platform stats for the body's activity block. Public so tests
     * (and future consumers) can assert the counts directly against
     * seeded data without parsing prose.
     *
     * Visibility semantics mirror the sitemap builders
     * (SitemapController): public visibility and canonical game-system
     * rows. Status filters are claim-specific, not sitemap-mirrored:
     * "upcoming" games additionally require a future date_time, and
     * "active" campaigns mean status Active only (the sitemap keeps
     * completed campaigns indexable); city hubs go through
     * CityDirectoryService's qualifying guard so the count can never
     * exceed what /cities/{slug} actually serves.
     *
     * @return array{upcoming_public_games: int, active_public_campaigns: int, game_systems: int, active_cities: int, as_of: string}
     */
    public function stats(): array
    {
        return [
            'upcoming_public_games' => Game::query()
                ->where('visibility', Visibility::Public->value)
                ->where('status', '!=', GameStatus::Canceled->value)
                ->where('date_time', '>', now())
                ->count(),
            'active_public_campaigns' => Campaign::query()
                ->where('visibility', Visibility::Public->value)
                ->where('status', CampaignStatus::Active->value)
                ->count(),
            'game_systems' => GameSystem::query()
                ->whereNull('canonical_game_system_id')
                ->count(),
            'active_cities' => $this->cityDirectory->qualifyingCities()->count(),
            'as_of' => now()->toDateString(),
        ];
    }

    /**
     * Record one /llms.txt fetch into the current hour's counter bucket
     * (78-05-T02). Called from the serve path so the count is
     * first-party truth — never breaks the response: a cache outage
     * degrades to an uncounted fetch, not a 500.
     */
    public function recordFetch(): void
    {
        $key = self::fetchCounterKey(now());

        try {
            Cache::add($key, 0, now()->addDays(self::FETCH_BUCKET_TTL_DAYS));
            Cache::increment($key);
        } catch (Throwable $e) {
            Log::warning('llms.fetch_counter_failed', [
                'section' => 'llms_txt',
                'cache_key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sum the last 7 days of hourly fetch buckets (168 keys, one
     * Cache::many round-trip). Missing buckets read null and are
     * skipped; values are matched numerically, not by type — the Redis
     * store returns increment results as numeric STRINGS
     * (RedisStore::unserialize passes is_numeric values through
     * uncast), so an is_int() guard would silently skip every bucket
     * in production while the array store used by tests keeps the
     * suite green (same trap as ShortLinkController's miss counter).
     * A cache read failure logs and reports 0 rather than throwing —
     * the admin page must not 500 over telemetry.
     */
    public function fetchCountsLast7Days(): int
    {
        $keys = [];
        for ($hoursBack = 0; $hoursBack < self::FETCH_WINDOW_BUCKETS; $hoursBack++) {
            $keys[] = self::fetchCounterKey(now()->subHours($hoursBack));
        }

        try {
            $total = 0;

            foreach (Cache::many($keys) as $value) {
                if (is_numeric($value)) {
                    $total += (int) $value;
                }
            }

            return $total;
        } catch (Throwable $e) {
            Log::warning('llms.fetch_counts_read_failed', [
                'section' => 'llms_txt',
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Hourly bucket key — YmdH is collision-free across months and
     * sorts lexicographically in time order.
     */
    private static function fetchCounterKey(Carbon $hour): string
    {
        return self::FETCH_COUNTER_PREFIX.$hour->format('YmdH');
    }

    /**
     * Request-independent base URL — config-driven, exactly like
     * SitemapController::baseUrl(); never url(), whose host follows the
     * incoming request.
     */
    private function baseUrl(): string
    {
        $url = config('app.url');

        return is_string($url) && $url !== '' ? rtrim($url, '/') : 'https://roundup.games';
    }

    private function siteName(): string
    {
        $name = config('app.name');

        return is_string($name) && $name !== '' ? $name : 'Roundup Games';
    }

    /**
     * Display label for a pillar's EN HTML path, reverse-looked up from
     * self::INVENTORY so the .md rows reuse the exact labels of their
     * HTML twins instead of restating them.
     */
    private function pillarLabel(string $path): string
    {
        $label = array_search($path, self::INVENTORY, true);

        if (is_string($label)) {
            return $label;
        }

        // Defensive fallback: a PAGES entry without an INVENTORY twin
        // still renders a label rather than crashing the whole map.
        return ucwords(str_replace(['-', '/'], ' ', substr($path, 4)));
    }
}

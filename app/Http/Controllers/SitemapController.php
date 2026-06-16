<?php

namespace App\Http\Controllers;

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\Team;
use App\Models\User;
use App\Services\SeoCacheService;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    /**
     * Static page paths (relative to locale prefix) included in the sitemap.
     */
    private const STATIC_PATHS = [
        '/',
        '/about',
        '/how-it-works',
        '/for-organizers',
        '/contact',
        '/safety-tools',
        '/gms',
        '/game-systems',
        '/our-pledge',
        '/our-pledge/algorithms',
    ];

    /**
     * Event statuses considered published and visible to the public.
     */
    private const PUBLISHED_EVENT_STATUSES = [
        'published',
        'registration_open',
        'registration_closed',
        'in_progress',
    ];

    /**
     * Maximum entities per sitemap type. With 2 locales per entity, this keeps
     * each sub-sitemap under the sitemap protocol's 50,000 URL limit.
     */
    private const MAX_ENTITIES_PER_SITEMAP = 25000;

    /**
     * Priority defaults per sitemap type.
     */
    private const TYPE_PRIORITIES = [
        'static' => '0.8',
        'game-systems' => '0.7',
        'events' => '0.9',
        'games' => '0.8',
        'campaigns' => '0.8',
        'teams' => '0.6',
        'profiles' => '0.5',
        'venues' => '0.7',
    ];

    /**
     * Changefreq defaults per sitemap type.
     */
    private const TYPE_CHANGEFREQ = [
        'static' => 'monthly',
        'game-systems' => 'weekly',
        'events' => 'daily',
        'games' => 'daily',
        'campaigns' => 'weekly',
        'teams' => 'weekly',
        'profiles' => 'weekly',
        'venues' => 'weekly',
    ];

    public function __construct(
        private readonly SeoCacheService $seoCache,
    ) {}

    /**
     * Get the supported locales from config.
     *
     * @return string[]
     */
    /**
     * @return array<int, string>
     */
    private function locales(): array
    {
        $locales = config('app.available_locales', ['en']);

        return is_array($locales) ? array_map(fn (mixed $v) => is_string($v) ? $v : 'en', $locales) : ['en'];
    }

    /**
     * Sitemap index: lists all sub-sitemaps with lastmod.
     *
     * GET /sitemap.xml
     */
    public function index(): Response
    {
        $xml = $this->seoCache->getIndex();

        if ($xml === null) {
            $xml = $this->buildIndexXml();
            $this->seoCache->setIndex($xml);
        }

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }

    /**
     * Per-type sub-sitemap.
     *
     * GET /sitemap-{type}.xml
     */
    public function show(string $type): Response
    {
        if (! $this->seoCache->isValidType($type)) {
            abort(404);
        }

        $xml = $this->seoCache->getSitemap($type);

        if ($xml === null) {
            $xml = $this->buildSitemapXml($type);
            $this->seoCache->setSitemap($type, $xml);
        }

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }

    // ── Index builder ──────────────────────────────────

    private function buildIndexXml(): string
    {
        $baseUrl = $this->baseUrl();
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        foreach ($this->seoCache->getSitemapTypes() as $type) {
            $lastmod = $this->getLastModForType($type);
            $lines[] = '  <sitemap>';
            $lines[] = '    <loc>'.htmlspecialchars("{$baseUrl}/sitemap-{$type}.xml", ENT_XML1, 'UTF-8').'</loc>';
            $lines[] = '    <lastmod>'.htmlspecialchars($lastmod, ENT_XML1, 'UTF-8').'</lastmod>';
            $lines[] = '  </sitemap>';
        }

        $lines[] = '</sitemapindex>';

        return implode("\n", $lines);
    }

    /**
     * Get the most recent updated_at for a sitemap type (for index lastmod).
     */
    private function getLastModForType(string $type): string
    {
        $query = match ($type) {
            'static' => null,
            'game-systems' => GameSystem::orderByDesc('updated_at'),
            'events' => Event::public()
                ->whereIn('status', self::PUBLISHED_EVENT_STATUSES)
                ->orderByDesc('updated_at'),
            'games' => Game::where('visibility', Visibility::Public->value)
                ->where('status', '!=', GameStatus::Canceled->value)
                ->orderByDesc('updated_at'),
            'campaigns' => Campaign::where('visibility', Visibility::Public->value)
                ->where('status', '!=', CampaignStatus::Cancelled->value)
                ->orderByDesc('updated_at'),
            'teams' => Team::where('is_active', true)
                ->orderByDesc('updated_at'),
            'profiles' => User::where('profile_complete', true)
                ->whereNotNull('slug')
                ->where('is_disabled', false)
                ->whereNull('anonymized_at')
                ->orderByDesc('updated_at'),
            'venues' => Location::where('is_verified', true)
                ->whereIn('venue_type', self::COMMERCIAL_VENUE_TYPES)
                ->whereNotNull('slug')
                ->orderByDesc('updated_at'),
            default => null,
        };

        if ($query === null) {
            return now()->toDateString();
        }

        $latest = $query->value('updated_at');

        return $latest instanceof \DateTimeInterface ? $latest->format('Y-m-d') : now()->toDateString();
    }

    // ── Per-type sitemap builders ──────────────────────

    private function buildSitemapXml(string $type): string
    {
        $entries = match ($type) {
            'static' => $this->getStaticEntries(),
            'game-systems' => $this->getGameSystemEntries(),
            'events' => $this->getEventEntries(),
            'games' => $this->getGameEntries(),
            'campaigns' => $this->getCampaignEntries(),
            'teams' => $this->getTeamEntries(),
            'profiles' => $this->getProfileEntries(),
            'venues' => $this->getVenueEntries(),
            default => [],
        };

        return $this->renderUrlSet($entries);
    }

    // ── Static pages ───────────────────────────────────

    /**
     * @return array<int, array{loc: string, lastmod: string, changefreq: string, priority: string}>
     */
    private function getStaticEntries(): array
    {
        $baseUrl = $this->baseUrl();
        $entries = [];
        $now = now()->toDateString();

        foreach ($this->locales() as $locale) {
            foreach (self::STATIC_PATHS as $path) {
                $entries[] = [
                    'loc' => "{$baseUrl}/{$locale}{$path}",
                    'lastmod' => $now,
                    'changefreq' => self::TYPE_CHANGEFREQ['static'],
                    'priority' => self::TYPE_PRIORITIES['static'],
                ];
            }
        }

        return $entries;
    }

    // ── Game Systems ───────────────────────────────────

    /**
     * @return array<int, array{loc: string, lastmod: string, changefreq: string, priority: string}>
     */
    private function getGameSystemEntries(): array
    {
        $baseUrl = $this->baseUrl();
        $entries = [];

        $systems = GameSystem::select('slug', 'updated_at')
            ->orderByDesc('updated_at')
            ->limit(self::MAX_ENTITIES_PER_SITEMAP)
            ->get();

        foreach ($systems as $system) {
            foreach ($this->locales() as $locale) {
                $entries[] = [
                    'loc' => "{$baseUrl}/{$locale}/game-systems/{$system->slug}",
                    'lastmod' => $system->updated_at?->toDateString() ?? now()->toDateString(),
                    'changefreq' => self::TYPE_CHANGEFREQ['game-systems'],
                    'priority' => self::TYPE_PRIORITIES['game-systems'],
                ];
            }
        }

        return $entries;
    }

    // ── Events ─────────────────────────────────────────

    /**
     * @return array<int, array{loc: string, lastmod: string, changefreq: string, priority: string}>
     */
    private function getEventEntries(): array
    {
        $baseUrl = $this->baseUrl();
        $entries = [];

        $events = Event::public()
            ->whereIn('status', self::PUBLISHED_EVENT_STATUSES)
            ->select('slug', 'updated_at')
            ->orderByDesc('updated_at')
            ->limit(self::MAX_ENTITIES_PER_SITEMAP)
            ->get();

        foreach ($events as $event) {
            foreach ($this->locales() as $locale) {
                $entries[] = [
                    'loc' => "{$baseUrl}/{$locale}/events/{$event->slug}",
                    'lastmod' => $event->updated_at?->toDateString() ?? now()->toDateString(),
                    'changefreq' => self::TYPE_CHANGEFREQ['events'],
                    'priority' => self::TYPE_PRIORITIES['events'],
                ];
            }
        }

        return $entries;
    }

    // ── Games ──────────────────────────────────────────

    /**
     * @return array<int, array{loc: string, lastmod: string, changefreq: string, priority: string}>
     */
    private function getGameEntries(): array
    {
        $baseUrl = $this->baseUrl();
        $entries = [];

        $games = Game::where('visibility', Visibility::Public->value)
            ->where('status', '!=', GameStatus::Canceled->value)
            ->select('id', 'updated_at')
            ->orderByDesc('updated_at')
            ->limit(self::MAX_ENTITIES_PER_SITEMAP)
            ->get();

        foreach ($games as $game) {
            foreach ($this->locales() as $locale) {
                $entries[] = [
                    'loc' => "{$baseUrl}/{$locale}/games/{$game->id}",
                    'lastmod' => $game->updated_at?->toDateString() ?? now()->toDateString(),
                    'changefreq' => self::TYPE_CHANGEFREQ['games'],
                    'priority' => self::TYPE_PRIORITIES['games'],
                ];
            }
        }

        return $entries;
    }

    // ── Campaigns ──────────────────────────────────────

    /**
     * @return array<int, array{loc: string, lastmod: string, changefreq: string, priority: string}>
     */
    private function getCampaignEntries(): array
    {
        $baseUrl = $this->baseUrl();
        $entries = [];

        $campaigns = Campaign::where('visibility', Visibility::Public->value)
            ->where('status', '!=', CampaignStatus::Cancelled->value)
            ->select('id', 'updated_at')
            ->orderByDesc('updated_at')
            ->limit(self::MAX_ENTITIES_PER_SITEMAP)
            ->get();

        foreach ($campaigns as $campaign) {
            foreach ($this->locales() as $locale) {
                $entries[] = [
                    'loc' => "{$baseUrl}/{$locale}/campaigns/{$campaign->id}",
                    'lastmod' => $campaign->updated_at?->toDateString() ?? now()->toDateString(),
                    'changefreq' => self::TYPE_CHANGEFREQ['campaigns'],
                    'priority' => self::TYPE_PRIORITIES['campaigns'],
                ];
            }
        }

        return $entries;
    }

    // ── Teams ──────────────────────────────────────────

    /**
     * @return array<int, array{loc: string, lastmod: string, changefreq: string, priority: string}>
     */
    private function getTeamEntries(): array
    {
        $baseUrl = $this->baseUrl();
        $entries = [];

        $teams = Team::where('is_active', true)
            ->select('slug', 'updated_at')
            ->orderByDesc('updated_at')
            ->limit(self::MAX_ENTITIES_PER_SITEMAP)
            ->get();

        foreach ($teams as $team) {
            foreach ($this->locales() as $locale) {
                $entries[] = [
                    'loc' => "{$baseUrl}/{$locale}/teams/{$team->slug}",
                    'lastmod' => $team->updated_at?->toDateString() ?? now()->toDateString(),
                    'changefreq' => self::TYPE_CHANGEFREQ['teams'],
                    'priority' => self::TYPE_PRIORITIES['teams'],
                ];
            }
        }

        return $entries;
    }

    // ── Profiles ───────────────────────────────────────

    /**
     * @return array<int, array{loc: string, lastmod: string, changefreq: string, priority: string}>
     */
    private function getProfileEntries(): array
    {
        $baseUrl = $this->baseUrl();
        $entries = [];

        $users = User::where('profile_complete', true)
            ->whereNotNull('slug')
            ->where('is_disabled', false)
            ->whereNull('anonymized_at')
            ->select('slug', 'updated_at')
            ->orderByDesc('updated_at')
            ->limit(self::MAX_ENTITIES_PER_SITEMAP)
            ->get();

        foreach ($users as $user) {
            foreach ($this->locales() as $locale) {
                $entries[] = [
                    'loc' => "{$baseUrl}/{$locale}/u/{$user->slug}",
                    'lastmod' => $user->updated_at?->toDateString() ?? now()->toDateString(),
                    'changefreq' => self::TYPE_CHANGEFREQ['profiles'],
                    'priority' => self::TYPE_PRIORITIES['profiles'],
                ];
            }
        }

        return $entries;
    }

    // ── Venues ─────────────────────────────────────────

    /**
     * Venue types that count as public commercial venues when verified.
     *
     * MUST stay in sync with LocationDisclosureService::COMMERCIAL_VENUE_TYPES
     * (the public-venue-page eligibility authority). Sitemap entries are a
     * crawler-visible mirror of isPublicVenuePage(), so the two lists must
     * never drift — private/unverified/`other` locations are never indexable.
     */
    private const COMMERCIAL_VENUE_TYPES = [
        'cafe',
        'flgs',
        'library',
        'community_center',
        'convention',
        'bar',
    ];

    /**
     * @return array<int, array{loc: string, lastmod: string, changefreq: string, priority: string}>
     */
    private function getVenueEntries(): array
    {
        $baseUrl = $this->baseUrl();
        $entries = [];

        $venues = Location::where('is_verified', true)
            ->whereIn('venue_type', self::COMMERCIAL_VENUE_TYPES)
            ->whereNotNull('slug')
            ->select('slug', 'updated_at')
            ->orderByDesc('updated_at')
            ->limit(self::MAX_ENTITIES_PER_SITEMAP)
            ->get();

        foreach ($venues as $venue) {
            foreach ($this->locales() as $locale) {
                $entries[] = [
                    'loc' => "{$baseUrl}/{$locale}/venue/{$venue->slug}",
                    'lastmod' => $venue->updated_at?->toDateString() ?? now()->toDateString(),
                    'changefreq' => self::TYPE_CHANGEFREQ['venues'],
                    'priority' => self::TYPE_PRIORITIES['venues'],
                ];
            }
        }

        return $entries;
    }

    // ── XML rendering ──────────────────────────────────

    /**
     * Render an array of URL entries into a sitemap urlset XML document.
     *
     * @param  array<int, array{loc: string, lastmod: string, changefreq: string, priority: string}>  $entries
     */
    private function renderUrlSet(array $entries): string
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        foreach ($entries as $entry) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>'.htmlspecialchars($entry['loc'], ENT_XML1, 'UTF-8').'</loc>';
            $lines[] = '    <lastmod>'.htmlspecialchars($entry['lastmod'], ENT_XML1, 'UTF-8').'</lastmod>';
            $lines[] = '    <changefreq>'.htmlspecialchars($entry['changefreq'], ENT_XML1, 'UTF-8').'</changefreq>';
            $lines[] = '    <priority>'.htmlspecialchars($entry['priority'], ENT_XML1, 'UTF-8').'</priority>';
            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines);
    }

    private function baseUrl(): string
    {
        $url = config('app.url');

        return is_string($url) ? $url : 'https://roundup.games';
    }
}

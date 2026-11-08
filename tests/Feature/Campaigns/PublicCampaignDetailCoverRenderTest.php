<?php

use App\Models\Campaign;
use App\Models\GameSystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\get;

// M075/S06-T01 — Red→green render pin for the public campaign detail hero
// (R052): the hero <img> must come from $campaign->resolveCoverUrl() so it
// stays in parity with og:image (Campaign::getDynamicSEOData reads the same
// resolver) across all three fallback rungs, and the page must push a
// matching <link rel="preload"> (the game page's hero+og+preload triple).
// Guests only — PublicCampaignDetail redirects authed users to the dashboard
// detail, so every test fetches as an unauthenticated visitor.

beforeEach(function () {
    app()->setLocale('en');
});

/**
 * Extract the hero cover <img> src from the public campaign detail page.
 * Anchored on the hero marker class string so unrelated imgs never match.
 */
function extractHeroCoverSrc(TestResponse $response): ?string
{
    preg_match(
        '/<img\s+src="([^"]+)"[^>]*class="w-full h-full object-cover opacity-95 blur-xs scale-105"/',
        $response->content(),
        $matches,
    );

    return $matches[1] ?? null;
}

/**
 * Extract the og:image meta content, handling both attribute orders like
 * extractMetaDescription in tests/Pest.php.
 */
function extractOgImageContent(TestResponse $response): ?string
{
    $html = $response->content();

    preg_match('/<meta\s+property="og:image"\s+content="([^"]*)"/', $html, $matches)
        || preg_match('/<meta\s+content="([^"]*)"\s+property="og:image"/', $html, $matches);

    return $matches[1] ?? null;
}

/**
 * Extract the cover preload href pushed by the hero partial — the third leg
 * of the hero+og+preload parity triple (_game-header.blade.php pattern).
 */
function extractCoverPreloadHref(TestResponse $response): ?string
{
    preg_match(
        '/<link\s+rel="preload"\s+as="image"\s+href="([^"]+)"\s+fetchpriority="high">/',
        $response->content(),
        $matches,
    );

    return $matches[1] ?? null;
}

// ═══════════════════════════════════════════════════════════

describe('rung 1 — host-uploaded cover', function () {
    it('renders hero img, og:image, and preload from the same host cover URL', function () {
        Storage::fake('public');

        $campaign = Campaign::factory()->create(['visibility' => 'public']);
        $campaign->addMedia(UploadedFile::fake()->image('campaign-cover.png', 1200, 630))
            ->toMediaCollection('cover');

        // The factory default attaches a fresh GameSystem (thumbnail_url=null,
        // no cover media), so the only competing URL would be og-default.
        $expectedPath = parse_url($campaign->fresh()->resolveCoverUrl(), PHP_URL_PATH);

        $response = get(route('campaigns.detail', $campaign->id))->assertOk();

        $heroSrc = extractHeroCoverSrc($response);
        expect($heroSrc)->not->toBeNull('host-cover campaign rendered no hero <img> — hero/og parity fails (R052)');

        $ogContent = extractOgImageContent($response);
        expect($ogContent)->not->toBeNull('og:image meta missing from the public detail page');

        // Cross-context comparisons at path level only (APP_URL vs served URL).
        expect(parse_url($heroSrc, PHP_URL_PATH))->toBe($expectedPath)
            ->and(parse_url($ogContent, PHP_URL_PATH))->toBe($expectedPath);

        // Preload triple leg: same URL, fetchpriority=high.
        expect(parse_url(extractCoverPreloadHref($response), PHP_URL_PATH))->toBe($expectedPath);

        // Negatives: nothing falls through to the bundled default on rung 1.
        expect(parse_url($heroSrc, PHP_URL_PATH))->not->toContain('og-default')
            ->and(parse_url($ogContent, PHP_URL_PATH))->not->toContain('og-default');
    });
});

// ═══════════════════════════════════════════════════════════

describe('rung 2 — representative GameSystem cover', function () {
    it('renders hero img and og:image from the representative system cover', function () {
        $system = GameSystem::factory()->create(['thumbnail_url' => 'https://example.com/camp-rep.jpg']);
        $campaign = Campaign::factory()
            ->withCampaignGameSystems([(string) $system->id])
            ->create(['visibility' => 'public']);

        $response = get(route('campaigns.detail', $campaign->id))->assertOk();

        $heroSrc = extractHeroCoverSrc($response);
        expect($heroSrc)->not->toBeNull('representative-cover campaign rendered no hero <img>');

        // External URL — identical absolute string in both surfaces.
        expect($heroSrc)->toBe('https://example.com/camp-rep.jpg')
            ->and(extractOgImageContent($response))->toBe('https://example.com/camp-rep.jpg');
    });
});

// ═══════════════════════════════════════════════════════════

describe('rung 3 — bundled default asset', function () {
    it('always renders a hero img: default asset in hero, og:image, and preload', function () {
        $campaign = Campaign::factory()->create(['visibility' => 'public']);
        $campaign->gameSystems()->sync([]);

        $expectedPath = parse_url(asset('images/og-default.jpg'), PHP_URL_PATH);

        $response = get(route('campaigns.detail', $campaign->id))->assertOk();

        // Rung 3 always resolves — the hero marker must be present.
        $heroSrc = extractHeroCoverSrc($response);
        expect($heroSrc)->not->toBeNull('no-system campaign rendered no hero <img> — rung 3 missing (R052)');

        $ogContent = extractOgImageContent($response);
        expect($ogContent)->not->toBeNull('og:image meta missing from the public detail page');

        expect(parse_url($heroSrc, PHP_URL_PATH))->toBe($expectedPath)
            ->and(parse_url($ogContent, PHP_URL_PATH))->toBe($expectedPath)
            ->and(parse_url(extractCoverPreloadHref($response), PHP_URL_PATH))->toBe($expectedPath);
    });
});

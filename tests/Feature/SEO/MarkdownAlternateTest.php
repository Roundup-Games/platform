<?php

use App\Enums\SafetyTool;
use App\Http\Controllers\MarkdownAlternateController;
use Illuminate\Support\Facades\Lang;

use function Pest\Laravel\get;

// ── Markdown alternates for the EN pillar pages (M078/S04/T01) ──────
//
// Contract: the nine pinned pillars serve clean hand-authored markdown
// at /en/{page}.md — 200, text/markdown; charset=utf-8, an H1-first
// body with at least one H2 section and zero HTML chrome, and a
// canonical Link header pointing agents at the EN HTML page (on the
// app.url host). EN-only by construction: the dedicated literal 'en'
// prefix block keeps /de/…md unroutable, and unknown .md pages fall
// through to the bare-path catch-all's locale-prefix 404.
//
// K004: assertions are structural (status, headers, markdown shapes,
// PAGES inventory, config-derived canonicals) — never literal prose.

describe('MarkdownAlternateController::PAGES — inventory pin', function () {
    it('covers exactly the nine pinned EN pillars', function () {
        expect(array_keys(MarkdownAlternateController::PAGES))->toBe([
            'about',
            'how-it-works',
            'for-organizers',
            'pricing',
            'faq',
            'safety-tools',
            'our-pledge',
            'our-pledge/algorithms',
            'our-pledge/roadmap',
        ]);
    });
});

describe('GET /en/{page}.md — the nine mirrors', function () {
    it('serves 200 text/markdown with clean markdown body and a canonical Link header', function (string $page) {
        $response = get('/en/'.$page.'.md');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=utf-8');

        $body = $response->content();

        // Markdown shape: H1 on the very first line (never a deeper
        // heading) and at least one H2 section below it.
        $lines = explode("\n", $body);
        expect($lines[0])->toStartWith('# ')
            ->and($body)->toMatch('/^## /m');

        // Zero HTML chrome: these views must never leak layout
        // components, divs, analytics partials, or comments into the
        // markdown — no '<' followed by a tag-ish character at all —
        // AND no HTML entities: Blade's {{ }} entity-encodes translated
        // copy (&#039; &quot; &amp;), which corrupts a text/markdown
        // body; the views interpolate raw ({!! !!}) on purpose.
        expect($body)->not->toMatch('/<[a-zA-Z!\/]/')
            ->and($body)->not->toMatch('/&(?:amp|quot|lt|gt|#0?39);/');

        // The canonical Link header pins the alternate to the EN HTML
        // page on the app.url host (mirrors the llms.txt URL contract).
        $canonical = '<'.rtrim((string) config('app.url'), '/').'/en/'.$page.'>; rel="canonical"';
        expect($response->headers->get('Link'))->toBe($canonical);
    })->with(array_keys(MarkdownAlternateController::PAGES));
});

describe('GET /en/{page}.md — mirror coverage of enumerated sources', function () {
    // The mirrors single-source VALUES from lang keys and enums, but
    // their structure ($sections maps, $algorithms lists, tool loops) is
    // hand-maintained — the sync-duty comments in the views are the only
    // guard against silently dropping an entry the HTML page renders.
    // These pins make the enumerable mirrors coverage-enforced, same
    // philosophy as the llms.txt link walk. K004: assertions compare
    // __() output against the body — wording-agnostic, never literals.

    it('mirrors every FAQ question from lang/en/faq.php', function () {
        $body = get('/en/faq.md')->assertOk()->content();

        $questions = array_filter(
            Lang::get('faq', [], 'en'),
            fn (string $key): bool => str_starts_with($key, 'content_q_'),
            ARRAY_FILTER_USE_KEY
        );
        $questions = array_filter($questions, 'is_string');

        expect($questions)->not->toBeEmpty();

        foreach ($questions as $question) {
            expect($body)->toContain($question);
        }
    });

    it('mirrors every SafetyTool case from the enum', function () {
        // The .md is rendered under locale 'en' (controller pins it);
        // pin the label lookups the same way so the comparison cannot
        // cross locales.
        app()->setLocale('en');
        $body = get('/en/safety-tools.md')->assertOk()->content();

        foreach (SafetyTool::cases() as $tool) {
            expect($body)->toContain($tool->label());
        }
    });

    it('mirrors the full algorithm directory from lang/en/pages.php', function () {
        $body = get('/en/our-pledge/algorithms.md')->assertOk()->content();

        $headings = array_filter(
            Lang::get('pages', [], 'en'),
            fn (string $key): bool => str_starts_with($key, 'heading_pledge_algo_'),
            ARRAY_FILTER_USE_KEY
        );
        $headings = array_filter($headings, 'is_string');

        expect($headings)->not->toBeEmpty();

        foreach ($headings as $heading) {
            expect($body)->toContain($heading);
        }
    });
});

describe('GET /en/{page}.md — negatives', function () {
    it('keeps the DE locale out by construction: /de/faq.md 404s', function () {
        get('/de/faq.md')->assertNotFound();
    });

    it('404s unknown .md pages under /en/', function () {
        get('/en/nope.md')->assertNotFound();
    });

    it('never exposes .md variants of the sitemap static set beyond the pinned nine', function () {
        // A .md route for any non-pinned pillar (here: the contact page)
        // must 404 — the where() whitelist is derived from PAGES alone.
        get('/en/contact.md')->assertNotFound();
    });
});

describe('EN pillar HTML — markdown alternate advertisement (T02)', function () {
    it('emits exactly one text/markdown alternate inside <head> on each EN pillar page', function (string $page) {
        $html = get('/en/'.$page)->assertOk()->content();

        $tag = '<link rel="alternate" type="text/markdown" href="'
            .rtrim((string) config('app.url'), '/').'/en/'.$page.'.md">';

        // Exactly one tag per page, pointing at the page's .md mirror on
        // the config app.url host (same contract as the .md route's
        // canonical Link header), and flushed inside <head> (before
        // </head>) via the head-links stack.
        expect(substr_count($html, 'rel="alternate" type="text/markdown"'))
            ->toBe(1)
            ->and($html)->toContain($tag)
            ->and(strpos($html, $tag))->toBeLessThan(strpos($html, '</head>'));
    })->with(array_keys(MarkdownAlternateController::PAGES));

    it('emits no markdown alternate on the DE faq page', function () {
        // The partial renders nothing for non-EN locales: the .md
        // mirrors are EN-only by construction, so a DE page advertising
        // one would be a 404-hunting link.
        get('/de/faq')->assertOk();

        expect(get('/de/faq')->content())->not->toContain('text/markdown');
    });

    it('emits no markdown alternate on the home page (not a pillar)', function () {
        get('/en')->assertOk();

        expect(get('/en')->content())->not->toContain('text/markdown');
    });
});

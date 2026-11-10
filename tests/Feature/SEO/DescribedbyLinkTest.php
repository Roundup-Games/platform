<?php

use App\Enums\Visibility;
use App\Models\Game;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\get;

// ── rel=describedby link tag (M078/S02/T01) ──────────
//
// Every page rendering the public layout (the only layout emitting
// {!! seo() !!}) must advertise /llms.txt via <link rel="describedby">
// (llmstxt.org v2 agent discoverability). The href is absolute on the
// app.url host — config-derived, never request-derived — so the expected
// value is computed the same way here (LlmsTxtTest convention). The tag
// is static layout markup, not per-page SEOData: the test pins exactly
// one tag per page, inside <head>.

/**
 * Assert the response carries exactly one <link rel="describedby"> tag
 * pointing at the absolute /llms.txt URL, and that it sits inside <head>.
 */
function assertDescribedbyLinkTagInHead(TestResponse $response): void
{
    $content = $response->content();
    $tag = '<link rel="describedby" href="'.rtrim((string) config('app.url'), '/').'/llms.txt">';

    // Presence with the exact absolute href on the app.url host.
    test()->assertStringContainsString($tag, $content,
        'Expected <link rel="describedby" href="…/llms.txt"> on the page.');

    // Exactly one tag per page — a duplicate (e.g. a per-page SEOData copy)
    // must never appear anywhere in the document.
    expect(substr_count($content, 'rel="describedby"'))->toBe(1);

    // The tag must live inside <head>, not leak into the body.
    $headStart = strpos($content, '<head>');
    $headEnd = strpos($content, '</head>');
    $tagPos = strpos($content, $tag);

    expect($headStart)->not->toBeFalse()
        ->and($headEnd)->not->toBeFalse()
        ->and($tagPos)->not->toBeFalse()
        ->toBeGreaterThan($headStart)
        ->toBeLessThan($headEnd);
}

it('renders one describedby link to /llms.txt in the head of public pages', function (string $path) {
    $response = get($path);

    $response->assertOk();

    assertDescribedbyLinkTagInHead($response);
})->with([
    'en home' => ['/en'],
    'de home' => ['/de'],
    'en discover' => ['/en/discover'],
    'en faq' => ['/en/faq'],
]);

it('renders one describedby link on a game detail page', function () {
    $game = Game::factory()->create([
        'name' => ['en' => 'Describedby Game'],
        'visibility' => Visibility::Public,
    ]);

    $response = get(route('games.detail', $game->id));

    $response->assertOk();

    assertDescribedbyLinkTagInHead($response);
});

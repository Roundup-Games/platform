<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Markdown alternates for the EN pillar pages (M078/S04).
 *
 * Serves clean hand-authored markdown mirrors of the nine pinned pillar
 * pages at /en/{page}.md — the per-page "optional" alternates the
 * llms.txt v2 map advertises. The routes live in a dedicated literal
 * 'en' prefix block outside the {locale} group (so /de/....md is
 * unroutable by construction) and are stateless: no locale middleware
 * runs, the copy is rendered in EN, and the canonical Link header
 * points agents back at the HTML page.
 */
class MarkdownAlternateController extends Controller
{
    /**
     * Static EN path => markdown view name. Single source of truth for
     * the /en/{page}.md route whitelist, the canonical Link targets,
     * and the llms.txt .md rows (imported by LlmsTxtService).
     *
     * @var array<string, string>
     */
    public const PAGES = [
        'about' => 'markdown.about',
        'how-it-works' => 'markdown.how-it-works',
        'for-organizers' => 'markdown.for-organizers',
        'pricing' => 'markdown.pricing',
        'faq' => 'markdown.faq',
        'safety-tools' => 'markdown.safety-tools',
        'our-pledge' => 'markdown.our-pledge',
        'our-pledge/algorithms' => 'markdown.our-pledge-algorithms',
        'our-pledge/roadmap' => 'markdown.our-pledge-roadmap',
    ];

    public function __invoke(string $page): Response
    {
        abort_unless(isset(self::PAGES[$page]), 404);

        // The routes are EN-only by construction, but no set.locale
        // middleware runs here — pin the render locale explicitly so the
        // lang-sourced copy is always English, regardless of session
        // state or APP_LOCALE defaults.
        app()->setLocale('en');

        // app.url is the canonical host basis (same contract as the
        // llms.txt inventory) — guard the config value's type for the
        // static-analysis level-9 gate.
        $appUrl = config('app.url');
        $canonical = rtrim(is_string($appUrl) ? $appUrl : '', '/').'/en/'.$page;

        return response()
            ->view(self::PAGES[$page])
            ->withHeaders([
                'Content-Type' => 'text/markdown; charset=utf-8',
                'Link' => '<'.$canonical.'>; rel="canonical"',
            ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\SEO\LlmsTxtService;
use Illuminate\Http\Response;

/**
 * GET /llms.txt — the llmstxt.org v2 markdown map for AI agents.
 *
 * Served by the router on purpose: a static public/llms.txt would be
 * served by the webserver before Laravel sees the request, freezing the
 * hand-written body (and its dead links) forever. The generated body
 * carries live platform stats and is cached by LlmsTxtService with a
 * 1 h TTL backstop — see that class for the staleness trade-off.
 *
 * Every serve also increments LlmsTxtService's hourly fetch counter
 * (78-05-T02) — the first-party "llms.txt fetches (last 7 days)"
 * number on the Search Performance admin page.
 */
class LlmsTxtController extends Controller
{
    public function __construct(
        private readonly LlmsTxtService $llmsTxt,
    ) {}

    /**
     * Serve the cached v2 markdown body as text/markdown, counting
     * the fetch into the hourly telemetry bucket first (the counter
     * is defensive — it must never break the response).
     */
    public function __invoke(): Response
    {
        $this->llmsTxt->recordFetch();

        return response($this->llmsTxt->render(), 200)
            ->header('Content-Type', 'text/markdown; charset=utf-8');
    }
}

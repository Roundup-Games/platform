<?php

/*
|--------------------------------------------------------------------------
| Content review dates (editorial stewardship)
|--------------------------------------------------------------------------
|
| Single source of truth for the "Last reviewed" temporal signal rendered
| by the content-review-note component on evergreen pillar pages (and
| their markdown mirrors). Backs the E-E-A-T freshness signal AI engines
| look for — honestly: a date here is a claim that a human reviewed the
| page's content on that date.
|
| Rules of maintenance:
|  - Update the date in the same commit that reviews or meaningfully
|    reworks the page's content — never bump it mechanically (D179
|    honesty canon: no fabricated freshness).
|  - A page absent from this map simply renders no review note.
|  - Dates are review dates, not publication dates: they may legitimately
|    stay unchanged for long stretches when the content is stable.
|
| Keys mirror the MarkdownAlternateController::PAGES whitelist paths, so
| pillar pages and their .md mirrors read the same value.
|
*/

return [
    'pages' => [
        // Reviewed 2026-11-10: all nine pillars re-derived from live
        // sources during the M078 markdown-mirror work and the GEO
        // follow-up review of the same day.
        'about' => '2026-11-10',
        'how-it-works' => '2026-11-10',
        'for-organizers' => '2026-11-10',
        'pricing' => '2026-11-10',
        'faq' => '2026-11-10',
        'safety-tools' => '2026-11-10',
        'our-pledge' => '2026-11-10',
        'our-pledge/algorithms' => '2026-11-10',
        'our-pledge/roadmap' => '2026-11-10',
    ],
];

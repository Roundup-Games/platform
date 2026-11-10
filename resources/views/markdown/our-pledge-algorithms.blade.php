{{-- Markdown alternate of /en/our-pledge/algorithms (M078/S04). Source:
     the directory of algorithm headings on
     resources/views/pages/pledge-algorithms.blade.php (lang/en|de/pages.php)
     plus its intro copy. The full formula documentation — actual math,
     weights, thresholds, and source-code links per algorithm — is long,
     detail-rich, and evolving, so it stays on the HTML page; this mirror
     carries the evergreen framing and directory. Sync duty: when an
     algorithm section is added, removed, or renamed on that page, mirror
     the heading in the $algorithms list below.
     NOTE: every interpolation is raw ({!! !!}) on purpose — these views
     serve text/markdown, and Blade's {{ }} would entity-encode
     apostrophes/ampersands into the body (&#039; etc.). Pinned by
     MarkdownAlternateTest's HTML-chrome/entity ban.
 --}}
@php
    // Same algorithm directory order as the HTML page's table of contents.
    $algorithms = [
        'pages.heading_pledge_algo_reliability',
        'pages.heading_pledge_algo_attendance',
        'pages.heading_pledge_algo_gm',
        'pages.heading_pledge_algo_people',
        'pages.heading_pledge_algo_session',
        'pages.heading_pledge_algo_proximity',
        'pages.heading_pledge_algo_disclosure',
        'pages.heading_pledge_algo_trending',
        'pages.heading_pledge_algo_platform',
    ];
@endphp
# {!! __('pages.content_commitment_algorithms_title') !!}

{!! __('pages.content_pledge_algo_hero_subtitle') !!}

{!! __('pages.content_pledge_algo_intro_1') !!}

{!! __('pages.content_pledge_algo_intro_2') !!}

{!! __('pages.content_pledge_algo_intro_3') !!}

## {!! __('pages.content_pledge_algo_toc_heading') !!}

@foreach ($algorithms as $headingKey)
- {!! __($headingKey) !!}
@endforeach

{!! __('pages.content_pledge_algo_cta_heading') !!}: [{!! __('pages.content_pledge_open_github') !!}](https://github.com/roundup-games/platform)

----
@include('markdown.partials.reviewed-note', ['page' => 'our-pledge/algorithms'])

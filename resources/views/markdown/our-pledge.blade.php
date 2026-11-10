{{-- Markdown alternate of /en/our-pledge (M078/S04). Source: the EN copy
     in lang/en|de/pages.php rendered by resources/views/pages/pledge.blade.php
     — editing those keys updates both pages. Sync duty: when the pledge
     page gains, drops, or reorders a section, mirror the change here.
     Live commitments cards stay on the HTML page; this is evergreen copy.
     NOTE: every interpolation is raw ({!! !!}) on purpose — these views
     serve text/markdown, and Blade's {{ }} would entity-encode
     apostrophes/ampersands into the body (&#039; etc.). Pinned by
     MarkdownAlternateTest's HTML-chrome/entity ban.
 --}}
# {!! __('pages.content_pledge_hero_heading') !!}

{!! __('pages.content_pledge_hero_subtitle') !!}

## {!! __('pages.content_pledge_nonprofit_heading') !!}

{!! __('pages.content_pledge_nonprofit_body', ['brand' => config('company.display_name')]) !!}

- {!! __('pages.content_pledge_nonprofit_detail_1') !!}
- {!! __('pages.content_pledge_nonprofit_detail_2') !!}
- {!! __('pages.content_pledge_nonprofit_detail_3') !!}

## {!! __('pages.content_pledge_open_heading') !!}

{!! __('pages.content_pledge_open_body') !!}

- [{!! __('pages.content_pledge_open_github') !!}](https://github.com/roundup-games/platform)

## {!! __('pages.content_pledge_investment_heading') !!}

{!! __('pages.content_pledge_investment_body') !!}

- {!! __('pages.content_pledge_investment_events') !!} — {!! __('pages.content_pledge_investment_events_body') !!}
- {!! __('pages.content_pledge_investment_education') !!} — {!! __('pages.content_pledge_investment_education_body') !!}
- {!! __('pages.content_pledge_investment_trust') !!} — {!! __('pages.content_pledge_investment_trust_body') !!}
- {!! __('pages.content_pledge_investment_promotion') !!} — {!! __('pages.content_pledge_investment_promotion_body') !!}

## {!! __('pages.content_pledge_cards_heading') !!}

{!! __('pages.content_commitments_subtitle') !!}

- [{!! __('pages.content_commitment_algorithms_title') !!}]({!! route('pledge.algorithms', 'en', false) !!}) — {!! __('pages.content_commitment_algorithms_body') !!}
- [{!! __('pages.content_commitment_operations_title') !!}]({!! route('safety.code-of-conduct', 'en', false) !!}) — {!! __('pages.content_commitment_operations_body') !!}
- [{!! __('pages.content_commitment_roadmap_title') !!}]({!! route('pledge.roadmap', 'en', false) !!}) — {!! __('pages.content_commitment_roadmap_body') !!}

----
@include('markdown.partials.reviewed-note', ['page' => 'our-pledge'])

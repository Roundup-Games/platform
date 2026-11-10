{{-- Markdown alternate of /en/about (M078/S04). Source: the EN copy in
     lang/en|de/pages.php rendered by resources/views/pages/about.blade.php
     — editing those keys updates both pages. Sync duty: when the about
     page gains, drops, or reorders a section, mirror the change here.
     CTAs and visual chrome stay on the HTML page; this is evergreen copy.
     NOTE: every interpolation is raw ({!! !!}) on purpose — these views
     serve text/markdown, and Blade's {{ }} would entity-encode
     apostrophes/ampersands into the body (&#039; etc.). Pinned by
     MarkdownAlternateTest's HTML-chrome/entity ban.
 --}}
# {!! __('pages.content_about_heading_vision') !!}

{!! __('pages.content_about_intro', ['brand' => config('company.display_name')]) !!}

## {!! __('pages.content_about_why_local_heading') !!}

{!! __('pages.content_about_why_local_p1') !!}

{!! __('pages.content_about_why_local_p2') !!}

{!! __('pages.content_about_why_local_p3', ['brand' => config('company.display_name')]) !!}

## {!! __('pages.content_about_what_drives_us') !!}

### {!! __('pages.content_about_value_community_first_title') !!}

{!! __('pages.content_about_value_community_first_body') !!}

### {!! __('pages.content_about_value_safe_by_design_title') !!}

{!! __('pages.content_about_value_safe_by_design_body') !!}

- [{!! __('safety.content_safety_tools') !!}]({!! route('safety-tools', 'en', false) !!})
- [{!! __('pages.content_commitment_algorithms_action') !!}]({!! route('pledge.algorithms', 'en', false) !!})

### {!! __('pages.content_pledge_open_heading') !!}

{!! __('pages.content_about_value_open_by_default_body') !!}

- [{!! __('pages.content_pledge_open_github') !!}](https://github.com/roundup-games/platform)

## {!! __('pages.content_about_commitments_heading') !!}

{!! __('pages.content_commitments_subtitle') !!}

- [{!! __('pages.content_commitment_algorithms_title') !!}]({!! route('pledge.algorithms', 'en', false) !!}) — {!! __('pages.content_commitment_algorithms_body') !!}
- [{!! __('pages.content_commitment_roadmap_title') !!}]({!! route('pledge.roadmap', 'en', false) !!}) — {!! __('pages.content_commitment_roadmap_body') !!}

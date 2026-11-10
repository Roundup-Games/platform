{{-- Markdown alternate of /en/our-pledge/roadmap (M078/S04). Source: the
     evergreen framing and future-ideas copy in lang/en|de/roadmap.php
     rendered by resources/views/pages/road-ahead.blade.php. The
     "Delivered recently" list is live, volatile state (refreshed at
     every milestone close) and intentionally NOT mirrored here — the
     HTML page is canonical for it. Sync duty: when the future-ideas
     framing changes, mirror the change here; never inline the
     delivered list.
     NOTE: every interpolation is raw ({!! !!}) on purpose — these views
     serve text/markdown, and Blade's {{ }} would entity-encode
     apostrophes/ampersands into the body (&#039; etc.). Pinned by
     MarkdownAlternateTest's HTML-chrome/entity ban.
 --}}
# {!! __('pages.content_commitment_roadmap_title') !!}

{!! __('roadmap.content_road_ahead_hero_subtitle') !!}

{!! __('roadmap.content_road_ahead_intro_1') !!}

{!! __('roadmap.content_road_ahead_intro_2') !!}

[{!! __('roadmap.action_road_ahead_view_repository') !!}](https://github.com/roundup-games/platform)

## {!! __('roadmap.heading_future_ideas') !!}

{!! __('roadmap.content_future_ideas_intro') !!}

### {!! __('roadmap.heading_future_idea_community_library') !!}

{!! __('roadmap.content_future_idea_community_library_body') !!}

### {!! __('roadmap.heading_future_idea_certification') !!}

{!! __('roadmap.content_future_idea_certification_body') !!}

### {!! __('roadmap.heading_future_idea_community_hqs') !!}

{!! __('roadmap.content_future_idea_community_hqs_body') !!}

### {!! __('roadmap.heading_future_idea_other') !!}

{!! __('roadmap.content_future_idea_other_body') !!}

{!! __('roadmap.content_future_ideas_closing') !!} [{!! __('roadmap.action_future_ideas_tell_us') !!}]({!! route('contact', 'en', false) !!})

## {!! __('roadmap.heading_delivered_recently') !!}

{!! __('roadmap.content_delivered_intro') !!}

[{!! __('pages.content_commitment_roadmap_action') !!}]({!! route('pledge.roadmap', 'en', false) !!})

----
@include('markdown.partials.reviewed-note', ['page' => 'our-pledge/roadmap'])

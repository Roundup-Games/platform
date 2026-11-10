{{-- Markdown alternate of /en/faq (M078/S04). Source of truth: the
     lang/en|de/faq.php Q/A keys — this mirror loops the exact same
     sections, questions, and answers as resources/views/pages/faq.blade.php,
     so editing faq.php updates both pages and the mirror can never
     render stale answers. Sync duty: when a section or Q/A is added,
     removed, or reordered on the FAQ page, mirror the change in the
     $sections map below.
     NOTE: every interpolation is raw ({!! !!}) on purpose — these views
     serve text/markdown, and Blade's {{ }} would entity-encode
     apostrophes/ampersands into the body (&#039; etc.). Pinned by
     MarkdownAlternateTest's HTML-chrome/entity ban.
 --}}
@php
    // Section heading key => list of [question key, answer key, optional follow-up markdown link].
    $sections = [
        'faq.heading_section_basics' => [
            ['faq.content_q_system_vs_game', 'faq.content_a_system_vs_game', null],
            ['faq.content_q_event_vs_game', 'faq.content_a_event_vs_game', null],
        ],
        'faq.heading_section_playing' => [
            ['faq.content_q_bench_vs_waitlist', 'faq.content_a_bench_vs_waitlist', null],
            ['faq.content_q_reliability', 'faq.content_a_reliability',
                '['.__('faq.action_view_algorithms').']('.route('pledge.algorithms', 'en', false).')'],
            ['faq.content_q_free_to_use', 'faq.content_a_free_to_use',
                '['.__('faq.action_view_pricing').']('.route('pricing', 'en', false).')'],
        ],
        'faq.heading_section_hosting' => [
            ['faq.content_q_host_first', 'faq.content_a_host_first',
                '['.__('faq.action_view_for_organizers').']('.route('for-organizers', 'en', false).')'],
            ['faq.content_q_create_event', 'faq.content_a_create_event', null],
        ],
        'faq.heading_section_trust' => [
            ['faq.content_q_venue_verified', 'faq.content_a_venue_verified', null],
            ['faq.content_q_data_rights', 'faq.content_a_data_rights',
                '['.__('faq.action_view_privacy').']('.route('privacy', 'en', false).')'],
        ],
    ];
@endphp
# {!! __('faq.heading_faq') !!}

{!! __('faq.content_faq_hero_subtitle', ['brand' => config('company.display_name')]) !!}

@foreach ($sections as $headingKey => $questions)
## {!! __($headingKey) !!}

@foreach ($questions as [$questionKey, $answerKey, $followUp])
### {!! __($questionKey) !!}

{!! __($answerKey) !!}
@if ($followUp)
{!! $followUp !!}
@endif

@endforeach
@endforeach
{!! __('faq.content_faq_still_stuck') !!} [{!! __('faq.action_contact_us') !!}]({!! route('contact', 'en', false) !!})

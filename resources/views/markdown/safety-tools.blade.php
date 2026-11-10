{{-- Markdown alternate of /en/safety-tools (M078/S04). Source: the
     SafetyTool/SafetyToolCategory enums (app/Enums/) plus the safety,
     common, pages, campaigns, and games copy rendered by
     resources/views/pages/safety-tools.blade.php. Editing the enums or
     those lang keys updates both pages. Sync duty: when a safety tool is
     added to the enum or the page's sections change, mirror the change
     here.
     NOTE: every interpolation is raw ({!! !!}) on purpose — these views
     serve text/markdown, and Blade's {{ }} would entity-encode
     apostrophes/ampersands into the body (&#039; etc.). Pinned by
     MarkdownAlternateTest's HTML-chrome/entity ban.
 --}}
@php
    $tools = \App\Enums\SafetyTool::cases();
    $categories = \App\Enums\SafetyToolCategory::cases();

    // Same per-category intro lines as the HTML page.
    $categoryIntros = [
        'before' => __('common.action_set_expectations_before_the_dice_start_rolling'),
        'during' => __('common.content_tools_you_can_use_right_at_the_table'),
        'after' => __('campaigns.content_reflect_and_improve_after_each_session'),
    ];

    // Attribution lines the HTML page shows under specific tools.
    $toolCredits = [
        \App\Enums\SafetyTool::XCard->value => __('common.content_created_by_john_stavropoulos_learn_more_at')
            .' [tinyurl.com/x-card-rpg](https://tinyurl.com/x-card-rpg)',
        \App\Enums\SafetyTool::ScriptChange->value => __('common.content_created_by_beau_j_gr_sheldon'),
    ];
@endphp
# {!! __('safety.field_safety_tools_for_tabletop_gaming') !!}

{!! __('safety.content_everyone_deserves_to_feel_safe') !!}

{!! __('safety.content_safety_tools_matter_at_every_table') !!}

{!! __('safety.content_whether_you_re_a_seasoned') !!}

{!! __('safety.content_coc_safety_tools_crosslink') !!} [{!! __('safety.action_view_code_of_conduct') !!}]({!! route('safety.code-of-conduct', 'en', false) !!})

@foreach ($categories as $category)
## {!! $category->label() !!}

{!! $categoryIntros[$category->value] !!}

@foreach ($tools as $tool)
@if ($tool->category() === $category)
### {!! $tool->label() !!}

{!! $tool->fullDescription() !!}
@if (isset($toolCredits[$tool->value]))
{!! $toolCredits[$tool->value] !!}
@endif

@endif
@endforeach
@endforeach
## {!! __('common.field_getting_started') !!}

- {!! __('safety.field_start_with_charter') !!} — {!! __('safety.content_the_single_most_impactful_thing') !!}
- {!! __('safety.content_have_an_x_card_on_the_table') !!} — {!! __('pages.content_even_if_you_never_use') !!}
- {!! __('common.content_check_in_regularly') !!} — {!! __('games.content_use_stars_wishes_or_a') !!}

## {!! __('safety.content_using_safety_tools_on_roundup') !!}

{!! __('safety.content_when_you_create_a_game') !!}

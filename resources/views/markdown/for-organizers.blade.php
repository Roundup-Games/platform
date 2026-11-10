{{-- Markdown alternate of /en/for-organizers (M078/S04). Source: the EN
     copy rendered by resources/views/pages/for-organizers.blade.php (keys
     live in lang/en|de/{pages,games,campaigns,events,profile,common}.php)
     — editing those keys updates both pages. Sync duty: when the
     for-organizers page gains, drops, or reorders a differentiator or
     step, mirror the change here.
     NOTE: every interpolation is raw ({!! !!}) on purpose — these views
     serve text/markdown, and Blade's {{ }} would entity-encode
     apostrophes/ampersands into the body (&#039; etc.). Pinned by
     MarkdownAlternateTest's HTML-chrome/entity ban.
 --}}
# {!! __('games.content_bring_your_games_to_the_table') !!}

{!! __('games.content_you_ve_got_the_game') !!} {!! __('pages.field_start_hosting_it_s_free') !!}

## {!! __('campaigns.content_everything_you_need_to_run_great_sessions') !!}

{!! __('campaigns.content_no_more_group_chats_spreadsheets') !!}

- {!! __('pages.content_org_diff_discord_title') !!} — {!! __('pages.content_org_diff_discord_text') !!}
- {!! __('pages.content_org_diff_charter_title') !!} — {!! __('pages.content_org_diff_charter_text') !!}
- {!! __('pages.content_org_diff_workspace_title') !!} — {!! __('pages.content_org_diff_workspace_text') !!}
- {!! __('pages.content_org_diff_reliability_title') !!} — {!! __('pages.content_org_diff_reliability_text') !!}
- {!! __('pages.content_org_diff_fees_title') !!} — {!! __('pages.content_org_diff_fees_text') !!}
- {!! __('pages.content_org_diff_waitlist_title') !!} — {!! __('pages.content_org_diff_waitlist_text') !!}

{!! __('pages.content_org_pricing_note') !!} [{!! __('pages.action_see_pricing') !!}]({!! route('pricing', 'en', false) !!})

## {!! __('games.content_from_idea_to_game_night_in_three_steps') !!}

1. {!! __('campaigns.action_create_a_session') !!} — {!! __('games.content_pick_your_game_set_the') !!}
2. {!! __('profile.action_set_your_preferences') !!} — {!! __('games.content_choose_your_visibility_level_add') !!}
3. {!! __('common.content_players_find_you') !!} — {!! __('campaigns.content_your_session_appears_in_discovery') !!}

{!! __('events.content_from_weekly_board_game_nights') !!}

[{!! __('pages.content_host_story_heading') !!}]({!! route('how-it-works', 'en', false) !!}) · [{!! __('pages.action_see_pricing') !!}]({!! route('pricing', 'en', false) !!})

----
@include('markdown.partials.reviewed-note', ['page' => 'for-organizers'])

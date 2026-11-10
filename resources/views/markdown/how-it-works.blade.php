{{-- Markdown alternate of /en/how-it-works (M078/S04). Source: the EN
     copy rendered by resources/views/pages/how-it-works.blade.php (keys
     live in lang/en|de/{pages,games,campaigns,events,common,auth,safety,profile,charter}.php)
     — editing those keys updates both pages. Sync duty: when the
     how-it-works page gains, drops, or reorders a step or section,
     mirror the change here.
     NOTE: every interpolation is raw ({!! !!}) on purpose — these views
     serve text/markdown, and Blade's {{ }} would entity-encode
     apostrophes/ampersands into the body (&#039; etc.). Pinned by
     MarkdownAlternateTest's HTML-chrome/entity ban.
 --}}
# {!! __('events.content_how_roundup_works') !!}

{!! __('pages.content_we_believe_everyone_deserves_a') !!}

## {!! __('games.content_three_steps_to_your_next_game_night') !!}

1. {!! __('campaigns.action_discover_sessions_near_you') !!} — {!! __('campaigns.content_share_your_location_and_browse') !!}
2. {!! __('games.action_find_your_kind_of_game') !!} — {!! __('games.content_love_epic_rpg_campaigns_quick') !!}
3. {!! __('common.content_show_up_and_play') !!} — {!! __('auth.content_sign_up_for_a_session') !!}

## {!! __('pages.content_host_story_heading') !!}

{!! __('pages.content_host_story_intro') !!}

1. {!! __('pages.content_host_story_step_plan_title') !!} — {!! __('pages.content_host_story_step_plan_text') !!}
2. {!! __('pages.content_host_story_step_roster_title') !!} — {!! __('pages.content_host_story_step_roster_text') !!}
3. {!! __('pages.content_host_story_step_overflow_title') !!} — {!! __('pages.content_host_story_step_overflow_text') !!}
4. {!! __('pages.content_host_story_step_recap_title') !!} — {!! __('pages.content_host_story_step_recap_text') !!}

[{!! __('pages.action_see_everything_for_organizers') !!}]({!! route('for-organizers', 'en', false) !!})

## {!! __('safety.content_your_safety_built_in') !!}

{!! __('pages.content_we_take_trust_seriously_here') !!}

### {!! __('campaigns.content_transparent_sessions') !!}

{!! __('campaigns.content_every_public_session_is_visible') !!}

### {!! __('profile.content_organizer_profiles') !!}

{!! __('pages.content_organizer_profiles_are_public_you') !!}

### {!! __('campaigns.content_protected_sessions') !!}

{!! __('campaigns.content_protected_sessions_require_membership_approval') !!}

### {!! __('charter.title_charter') !!}

{!! __('pages.content_how_it_works_charter_text') !!}

{!! __('pages.content_safety_bridge_trust') !!} {!! __('pages.content_safety_bridge_nonprofit') !!}

[{!! __('pages.action_learn_about_our_pledge') !!}]({!! route('pledge', 'en', false) !!})

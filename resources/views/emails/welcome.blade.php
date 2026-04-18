@component('mail::message')
# {{ __('events.content_welcome_to_roundup_games') }} 🎲

{{ __('common.field_hey_name', ['name' => $user->name]) }}

{{ __('events.content_thanks_for_joining_roundup_games') }}

## {{ __('common.field_get_started') }}

{{ __("common.content_here_s_what_you_can_do_next") }}

- {{ __('profile.content_complete_your_profile_add_your') }}
- {{ __('teams.content_find_or_create_a_team') }}
- {{ __('events.content_browse_events_discover_upcoming_tournaments') }}
- {{ __('games.content_start_a_campaign_organize_a') }}

@component('mail::button', ['url' => config('app.url') . '/' . app()->getLocale() . '/dashboard'])
{{ __('profile.action_go_to_your_dashboard') }}
@endcomponent

{{ __("emails.content_if_you_have_any_questions") }}

{{ __('common.content_happy_gaming') }} 🎯
Roundup Games
@endcomponent

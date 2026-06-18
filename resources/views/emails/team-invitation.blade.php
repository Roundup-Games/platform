@component('mail::message')
# {{ __("teams.content_you_re_invited_to_join_a_team") }} 🎲

{{ __('common.content_hey_there') }}

{{ __('emails.content_inviter_has_invited_you_to', ['inviter' => $inviter->name, 'team' => $team->name, 'brand' => config('company.display_name')]) }}

{{-- M053/S1/T06: routed through <x-location-display> (the sole address-rendering
     authority). Email context has no session; raw-city path needs no viewer. --}}
@if($team->city || $team->country)
**{{ __('common.content_based_in') }}** <x-location-display :city="$team->city" :country="$team->country" without-icon />
@endif

@component('mail::button', ['url' => $acceptUrl])
{{ __('common.action_accept_invitation') }}
@endcomponent

{{ __('emails.content_this_invitation_was_sent_to', ['email' => $inviteeEmail]) }}

{{ __('common.content_happy_gaming') }} 🎯
{{ config('company.display_name') }}
@endcomponent

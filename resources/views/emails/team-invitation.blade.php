@component('mail::message')
# {{ __("You're Invited to Join a Team!") }} 🎲

{{ __('Hey there,') }}

{{ __('**:inviter** has invited you to join the team **:team** on Roundup Games.', ['inviter' => $inviter->name, 'team' => $team->name]) }}

@if($team->city || $team->country)
**{{ __('Based in:') }}** {{ collect([$team->city, $team->country])->filter()->join(', ') }}
@endif

@component('mail::button', ['url' => $acceptUrl])
{{ __('Accept Invitation') }}
@endcomponent

{{ __('This invitation was sent to **:email**. If you weren\'t expecting this, you can safely ignore this email.', ['email' => $inviteeEmail]) }}

{{ __('Happy gaming!') }} 🎯
Roundup Games
@endcomponent

@component('mail::message')
# You're Invited to Join a Team! 🎲

Hey there,

**{{ $inviter->name }}** has invited you to join the team **{{ $team->name }}** on Roundup Games.

@if($team->city || $team->country)
**Based in:** {{ collect([$team->city, $team->country])->filter()->join(', ') }}
@endif

@component('mail::button', ['url' => $acceptUrl])
Accept Invitation
@endcomponent

This invitation was sent to **{{ $inviteeEmail }}**. If you weren't expecting this, you can safely ignore this email.

Happy gaming! 🎯
Roundup Games
@endcomponent

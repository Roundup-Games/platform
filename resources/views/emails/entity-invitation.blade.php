@component('mail::message')
# {{ __($entityType === 'game' ? 'emails.content_you_re_invited_to_a_game' : 'emails.content_you_re_invited_to_a_campaign') }} 🎲

{{ $entityType === 'game'
    ? __('emails.content_inviter_has_invited_you_to_join_game', ['inviter' => $inviterName, 'entity' => $entityName, 'brand' => config('company.display_name')])
    : __('emails.content_inviter_has_invited_you_to_join_campaign', ['inviter' => $inviterName, 'entity' => $entityName, 'brand' => config('company.display_name')]) }}

**{{ __('common.field_name') }}:** {{ $entityName }}
@if($entityDateTime)
**{{ __('common.field_date_time') }}:** {{ $entityDateTime }}
@endif
@if($entityLocation)
**{{ __('common.field_location') }}:** {{ $entityLocation }}
@endif

@component('mail::button', ['url' => $signupUrl])
{{ __('emails.action_create_account_to_join') }}
@endcomponent

{{ __('emails.content_invitation_sent_to_email', ['email' => $inviteeEmail]) }}

@if($optoutUrl)
---

<em style="color: #6b7280; font-size: 0.875rem;">{{ __('emails.content_dont_want_invitations') }}
<a href="{{ $optoutUrl }}">{{ __('emails.action_optout_invite_emails') }}</a></em>
@endif

{{ __('common.content_happy_gaming') }} 🎯
{{ config('company.display_name') }}
@endcomponent

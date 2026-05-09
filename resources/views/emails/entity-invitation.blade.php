@component('mail::message')
# {{ __($entityType === 'game' ? 'emails.content_you_re_invited_to_a_game' : 'emails.content_you_re_invited_to_a_campaign') }} 🎲

{{ __('emails.content_inviter_has_invited_you_to_join_' . $entityType, ['inviter' => $inviterName, 'entity' => $entityName]) }}

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

{{ __('common.content_happy_gaming') }} 🎯
Roundup Games
@endcomponent

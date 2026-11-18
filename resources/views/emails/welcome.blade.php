@component('mail::message', ['preheader' => $preheader])
{{-- Preheader: hidden inbox-preview line. Mailables do NOT render through
    the published notifications wrapper (that slot only executes for
    notification MailMessages), so the preheader is passed directly to
    mail::message — whose published override renders it as the first row
    of the header slot, BEFORE the brand header (M081 sixth-pass review:
    a body-slot div let clients concatenate the brand name ahead of the
    crafted preview copy). The text part strips the markup at the mail
    text layout, so no HTML leaks there. --}}

# {{ __('events.content_welcome_to_roundup_games', ['brand' => config('company.display_name')]) }}

{{ __('common.field_hey_name', ['name' => $user->name]) }}

{{ __('notifications.content_welcome_intro', ['brand' => config('company.display_name')]) }}

@component('mail::button', ['url' => $ctaUrl])
{{ __('notifications.action_complete_your_profile') }}
@endcomponent

{{ __('notifications.content_welcome_outro') }}
@endcomponent

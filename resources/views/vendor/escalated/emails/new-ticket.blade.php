@component('mail::message')
{{-- Roundup override of escalated::emails/new-ticket (M081/S03/T02,
    localized M081 follow-up).
    The markdown pipeline renders this through the S01 roundup theme
    (branded header/footer, amber primary buttons), so the override only
    supplies the copy and drops the vendor-bespoke chunks: the settings
    logo <img>, the hex accent color (button-<hex> matches no theme rule —
    the button rendered unstyled), and the settings footer <small>.
    Copy is keyed (lang/*/escalation_mails.php) and renders in the
    requester's preferred language — app Users carry preferred_language,
    external Contacts fall back to EN. --}}
@php($mailLocale = $ticket->requester?->preferred_language?->value ?? 'en')
# {{ __('escalation_mails.heading_new_ticket', [], $mailLocale) }}

{{ __('escalation_mails.body_new_ticket_intro', ['brand' => config('app.name')], $mailLocale) }}

**{{ __('escalation_mails.field_reference', [], $mailLocale) }}:** {{ $ticket->reference }}
**{{ __('escalation_mails.field_subject', [], $mailLocale) }}:** {{ $ticket->subject }}
**{{ __('escalation_mails.field_priority', [], $mailLocale) }}:** {{ __('escalated::enums.priority.'.$ticket->priority->value, [], $mailLocale) }}

{{ $ticket->description }}

@component('mail::button', ['url' => $url])
{{ __('escalation_mails.action_view_ticket', [], $mailLocale) }}
@endcomponent

{{ __('escalation_mails.body_new_ticket_follow', [], $mailLocale) }}

{{ __('escalation_mails.content_signoff', [], $mailLocale) }},<br>
{{ config('app.name') }} Support
@endcomponent

@component('mail::message')
{{-- Roundup override of escalated::emails/resolved (M081/S03/T02,
    localized M081 follow-up).
    Goes to the ticket requester. Same brand treatment as new-ticket: S01
    theme handles chrome, copy lives here, vendor logo / hex accent /
    settings footer dropped. Copy is keyed and renders in the
    requester's preferred language. --}}
@php($mailLocale = $ticket->requester?->preferred_language?->value ?? 'en')
# {{ __('escalation_mails.heading_ticket_resolved', [], $mailLocale) }}

{{ __('escalation_mails.body_ticket_resolved_intro', [], $mailLocale) }}

**{{ __('escalation_mails.field_reference', [], $mailLocale) }}:** {{ $ticket->reference }}
**{{ __('escalation_mails.field_subject', [], $mailLocale) }}:** {{ $ticket->subject }}

{{ __('escalation_mails.body_ticket_resolved_follow', [], $mailLocale) }}

@component('mail::button', ['url' => $url])
{{ __('escalation_mails.action_view_ticket', [], $mailLocale) }}
@endcomponent

{{ __('escalation_mails.content_signoff', [], $mailLocale) }},<br>
{{ config('app.name') }} Support
@endcomponent
